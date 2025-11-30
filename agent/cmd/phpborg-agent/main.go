package main

import (
	"context"
	"flag"
	"fmt"
	"log"
	"os"
	"os/signal"
	"sync"
	"syscall"
	"time"

	"github.com/phpborg/phpborg-agent/internal/api"
	"github.com/phpborg/phpborg-agent/internal/cert"
	"github.com/phpborg/phpborg-agent/internal/config"
	"github.com/phpborg/phpborg-agent/internal/executor"
	"github.com/phpborg/phpborg-agent/internal/task"
)

const Version = "2.3.5"

func main() {
	// Parse command line flags
	configPath := flag.String("config", config.GetDefaultConfigPath(), "Path to configuration file")
	showVersion := flag.Bool("version", false, "Show version and exit")
	installService := flag.Bool("install", false, "Install as system service")
	uninstallService := flag.Bool("uninstall", false, "Uninstall system service")
	flag.Parse()

	if *showVersion {
		fmt.Printf("phpborg-agent version %s\n", Version)
		os.Exit(0)
	}

	// Handle service installation/uninstallation
	if *installService {
		if err := installAsService(); err != nil {
			log.Fatalf("Failed to install service: %v", err)
		}
		fmt.Println("Service installed successfully")
		os.Exit(0)
	}

	if *uninstallService {
		if err := uninstallServiceCmd(); err != nil {
			log.Fatalf("Failed to uninstall service: %v", err)
		}
		fmt.Println("Service uninstalled successfully")
		os.Exit(0)
	}

	// Load configuration
	cfg, err := config.LoadFromFile(*configPath)
	if err != nil {
		log.Fatalf("Failed to load configuration: %v", err)
	}

	// Set agent version in config
	cfg.Agent.Version = Version

	// Setup logging
	if cfg.Logging.File != "" {
		f, err := os.OpenFile(cfg.Logging.File, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			log.Fatalf("Failed to open log file: %v", err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	log.Println("============================================================")
	log.Printf("  phpBorg Agent v%s", Version)
	log.Println("============================================================")
	log.Printf("[AGENT] Agent: %s (%s)", cfg.Agent.Name, cfg.Agent.UUID)
	log.Printf("[AGENT] Server URL: %s", cfg.Server.URL)

	// Create API client
	client, err := api.NewClient(cfg)
	if err != nil {
		log.Fatalf("[AGENT] Failed to create API client: %v", err)
	}
	log.Printf("[AGENT] API client created, connecting to server...")

	// Create executor
	exec := executor.NewExecutor(cfg)

	// Create task handler
	handler := task.NewHandler(cfg, client, exec)

	// Create certificate renewer for auto-renewal
	certRenewer := cert.NewRenewer(cfg, client)

	// Create agent
	agent := &Agent{
		config:      cfg,
		client:      client,
		exec:        exec,
		handler:     handler,
		certRenewer: certRenewer,
	}

	// Setup signal handling
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		sig := <-sigChan
		log.Printf("[AGENT] Received signal %v, shutting down...", sig)
		cancel()
	}()

	// Start certificate renewal background process
	certRenewer.Start(ctx)
	defer certRenewer.Stop()

	// Run agent
	if err := agent.Run(ctx); err != nil {
		log.Fatalf("[AGENT] Agent error: %v", err)
	}

	log.Println("[AGENT] Agent stopped")
}

// Agent is the main agent structure
type Agent struct {
	config      *config.Config
	client      *api.Client
	exec        *executor.Executor
	handler     *task.Handler
	certRenewer *cert.Renewer
}

// Run starts the agent main loop
func (a *Agent) Run(ctx context.Context) error {
	log.Println("[AGENT] Agent started, polling for tasks...")
	log.Printf("[AGENT] Poll interval: %v, Heartbeat interval: %v", a.config.Polling.Interval, a.config.Polling.HeartbeatInterval)

	// Send initial heartbeat
	if err := a.sendHeartbeat(ctx); err != nil {
		log.Printf("[AGENT] Initial heartbeat failed: %v (server may be unreachable)", err)
	} else {
		log.Println("[AGENT] Successfully connected to server!")
	}

	// Start task workers
	taskChan := make(chan api.Task, 10)
	var wg sync.WaitGroup

	for i := 0; i < a.config.Agent.MaxConcurrentTasks; i++ {
		wg.Add(1)
		go func(workerID int) {
			defer wg.Done()
			a.taskWorker(ctx, workerID, taskChan)
		}(i)
	}

	// Start heartbeat ticker
	heartbeatTicker := time.NewTicker(a.config.Polling.HeartbeatInterval)
	defer heartbeatTicker.Stop()

	// Start task polling ticker
	pollTicker := time.NewTicker(a.config.Polling.Interval)
	defer pollTicker.Stop()

	for {
		select {
		case <-ctx.Done():
			log.Println("[AGENT] Shutting down agent...")
			close(taskChan)
			wg.Wait()
			return nil

		case <-heartbeatTicker.C:
			if err := a.sendHeartbeat(ctx); err != nil {
				log.Printf("[HEARTBEAT] Failed: %v", err)
			}

		case <-pollTicker.C:
			a.pollTasks(ctx, taskChan)
		}
	}
}

// sendHeartbeat sends a heartbeat to the server
func (a *Agent) sendHeartbeat(ctx context.Context) error {
	caps := a.exec.DetectCapabilities(ctx)
	osInfo := a.exec.GetOSInfo(ctx)

	_, err := a.client.SendHeartbeat(ctx, Version, caps, osInfo)
	if err != nil {
		return err
	}

	log.Printf("[HEARTBEAT] Sent successfully (OS: %s)", osInfo)
	return nil
}

// pollTasks fetches pending tasks from the server
func (a *Agent) pollTasks(ctx context.Context, taskChan chan<- api.Task) {
	resp, err := a.client.GetTasks(ctx)
	if err != nil {
		log.Printf("[POLL] Failed to poll tasks: %v", err)
		return
	}

	if resp.Count > 0 {
		log.Printf("[POLL] Received %d task(s)", resp.Count)
		for _, t := range resp.Tasks {
			log.Printf("[TASK] Queuing task #%d (type: %s)", t.ID, t.Type)
			select {
			case taskChan <- t:
			case <-ctx.Done():
				return
			default:
				log.Printf("[TASK] Queue full, skipping task #%d", t.ID)
			}
		}
	}
}

// taskWorker processes tasks from the task channel
func (a *Agent) taskWorker(ctx context.Context, workerID int, taskChan <-chan api.Task) {
	log.Printf("[WORKER-%d] Started", workerID)

	for {
		select {
		case <-ctx.Done():
			log.Printf("[WORKER-%d] Stopping", workerID)
			return
		case t, ok := <-taskChan:
			if !ok {
				log.Printf("[WORKER-%d] Task channel closed", workerID)
				return
			}
			log.Printf("[WORKER-%d] Processing task #%d (type: %s)", workerID, t.ID, t.Type)
			startTime := time.Now()
			if err := a.handler.ProcessTask(ctx, t); err != nil {
				log.Printf("[WORKER-%d] Task #%d FAILED after %v: %v", workerID, t.ID, time.Since(startTime), err)
			} else {
				log.Printf("[WORKER-%d] Task #%d COMPLETED in %v", workerID, t.ID, time.Since(startTime))
			}
		}
	}
}

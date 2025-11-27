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
	"github.com/phpborg/phpborg-agent/internal/config"
	"github.com/phpborg/phpborg-agent/internal/executor"
	"github.com/phpborg/phpborg-agent/internal/task"
)

const Version = "1.0.0"

func main() {
	// Parse command line flags
	configPath := flag.String("config", "/etc/phpborg-agent/config.yaml", "Path to configuration file")
	showVersion := flag.Bool("version", false, "Show version and exit")
	flag.Parse()

	if *showVersion {
		fmt.Printf("phpborg-agent version %s\n", Version)
		os.Exit(0)
	}

	// Load configuration
	cfg, err := config.LoadFromFile(*configPath)
	if err != nil {
		log.Fatalf("Failed to load configuration: %v", err)
	}

	// Setup logging
	if cfg.Logging.File != "" {
		f, err := os.OpenFile(cfg.Logging.File, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0644)
		if err != nil {
			log.Fatalf("Failed to open log file: %v", err)
		}
		defer f.Close()
		log.SetOutput(f)
	}

	log.Printf("phpborg-agent version %s starting...", Version)
	log.Printf("Agent: %s (%s)", cfg.Agent.Name, cfg.Agent.UUID)

	// Create API client
	client, err := api.NewClient(cfg)
	if err != nil {
		log.Fatalf("Failed to create API client: %v", err)
	}

	// Create executor
	exec := executor.NewExecutor(cfg)

	// Create task handler
	handler := task.NewHandler(cfg, client, exec)

	// Create agent
	agent := &Agent{
		config:  cfg,
		client:  client,
		exec:    exec,
		handler: handler,
	}

	// Setup signal handling
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	go func() {
		sig := <-sigChan
		log.Printf("Received signal %v, shutting down...", sig)
		cancel()
	}()

	// Run agent
	if err := agent.Run(ctx); err != nil {
		log.Fatalf("Agent error: %v", err)
	}

	log.Println("Agent stopped")
}

// Agent is the main agent structure
type Agent struct {
	config  *config.Config
	client  *api.Client
	exec    *executor.Executor
	handler *task.Handler
}

// Run starts the agent main loop
func (a *Agent) Run(ctx context.Context) error {
	log.Println("Agent started, polling for tasks...")

	// Send initial heartbeat
	if err := a.sendHeartbeat(ctx); err != nil {
		log.Printf("Initial heartbeat failed: %v", err)
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
			log.Println("Shutting down agent...")
			close(taskChan)
			wg.Wait()
			return nil

		case <-heartbeatTicker.C:
			if err := a.sendHeartbeat(ctx); err != nil {
				log.Printf("Heartbeat failed: %v", err)
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

	log.Println("Heartbeat sent successfully")
	return nil
}

// pollTasks fetches pending tasks from the server
func (a *Agent) pollTasks(ctx context.Context, taskChan chan<- api.Task) {
	resp, err := a.client.GetTasks(ctx)
	if err != nil {
		log.Printf("Failed to poll tasks: %v", err)
		return
	}

	if resp.Count > 0 {
		log.Printf("Received %d task(s)", resp.Count)
		for _, t := range resp.Tasks {
			select {
			case taskChan <- t:
			case <-ctx.Done():
				return
			default:
				log.Printf("Task queue full, skipping task %d", t.ID)
			}
		}
	}
}

// taskWorker processes tasks from the task channel
func (a *Agent) taskWorker(ctx context.Context, workerID int, taskChan <-chan api.Task) {
	log.Printf("Worker %d started", workerID)

	for {
		select {
		case <-ctx.Done():
			log.Printf("Worker %d stopping", workerID)
			return
		case t, ok := <-taskChan:
			if !ok {
				log.Printf("Worker %d: task channel closed", workerID)
				return
			}
			log.Printf("Worker %d: processing task %d", workerID, t.ID)
			if err := a.handler.ProcessTask(ctx, t); err != nil {
				log.Printf("Worker %d: task %d failed: %v", workerID, t.ID, err)
			}
		}
	}
}

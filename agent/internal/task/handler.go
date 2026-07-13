package task

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"

	"github.com/phpborg/phpborg-agent/internal/api"
	"github.com/phpborg/phpborg-agent/internal/config"
	"github.com/phpborg/phpborg-agent/internal/executor"
)

// Note: executor package is imported above for BorgProgress type

// Handler processes tasks received from the phpBorg server
type Handler struct {
	config   *config.Config
	client   *api.Client
	executor *executor.Executor
	// activeBackups counts backup_create tasks currently running (atomic). An
	// agent_update is DEFERRED while any backup runs, so a self-update never kills a
	// backup mid-flight (Bug 27a).
	activeBackups int32
}

// stateDir holds one marker file per running task so orphans left by a brutal restart
// can be reconciled (reported failed) at the next startup (Bug 27c).
func (h *Handler) stateDir() string {
	return "/var/lib/phpborg-agent/state/running"
}

func (h *Handler) markTaskRunning(taskID int) {
	dir := h.stateDir()
	if err := os.MkdirAll(dir, 0755); err != nil {
		log.Printf("[STATE] could not create state dir: %v", err)
		return
	}
	_ = os.WriteFile(filepath.Join(dir, strconv.Itoa(taskID)), []byte(time.Now().UTC().Format(time.RFC3339)), 0644)
}

func (h *Handler) clearTaskRunning(taskID int) {
	_ = os.Remove(filepath.Join(h.stateDir(), strconv.Itoa(taskID)))
}

// ReconcileOrphanedTasks reports as failed any task still marked running at startup —
// i.e. a task that was interrupted by a restart/crash and could never report itself
// (Bug 27c). Prevents orphan tasks stuck "running" server-side.
func (h *Handler) ReconcileOrphanedTasks(ctx context.Context) {
	dir := h.stateDir()
	entries, err := os.ReadDir(dir)
	if err != nil {
		return // no state dir => nothing to reconcile
	}
	for _, e := range entries {
		if e.IsDir() {
			continue
		}
		taskID, err := strconv.Atoi(e.Name())
		if err != nil {
			_ = os.Remove(filepath.Join(dir, e.Name()))
			continue
		}
		log.Printf("[STATE] reconciling orphaned task #%d (agent restarted while it was running)", taskID)
		if err := h.client.FailTask(ctx, taskID, "agent restarted while task was running; backup interrupted (resumes from last borg checkpoint on retry)", 137); err != nil {
			log.Printf("[STATE] could not report orphan #%d failed: %v (will retry next start)", taskID, err)
			continue // keep the marker so we try again next start
		}
		_ = os.Remove(filepath.Join(dir, e.Name()))
	}
}

// NewHandler creates a new task handler
func NewHandler(cfg *config.Config, client *api.Client, exec *executor.Executor) *Handler {
	return &Handler{
		config:   cfg,
		client:   client,
		executor: exec,
	}
}

// ProcessTask handles a single task
func (h *Handler) ProcessTask(ctx context.Context, task api.Task) error {
	log.Printf("[TASK] Processing task %d: type=%s priority=%s", task.ID, task.Type, task.Priority)

	// Mark task as started
	if err := h.client.StartTask(ctx, task.ID); err != nil {
		return fmt.Errorf("failed to start task: %w", err)
	}

	// Create task context with timeout.
	// Bug 23: backups of multi-TB / millions-of-small-files repos legitimately run for
	// many hours. They must NOT inherit the generic 1h fallback (which killed borg mid
	// archive). When no explicit timeout is set for a backup task, run without a cap
	// (bounded only by the parent context / the borg process itself).
	timeout := time.Duration(task.TimeoutSeconds) * time.Second
	isBackup := task.Type == "backup_create" || task.Type == "backup_restore"
	if timeout <= 0 && !isBackup {
		timeout = 1 * time.Hour
	}

	var taskCtx context.Context
	var cancel context.CancelFunc
	if timeout > 0 {
		taskCtx, cancel = context.WithTimeout(ctx, timeout)
	} else {
		// No timeout cap (backup task without an explicit timeout).
		taskCtx, cancel = context.WithCancel(ctx)
	}
	defer cancel()

	// Execute task based on type
	var result map[string]interface{}
	var taskErr error
	var exitCode int

	switch task.Type {
	case "backup_create":
		result, exitCode, taskErr = h.handleBackupCreate(taskCtx, task)
	case "backup_restore":
		result, exitCode, taskErr = h.handleBackupRestore(taskCtx, task)
	case "capabilities_detect":
		result, exitCode, taskErr = h.handleCapabilitiesDetect(taskCtx, task)
	case "stats_collect":
		result, exitCode, taskErr = h.handleStatsCollect(taskCtx, task)
	case "agent_update":
		result, exitCode, taskErr = h.handleAgentUpdate(taskCtx, task)
	case "test":
		result, exitCode, taskErr = h.handleTest(taskCtx, task)
	default:
		taskErr = fmt.Errorf("unknown task type: %s", task.Type)
		exitCode = 1
	}

	// Report result
	if taskErr != nil {
		log.Printf("[TASK] Task %d failed: %v", task.ID, taskErr)
		if err := h.client.FailTask(ctx, task.ID, taskErr.Error(), exitCode); err != nil {
			log.Printf("[TASK] Failed to report failure: %v", err)
		}
		return taskErr
	}

	log.Printf("[TASK] Task %d completed successfully", task.ID)
	if err := h.client.CompleteTask(ctx, task.ID, result, exitCode); err != nil {
		log.Printf("[TASK] Failed to report completion: %v", err)
		return err
	}

	return nil
}

// tailString returns at most the last n bytes of s, marking truncation. Bug 24: borg's
// --progress/--log-json stream over 800k+ files is multiple MB and overflowed the
// persisted job output. We keep the final --json stats (stdout, small) and only a tail
// of the stderr stream.
func tailString(s string, n int) string {
	if len(s) <= n {
		return s
	}
	return "...[truncated " + strconv.Itoa(len(s)-n) + " bytes]...\n" + s[len(s)-n:]
}

// isTransientConnError reports whether borg's stderr indicates a transient SSH/network
// drop worth retrying (source server here has a failing HBA + flaky link). A genuine
// borg/repo error (config, auth, disk full) is NOT matched, so it is not retried.
func isTransientConnError(stderr string) bool {
	s := strings.ToLower(stderr)
	for _, needle := range []string{
		"connection closed by remote host",
		"connection reset by peer",
		"broken pipe",
		"connection timed out",
		"connection refused",
		"ssh: connect to host",
		"kex_exchange_identification",
		"connection to ", // "Connection to <host> closed"
		"client_loop: send disconnect",
	} {
		if strings.Contains(s, needle) {
			return true
		}
	}
	return false
}

// handleBackupCreate handles a backup creation task
func (h *Handler) handleBackupCreate(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	// Extract parameters from payload
	repoPath, _ := task.Payload["repo_path"].(string)
	archiveName, _ := task.Payload["archive_name"].(string)
	passphrase, _ := task.Payload["passphrase"].(string)

	pathsRaw, _ := task.Payload["paths"].([]interface{})
	paths := make([]string, len(pathsRaw))
	for i, p := range pathsRaw {
		paths[i], _ = p.(string)
	}

	excludesRaw, _ := task.Payload["excludes"].([]interface{})
	excludes := make([]string, len(excludesRaw))
	for i, e := range excludesRaw {
		excludes[i], _ = e.(string)
	}

	compression, _ := task.Payload["compression"].(string)

	// Bug 17: optional --one-file-system (JSON booleans decode to bool)
	oneFileSystem, _ := task.Payload["one_file_system"].(bool)

	// Bug 21: allow access to an unencrypted repository (empty passphrase). Explicit
	// payload flag wins; otherwise inferred from an empty passphrase below.
	allowUnencrypted, _ := task.Payload["allow_unencrypted"].(bool)

	if repoPath == "" || archiveName == "" || len(paths) == 0 {
		return nil, 1, fmt.Errorf("missing required parameters: repo_path, archive_name, paths")
	}

	// Bug 27a: this backup is active — an agent_update will be deferred while it runs.
	atomic.AddInt32(&h.activeBackups, 1)
	defer atomic.AddInt32(&h.activeBackups, -1)
	// Bug 27c: persist a marker so an orphan left by a brutal restart is reconciled.
	h.markTaskRunning(task.ID)
	defer h.clearTaskRunning(task.ID)

	// Bug 33: the expected total size (osize of the LAST archive of this repo, provided
	// by the server) lets us compute a REAL percentage from borg's archive_progress
	// instead of a hardcoded 10%.
	expectedOsize := int64(0)
	if v, ok := task.Payload["expected_osize"].(float64); ok {
		expectedOsize = int64(v)
	}

	// Shared live-progress state (Bug 33): the phase is EXPLICIT and the keepalive
	// re-sends the last RICH info instead of a generic message that used to wipe the
	// stats and reset the percentage.
	var progressMu sync.Mutex
	lastPct := 5
	lastInfo := api.ProgressInfo{
		Phase:   "init",
		Message: "Initializing: repository lock & chunk cache sync...",
	}
	sendProgress := func() {
		progressMu.Lock()
		pct, info := lastPct, lastInfo
		progressMu.Unlock()
		_ = h.client.UpdateProgressWithInfo(ctx, task.ID, pct, info)
	}

	// Bug 26: keepalive. borg's cache sync (~20 min) emits no progress events, so ping
	// every 60s while the backup runs — the server's progress_updated_at stays fresh as
	// long as the agent is alive, and the watchdog only fires on a dead agent.
	keepaliveStop := make(chan struct{})
	defer close(keepaliveStop)
	go func() {
		ticker := time.NewTicker(60 * time.Second)
		defer ticker.Stop()
		for {
			select {
			case <-keepaliveStop:
				return
			case <-ctx.Done():
				return
			case <-ticker.C:
				sendProgress()
			}
		}
	}()

	// Initial progress: explicit init phase
	sendProgress()

	// Create cancellable context for the backup operation
	backupCtx, cancelBackup := context.WithCancel(ctx)
	defer cancelBackup()

	// Track if cancelled
	cancelled := false

	// Start goroutine to check for cancellation every 5 seconds
	go func() {
		ticker := time.NewTicker(5 * time.Second)
		defer ticker.Stop()

		for {
			select {
			case <-backupCtx.Done():
				return
			case <-ticker.C:
				// Check if task has been cancelled
				status, err := h.client.GetTaskStatus(ctx, task.ID)
				if err != nil {
					log.Printf("[BACKUP] Failed to check task status: %v", err)
					continue
				}
				if status.ShouldCancel {
					log.Printf("[BACKUP] Task %d cancelled by user, stopping backup...", task.ID)
					cancelled = true
					cancelBackup()
					return
				}
			}
		}
	}()

	// Track last update time for throttling
	var lastUpdate time.Time

	// Create progress callback for real-time updates
	progressCallback := func(progress executor.BorgProgress) {
		// Throttle updates to max 1 per second
		if time.Since(lastUpdate) < time.Second {
			return
		}
		lastUpdate = time.Now()

		// Bug 33: REAL percentage — processed osize vs the last archive's total osize
		// (provided by the server), capped at 99 until the archive is committed.
		// Unknown total (first backup of a repo): stay at a nominal 10.
		progressPercent := 10
		if expectedOsize > 0 && progress.OriginalSize > 0 {
			pct := int(progress.OriginalSize * 100 / expectedOsize)
			if pct < 1 {
				pct = 1
			}
			if pct > 99 {
				pct = 99
			}
			progressPercent = pct
		}

		// Send detailed progress info with the EXPLICIT phase (first borg
		// archive_progress event => data is flowing => transfer)
		info := api.ProgressInfo{
			FilesCount:       progress.NFiles,
			OriginalSize:     progress.OriginalSize,
			CompressedSize:   progress.CompressedSize,
			DeduplicatedSize: progress.DeduplicatedSize,
			CurrentPath:      progress.Path,
			Phase:            "transfer",
		}

		// Format a human-readable message
		info.Message = fmt.Sprintf("Backing up: %d files, %s processed",
			progress.NFiles,
			formatBytes(progress.OriginalSize),
		)

		// Remember as the latest rich state (re-sent by the keepalive)
		progressMu.Lock()
		lastPct, lastInfo = progressPercent, info
		progressMu.Unlock()

		if err := h.client.UpdateProgressWithInfo(ctx, task.ID, progressPercent, info); err != nil {
			log.Printf("[BACKUP] Failed to send progress update: %v", err)
		}
	}

	// Execute borg create with progress streaming (uses cancellable context).
	// P1: auto-retry with backoff on a transient SSH/connection drop. Thanks to
	// --checkpoint-interval, borg resumes from the last checkpoint and dedups against
	// the already-committed chunks, so a retry catches up quickly instead of restarting.
	const maxBorgAttempts = 6
	var result *executor.CommandResult
	for attempt := 1; attempt <= maxBorgAttempts; attempt++ {
		result = h.executor.BorgCreateWithProgress(backupCtx, repoPath, archiveName, paths, excludes, compression, passphrase, oneFileSystem, allowUnencrypted, progressCallback)

		// Stop immediately on cancel/timeout or a committed result (exit 0/1).
		if backupCtx.Err() != nil || cancelled {
			break
		}
		if result.ExitCode == 0 || result.ExitCode == 1 {
			break
		}
		// Retry only on a transient connection failure, with capped backoff.
		if attempt < maxBorgAttempts && isTransientConnError(result.Stderr) {
			backoff := time.Duration(attempt*30) * time.Second // 30s,60s,90s,...
			log.Printf("[BACKUP] transient connection failure (attempt %d/%d), resuming in %v", attempt, maxBorgAttempts, backoff)
			h.client.UpdateProgress(ctx, task.ID, 10, fmt.Sprintf("Connection lost — resuming from last checkpoint in %ds (attempt %d/%d)...", int(backoff.Seconds()), attempt+1, maxBorgAttempts))
			select {
			case <-time.After(backoff):
			case <-backupCtx.Done():
			}
			continue
		}
		break // non-transient error: fall through to the status logic below
	}

	// === Bug 23: derive the status from the REAL outcome ===================
	// A "success" MUST NEVER be reported unless borg exited cleanly and therefore
	// committed the archive. A killed/timed-out/signalled process returns a negative
	// exit code and MUST fail — otherwise phpBorg reports a backup that does not exist.

	// 1) The task context ended (deadline or cancellation) => borg was killed before
	//    completion, so NO archive was committed. This must fail, whatever the exit code.
	if ctxErr := backupCtx.Err(); ctxErr != nil {
		if ctxErr == context.DeadlineExceeded {
			return nil, 124, fmt.Errorf(
				"backup TIMED OUT after %s and was killed before completion — no archive committed (borg exit %d): %s",
				result.Duration, result.ExitCode, result.Stderr,
			)
		}
		return nil, 130, fmt.Errorf(
			"backup was cancelled before completion — no archive committed (borg exit %d): %s",
			result.ExitCode, result.Stderr,
		)
	}

	// 2) Explicit user-cancel flag (belt and suspenders).
	if cancelled {
		return nil, 130, fmt.Errorf("backup cancelled by user")
	}

	// 3) Borg exit codes: 0 = success, 1 = warnings (archive STILL committed).
	//    Anything else — >=2 (error) OR <0 (killed/signalled, e.g. -1) — is a FAILURE.
	//    The previous check `ExitCode > 1` wrongly let a killed borg (exit -1) through
	//    and reported a phantom success.
	if result.ExitCode != 0 && result.ExitCode != 1 {
		return nil, result.ExitCode, fmt.Errorf(
			"borg create failed (exit %d) — no archive committed: %s",
			result.ExitCode, result.Stderr,
		)
	}

	// === Bug 32a: PROOF OF ARCHIVE — the non-negotiable gate =================
	// Never conclude "completed" from exit codes alone: on 2.4.7 a sudo failure
	// (exit 1, borg never launched) was mistaken for borg warnings and produced a
	// phantom success. Whatever the exit code says, the archive must EXIST.
	progressMu.Lock()
	lastPct = 92
	lastInfo.Phase = "finalize"
	lastInfo.Message = "Verifying the archive was committed..."
	progressMu.Unlock()
	sendProgress()
	exists, vres := h.executor.BorgArchiveExists(ctx, repoPath, archiveName, passphrase, allowUnencrypted)
	if !exists {
		return nil, 2, fmt.Errorf(
			"borg exited %d but archive %q is NOT present in the repository — the backup did NOT complete "+
				"(launcher or borg failed; ran_as_root=%v). borg stderr: %s | verification stderr: %s",
			result.ExitCode, archiveName, result.RanAsRoot,
			tailString(result.Stderr, 2000), tailString(vres.Stderr, 1000),
		)
	}

	// Check if there were warnings (exit code 1)
	hasWarnings := result.ExitCode == 1

	// Bug 31b: report skips HONESTLY. "Permission denied" skips mean the backup is
	// INCOMPLETE (root-only files like shadow / SSL keys are missing — a bare-metal
	// restore would not work); "vanished/changed" skips are benign on a live server.
	// A bland "some files skipped" hiding 216 unsaved secrets is unacceptable.
	permDenied := strings.Count(result.Stderr, "Permission denied")
	benignSkips := strings.Count(result.Stderr, "vanished") +
		strings.Count(result.Stderr, "changed while we backed it up")

	switch {
	case permDenied > 0:
		h.client.UpdateProgress(ctx, task.ID, 95, fmt.Sprintf(
			"WARNING: backup INCOMPLETE — %d file(s) UNREADABLE (permission denied). Root-only files (secrets, keys) are missing from this archive.",
			permDenied,
		))
	case hasWarnings:
		h.client.UpdateProgress(ctx, task.ID, 95, fmt.Sprintf(
			"Backup completed with benign warnings (%d file(s) changed/vanished while reading — normal on a live server)",
			benignSkips,
		))
	default:
		h.client.UpdateProgress(ctx, task.ID, 95, "Backup completed successfully")
	}

	res := map[string]interface{}{
		"stdout":                     result.Stdout,                    // final borg --json stats (kept in full)
		"stderr":                     tailString(result.Stderr, 16384), // Bug 24: only a tail of the progress stream
		"duration":                   result.Duration.String(),
		"has_warnings":               hasWarnings,
		"exit_code":                  result.ExitCode,
		"ran_as_root":                result.RanAsRoot,   // Bug 31: false => root-only files were skipped
		"archive_verified":           true,               // Bug 32: proof-of-archive check passed (borg list)
		"skipped_permission_denied":  permDenied,         // GRAVE: unreadable => incomplete backup
		"skipped_benign":             benignSkips,        // benign: changed/vanished on a live system
	}
	if permDenied > 0 {
		res["message"] = fmt.Sprintf(
			"INCOMPLETE BACKUP: %d file(s) skipped with 'Permission denied' (ran_as_root=%v). These files are NOT in the archive.",
			permDenied, result.RanAsRoot,
		)
	}
	return res, 0, nil
}

// formatBytes formats bytes into human-readable string
func formatBytes(bytes int64) string {
	if bytes == 0 {
		return "0 B"
	}

	units := []string{"B", "KB", "MB", "GB", "TB"}
	i := 0
	size := float64(bytes)

	for size >= 1024 && i < len(units)-1 {
		size /= 1024
		i++
	}

	return fmt.Sprintf("%.1f %s", size, units[i])
}

// handleBackupRestore handles a backup restoration task
func (h *Handler) handleBackupRestore(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	repoPath, _ := task.Payload["repo_path"].(string)
	archiveName, _ := task.Payload["archive_name"].(string)
	destPath, _ := task.Payload["dest_path"].(string)

	patternsRaw, _ := task.Payload["patterns"].([]interface{})
	patterns := make([]string, len(patternsRaw))
	for i, p := range patternsRaw {
		patterns[i], _ = p.(string)
	}

	if repoPath == "" || archiveName == "" {
		return nil, 1, fmt.Errorf("missing required parameters: repo_path, archive_name")
	}

	if destPath == "" {
		destPath = "/var/restore"
	}

	h.client.UpdateProgress(ctx, task.ID, 10, "Starting restore...")

	result := h.executor.BorgExtract(ctx, repoPath, archiveName, destPath, patterns)

	if result.ExitCode != 0 {
		return nil, result.ExitCode, fmt.Errorf("borg extract failed: %s", result.Stderr)
	}

	h.client.UpdateProgress(ctx, task.ID, 90, "Restore completed")

	return map[string]interface{}{
		"stdout":    result.Stdout,
		"stderr":    tailString(result.Stderr, 16384), // Bug 24
		"duration":  result.Duration.String(),
		"dest_path": destPath,
	}, 0, nil
}

// handleCapabilitiesDetect detects system capabilities
func (h *Handler) handleCapabilitiesDetect(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	h.client.UpdateProgress(ctx, task.ID, 50, "Detecting capabilities...")

	caps := h.executor.DetectCapabilities(ctx)
	osInfo := h.executor.GetOSInfo(ctx)

	return map[string]interface{}{
		"capabilities": caps,
		"os_info":      osInfo,
	}, 0, nil
}

// handleStatsCollect handles a stats collection task
func (h *Handler) handleStatsCollect(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	log.Printf("[STATS] Collecting system stats...")
	h.client.UpdateProgress(ctx, task.ID, 10, "Collecting system information...")

	stats := make(map[string]interface{})

	// OS Information
	h.client.UpdateProgress(ctx, task.ID, 20, "Collecting OS info...")
	osInfo := h.executor.GetOSInfo(ctx)
	stats["os_info"] = osInfo

	// Parse OS info to extract distribution and version
	osResult := h.executor.Run(ctx, "cat", []string{"/etc/os-release"}, 10*time.Second)
	if osResult.ExitCode == 0 {
		for _, line := range strings.Split(osResult.Stdout, "\n") {
			if strings.HasPrefix(line, "NAME=") {
				stats["os_distribution"] = strings.Trim(strings.TrimPrefix(line, "NAME="), "\"")
			}
			if strings.HasPrefix(line, "VERSION=") {
				stats["os_version"] = strings.Trim(strings.TrimPrefix(line, "VERSION="), "\"")
			}
		}
	}

	// Kernel version
	kernelResult := h.executor.Run(ctx, "uname", []string{"-r"}, 10*time.Second)
	if kernelResult.ExitCode == 0 {
		stats["kernel_version"] = strings.TrimSpace(kernelResult.Stdout)
	}

	// Hostname
	hostnameResult := h.executor.Run(ctx, "hostname", nil, 10*time.Second)
	if hostnameResult.ExitCode == 0 {
		stats["hostname"] = strings.TrimSpace(hostnameResult.Stdout)
	}

	// Architecture
	archResult := h.executor.Run(ctx, "uname", []string{"-m"}, 10*time.Second)
	if archResult.ExitCode == 0 {
		stats["architecture"] = strings.TrimSpace(archResult.Stdout)
	}

	// CPU Information
	h.client.UpdateProgress(ctx, task.ID, 40, "Collecting CPU info...")

	// CPU cores
	nprocResult := h.executor.Run(ctx, "nproc", nil, 10*time.Second)
	if nprocResult.ExitCode == 0 {
		if cores, err := strconv.Atoi(strings.TrimSpace(nprocResult.Stdout)); err == nil {
			stats["cpu_cores"] = cores
		}
	}

	// CPU model
	cpuModelResult := h.executor.Run(ctx, "sh", []string{"-c", `grep "model name" /proc/cpuinfo | head -1 | cut -d":" -f2`}, 10*time.Second)
	if cpuModelResult.ExitCode == 0 {
		stats["cpu_model"] = strings.TrimSpace(cpuModelResult.Stdout)
	}

	// Load averages
	loadResult := h.executor.Run(ctx, "cat", []string{"/proc/loadavg"}, 10*time.Second)
	if loadResult.ExitCode == 0 {
		loads := strings.Fields(loadResult.Stdout)
		if len(loads) >= 3 {
			if load1, err := strconv.ParseFloat(loads[0], 64); err == nil {
				stats["cpu_load_1"] = load1
			}
			if load5, err := strconv.ParseFloat(loads[1], 64); err == nil {
				stats["cpu_load_5"] = load5
			}
			if load15, err := strconv.ParseFloat(loads[2], 64); err == nil {
				stats["cpu_load_15"] = load15
			}
		}
	}

	// Memory Information
	h.client.UpdateProgress(ctx, task.ID, 60, "Collecting memory info...")
	memResult := h.executor.Run(ctx, "free", []string{"-m"}, 10*time.Second)
	if memResult.ExitCode == 0 {
		for _, line := range strings.Split(memResult.Stdout, "\n") {
			fields := strings.Fields(line)
			if len(fields) >= 4 && fields[0] == "Mem:" {
				if total, err := strconv.Atoi(fields[1]); err == nil {
					stats["memory_total_mb"] = total
				}
				if used, err := strconv.Atoi(fields[2]); err == nil {
					stats["memory_used_mb"] = used
				}
				if free, err := strconv.Atoi(fields[3]); err == nil {
					stats["memory_free_mb"] = free
				}
				if len(fields) >= 7 {
					if available, err := strconv.Atoi(fields[6]); err == nil {
						stats["memory_available_mb"] = available
					}
				}
				if total, ok := stats["memory_total_mb"].(int); ok && total > 0 {
					if used, ok := stats["memory_used_mb"].(int); ok {
						stats["memory_percent"] = float64(used) / float64(total) * 100
					}
				}
			}
			if len(fields) >= 3 && fields[0] == "Swap:" {
				if total, err := strconv.Atoi(fields[1]); err == nil {
					stats["swap_total_mb"] = total
				}
				if used, err := strconv.Atoi(fields[2]); err == nil {
					stats["swap_used_mb"] = used
				}
			}
		}
	}

	// Disk Information
	h.client.UpdateProgress(ctx, task.ID, 80, "Collecting disk info...")
	dfResult := h.executor.Run(ctx, "df", []string{"-BG", "/"}, 10*time.Second)
	if dfResult.ExitCode == 0 {
		lines := strings.Split(dfResult.Stdout, "\n")
		if len(lines) >= 2 {
			fields := strings.Fields(lines[1])
			if len(fields) >= 5 {
				// Parse sizes (remove trailing G)
				if total, err := strconv.Atoi(strings.TrimSuffix(fields[1], "G")); err == nil {
					stats["disk_total_gb"] = total
				}
				if used, err := strconv.Atoi(strings.TrimSuffix(fields[2], "G")); err == nil {
					stats["disk_used_gb"] = used
				}
				if free, err := strconv.Atoi(strings.TrimSuffix(fields[3], "G")); err == nil {
					stats["disk_free_gb"] = free
				}
				// Parse percentage (remove trailing %)
				if percent, err := strconv.Atoi(strings.TrimSuffix(fields[4], "%")); err == nil {
					stats["disk_percent"] = percent
				}
			}
		}
	}

	// Uptime
	uptimeResult := h.executor.Run(ctx, "cat", []string{"/proc/uptime"}, 10*time.Second)
	if uptimeResult.ExitCode == 0 {
		uptimeParts := strings.Fields(uptimeResult.Stdout)
		if len(uptimeParts) >= 1 {
			if uptimeFloat, err := strconv.ParseFloat(uptimeParts[0], 64); err == nil {
				uptimeSeconds := int(uptimeFloat)
				stats["uptime_seconds"] = uptimeSeconds
				stats["uptime_human"] = formatUptime(uptimeSeconds)
			}
		}
	}

	// IP Address
	ipResult := h.executor.Run(ctx, "hostname", []string{"-I"}, 10*time.Second)
	if ipResult.ExitCode == 0 {
		ips := strings.Fields(ipResult.Stdout)
		if len(ips) > 0 {
			stats["ip_address"] = ips[0]
		}
	}

	h.client.UpdateProgress(ctx, task.ID, 100, "Stats collection completed")
	log.Printf("[STATS] Stats collection completed: %d metrics", len(stats))

	return stats, 0, nil
}

// formatUptime converts seconds to human-readable string
func formatUptime(seconds int) string {
	if seconds < 60 {
		return fmt.Sprintf("%d seconds", seconds)
	}

	minutes := seconds / 60
	hours := minutes / 60
	days := hours / 24

	if days > 0 {
		hours = hours % 24
		return fmt.Sprintf("%d days, %d hours", days, hours)
	}

	if hours > 0 {
		minutes = minutes % 60
		return fmt.Sprintf("%d hours, %d minutes", hours, minutes)
	}

	return fmt.Sprintf("%d minutes", minutes)
}

// handleTest handles a test task (for debugging)
func (h *Handler) handleTest(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	message, _ := task.Payload["message"].(string)
	if message == "" {
		message = "Test task executed successfully"
	}

	// Simulate some work
	for i := 0; i <= 100; i += 20 {
		select {
		case <-ctx.Done():
			return nil, 1, ctx.Err()
		default:
			h.client.UpdateProgress(ctx, task.ID, i, fmt.Sprintf("Test progress: %d%%", i))
			time.Sleep(500 * time.Millisecond)
		}
	}

	return map[string]interface{}{
		"message": message,
		"time":    time.Now().Format(time.RFC3339),
	}, 0, nil
}

// handleAgentUpdate handles an agent self-update task
// This downloads the new binary, verifies it, replaces the current binary,
// and restarts the agent via systemd
func (h *Handler) handleAgentUpdate(ctx context.Context, task api.Task) (map[string]interface{}, int, error) {
	// Bug 27a: NEVER self-update while a backup is running — the restart would kill borg
	// mid-archive (this is exactly what killed backup #89 at ~457 GB). Defer: report the
	// update as deferred; the server re-offers it and it applies once the backup is done.
	if atomic.LoadInt32(&h.activeBackups) > 0 {
		log.Printf("[UPDATE] deferred: a backup is running (won't restart the agent mid-backup)")
		return map[string]interface{}{
			"status":  "deferred",
			"message": "Agent update deferred: a backup is currently running. It will apply after the backup completes.",
		}, 0, nil
	}

	// Extract parameters
	expectedChecksum, _ := task.Payload["checksum"].(string)
	newVersion, _ := task.Payload["version"].(string)
	forceUpdate, _ := task.Payload["force"].(bool)

	if expectedChecksum == "" && !forceUpdate {
		return nil, 1, fmt.Errorf("checksum required for update (or set force=true)")
	}

	// Guard 3 (idempotence) — THE fix for the restart loop that killed backup #89:
	// if the RUNNING binary is already the target version, do NOTHING. No download, no
	// replace, and above all NO restart. A redundant restart of an already-up-to-date
	// agent must never happen, even if a previous update's unit/sudoers write failed
	// (read-only /etc) and the server keeps re-offering the same version.
	if !forceUpdate && newVersion != "" && newVersion == h.config.Agent.Version {
		log.Printf("[UPDATE] already running target version %s — no action, no restart", newVersion)
		return map[string]interface{}{
			"status":       "up_to_date",
			"new_version":  newVersion,
			"prev_version": h.config.Agent.Version,
			"message":      fmt.Sprintf("Agent already at %s; no update or restart needed.", newVersion),
		}, 0, nil
	}

	h.client.UpdateProgress(ctx, task.ID, 10, "Preparing update...")

	// Get current binary path
	currentBinary, err := os.Executable()
	if err != nil {
		return nil, 1, fmt.Errorf("failed to get current executable path: %w", err)
	}
	currentBinary, err = filepath.EvalSymlinks(currentBinary)
	if err != nil {
		return nil, 1, fmt.Errorf("failed to resolve executable path: %w", err)
	}

	// Create temporary file for new binary in /tmp (works with systemd PrivateTmp)
	tmpFile := "/tmp/.phpborg-agent.new"

	h.client.UpdateProgress(ctx, task.ID, 20, "Downloading new binary...")

	// Download new binary
	if err := h.client.DownloadUpdate(ctx, tmpFile); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to download update: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 60, "Verifying checksum...")

	// Verify checksum
	if expectedChecksum != "" {
		actualChecksum, err := fileChecksum(tmpFile)
		if err != nil {
			os.Remove(tmpFile)
			return nil, 1, fmt.Errorf("failed to calculate checksum: %w", err)
		}

		if actualChecksum != expectedChecksum {
			os.Remove(tmpFile)
			return nil, 1, fmt.Errorf("checksum mismatch: expected %s, got %s", expectedChecksum, actualChecksum)
		}
	}

	h.client.UpdateProgress(ctx, task.ID, 70, "Making binary executable...")

	// Make executable
	if err := os.Chmod(tmpFile, 0755); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to set executable permission: %w", err)
	}

	// Verify the new binary works
	h.client.UpdateProgress(ctx, task.ID, 75, "Verifying new binary...")
	cmd := exec.CommandContext(ctx, tmpFile, "-version")
	if output, err := cmd.CombinedOutput(); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("new binary verification failed: %v (output: %s)", err, string(output))
	}

	h.client.UpdateProgress(ctx, task.ID, 80, "Backing up current binary...")

	// Backup current binary to /tmp (systemd protects /usr/local/bin)
	backupFile := "/tmp/.phpborg-agent.backup"
	if err := copyFile(currentBinary, backupFile); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to backup current binary: %w", err)
	}

	h.client.UpdateProgress(ctx, task.ID, 85, "Replacing binary...")

	// Rename running binary first (Linux allows this even while executing)
	oldBinary := currentBinary + ".old"
	os.Remove(oldBinary) // Remove any previous .old file
	if err := os.Rename(currentBinary, oldBinary); err != nil {
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to rename current binary: %w", err)
	}

	// Copy new binary to destination
	if err := copyFile(tmpFile, currentBinary); err != nil {
		// Try to restore old binary
		os.Rename(oldBinary, currentBinary)
		os.Remove(tmpFile)
		return nil, 1, fmt.Errorf("failed to install new binary: %w", err)
	}

	// Set ownership to match old binary (agent user)
	if err := os.Chmod(currentBinary, 0755); err != nil {
		log.Printf("[UPDATE] Warning: failed to set permissions: %v", err)
	}

	os.Remove(tmpFile)    // Clean up temp file
	os.Remove(oldBinary)  // Clean up old binary

	h.client.UpdateProgress(ctx, task.ID, 85, "Updating system configuration...")

	// Bug 22: update the systemd unit / sudoers ONLY if they changed, and capture any
	// failure to write a needed change (e.g. read-only /etc under ProtectSystem=strict)
	// so the task does not silently report success.
	_, svcErr := h.updateSystemdService()
	_, sudoErr := h.updateSudoersFile()

	h.client.UpdateProgress(ctx, task.ID, 90, "Restarting agent via systemd...")

	// Restart via systemd (this will kill us, but the task is essentially done)
	// We use a background process so we can return the result first
	go func() {
		time.Sleep(2 * time.Second) // Give time to send result

		// Best effort: try an explicit restart, else exit so systemd (Restart=always)
		// brings us back on the new binary. Under a hardened unit (NoNewPrivileges) sudo
		// is expected to be unavailable, so the exit fallback is the NORMAL path here —
		// not an error worth alarming about on every update.
		if err := exec.Command("systemctl", "restart", "phpborg-agent").Run(); err == nil {
			return
		}
		if err := exec.Command("sudo", "systemctl", "restart", "phpborg-agent").Run(); err == nil {
			return
		}
		log.Printf("[UPDATE] restarting via systemd (Restart=always) to load the new binary...")
		os.Exit(0)
	}()

	// Clean up backup after successful binary update
	os.Remove(backupFile)

	result := map[string]interface{}{
		"previous_version": h.config.Agent.Version,
		"new_version":      newVersion,
		"binary_path":      currentBinary,
	}

	// Bug 22: the binary updated fine. Handle a unit/sudoers refresh that could not be
	// applied.
	if svcErr != nil || sudoErr != nil {
		// Case A — read-only /etc only (agent deployed before ReadWritePaths was added
		// to its unit; ProtectSystem=strict). The binary IS updated and the agent works;
		// the config refresh can only be applied by a one-time redeploy. Do NOT fail the
		// task and do NOT alarm on every single update — complete with a clear note.
		if (svcErr == nil || isReadOnlyErr(svcErr)) && (sudoErr == nil || isReadOnlyErr(sudoErr)) {
			result["status"] = "updated"
			result["config_update"] = "deferred: /etc is read-only in the agent sandbox (ProtectSystem=strict) — redeploy the agent once to refresh its unit/sudoers"
			result["message"] = fmt.Sprintf(
				"Agent updated to %s. Unit/sudoers refresh deferred (read-only /etc) — redeploy once to apply.",
				newVersion,
			)
			log.Printf("[UPDATE] unit/sudoers refresh deferred: read-only /etc (redeploy the agent once to apply)")
			return result, 0, nil
		}

		// Case B — an UNEXPECTED write failure (permissions, disk full, ...). Surface it.
		result["status"] = "updated_with_warnings"
		result["message"] = fmt.Sprintf(
			"Binary updated to %s but system config could not be updated: systemd=%v sudoers=%v",
			newVersion, svcErr, sudoErr,
		)
		return result, 1, fmt.Errorf(
			"agent binary updated to %s but unit/sudoers could not be updated: systemd=%v sudoers=%v",
			newVersion, svcErr, sudoErr,
		)
	}

	result["status"] = "updated"
	result["message"] = "Agent updated successfully, restarting..."
	return result, 0, nil
}

// fileChecksum calculates SHA256 checksum of a file
func fileChecksum(path string) (string, error) {
	f, err := os.Open(path)
	if err != nil {
		return "", err
	}
	defer f.Close()

	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", err
	}

	return hex.EncodeToString(h.Sum(nil)), nil
}

// copyFile copies a file from src to dst
func copyFile(src, dst string) error {
	sourceFile, err := os.Open(src)
	if err != nil {
		return err
	}
	defer sourceFile.Close()

	destFile, err := os.Create(dst)
	if err != nil {
		return err
	}
	defer destFile.Close()

	_, err = io.Copy(destFile, sourceFile)
	if err != nil {
		return err
	}

	// Preserve permissions
	sourceInfo, err := os.Stat(src)
	if err != nil {
		return err
	}
	return os.Chmod(dst, sourceInfo.Mode())
}

// desiredSystemdUnit is the canonical agent unit. It MUST stay in sync with the
// installer (AgentInstallService::generateInstallScript). It includes ReadWritePaths
// for the agent's own unit file and sudoers file so that self-update can rewrite them
// even under ProtectSystem=strict (Bug 22).
const desiredSystemdUnit = `[Unit]
Description=phpBorg Agent
Documentation=https://github.com/altzone/phpBorg
After=network.target

[Service]
Type=simple
User=phpborg-agent
Group=phpborg-agent
ExecStart=/var/lib/phpborg-agent/bin/phpborg-agent -config /etc/phpborg-agent/config.yaml
Restart=always
RestartSec=10

# Security hardening.
# NoNewPrivileges MUST be "no": the agent runs borg create as root via sudo (Bug 31)
# and NNP=yes forbids any privilege elevation — with it, sudo fails with
# 'The "no new privileges" flag is set' and no backup can read root-only files.
# ProtectSystem=strict stays: borg only READS sources (read-only fs is fine) and
# writes its cache under /var/lib/phpborg-agent (ReadWritePaths below).
NoNewPrivileges=no
ProtectSystem=strict
ProtectHome=read-only
PrivateTmp=yes
ReadWritePaths=/var/log/phpborg-agent
ReadWritePaths=/var/lib/phpborg-agent
ReadWritePaths=/etc/systemd/system/phpborg-agent.service
ReadWritePaths=/etc/sudoers.d/phpborg-agent

[Install]
WantedBy=multi-user.target
`

// isReadOnlyErr reports whether a write failed because the filesystem is read-only —
// the expected case for an agent deployed before ReadWritePaths was added to its unit
// (ProtectSystem=strict makes /etc read-only). This is a redeploy-to-fix condition, not
// a per-update error to alarm on.
func isReadOnlyErr(err error) bool {
	return err != nil && strings.Contains(strings.ToLower(err.Error()), "read-only file system")
}

// updateSystemdService rewrites the unit ONLY if it differs from the canonical content
// (Bug 22 fix #3), and returns whether it changed and any error writing a needed change
// (fix #2). It never rewrites (nor fails) when the unit is already up to date.
func (h *Handler) updateSystemdService() (bool, error) {
	servicePath := "/etc/systemd/system/phpborg-agent.service"

	current, _ := os.ReadFile(servicePath)
	if string(current) == desiredSystemdUnit {
		log.Println("[UPDATE] systemd unit already up to date, skipping")
		return false, nil
	}

	if err := os.WriteFile(servicePath, []byte(desiredSystemdUnit), 0644); err != nil {
		// Read-only /etc is expected on older-deployed agents; handled calmly by the
		// caller (deferred, not a per-update alarm). Only log unexpected failures here.
		if !isReadOnlyErr(err) {
			log.Printf("[UPDATE] failed to update systemd unit: %v", err)
		}
		return true, fmt.Errorf("systemd unit not writable: %w", err)
	}

	if err := exec.Command("systemctl", "daemon-reload").Run(); err != nil {
		// Non-fatal: the unit file was written; a reload will happen on the next boot.
		log.Printf("[UPDATE] warning: daemon-reload failed: %v", err)
	}
	log.Println("[UPDATE] systemd unit updated")
	return true, nil
}

// desiredSudoers is the canonical agent sudoers content applied by self-update.
const desiredSudoers = `# phpBorg Agent sudoers rules
# Minimal privileges for backup operations
# Auto-updated by agent self-update

Defaults:phpborg-agent !requiretty
Defaults:phpborg-agent env_reset

# System information (read-only)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /etc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/cat /proc/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/df *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/du *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/find *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/which *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl is-active *

# MySQL/MariaDB (read-only dumps)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysqldump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mariadb-dump *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mysql -e *

# PostgreSQL (read-only dumps)
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dump *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/pg_dumpall *
phpborg-agent ALL=(postgres) NOPASSWD: /usr/bin/psql -t -c *

# Borg Backup (Bug 31: SETENV lets the agent pass BORG_* vars — passphrase, RSH,
# BASE_DIR — inline through sudo so borg create runs as ROOT and reads every file)
phpborg-agent ALL=(root) NOPASSWD: SETENV: /usr/bin/borg --version
phpborg-agent ALL=(root) NOPASSWD: SETENV: /usr/bin/borg create *
phpborg-agent ALL=(root) NOPASSWD: SETENV: /usr/bin/borg extract *
phpborg-agent ALL=(root) NOPASSWD: SETENV: /usr/bin/borg list *

# LVM Detection (read-only)
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvs *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/vgs *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/pvs *

# LVM Snapshots (restricted naming pattern)
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvcreate -L * -s -n phpborg_snap_* *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/lvremove -f /dev/*/phpborg_snap_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mount -o ro /dev/*/phpborg_snap_* /mnt/phpborg_*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/umount /mnt/phpborg_*

# Btrfs Detection and Snapshots
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/btrfs subvolume list *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/btrfs subvolume snapshot *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/btrfs subvolume delete *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/btrfs filesystem show *
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/btrfs filesystem usage *

# ZFS Detection and Snapshots
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/zfs list *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/zfs snapshot *
phpborg-agent ALL=(root) NOPASSWD: /usr/sbin/zfs destroy *

# Agent self-update (restart service)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl restart phpborg-agent
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl stop phpborg-agent
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/systemctl start phpborg-agent

# Restore operations (restricted paths)
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/mkdir -p /var/restore/*
phpborg-agent ALL=(root) NOPASSWD: /usr/bin/rm -rf /var/restore/*
`

// updateSudoersFile rewrites the sudoers file ONLY if it differs from the canonical
// content (Bug 22 fix #3) and returns whether it changed plus any error writing a
// needed change (fix #2) — e.g. read-only /etc under ProtectSystem=strict.
func (h *Handler) updateSudoersFile() (bool, error) {
	sudoersPath := "/etc/sudoers.d/phpborg-agent"

	current, _ := os.ReadFile(sudoersPath)
	if string(current) == desiredSudoers {
		log.Println("[UPDATE] sudoers already up to date, skipping")
		return false, nil
	}

	if err := os.WriteFile(sudoersPath, []byte(desiredSudoers), 0440); err != nil {
		if !isReadOnlyErr(err) {
			log.Printf("[UPDATE] failed to update sudoers: %v", err)
		}
		return true, fmt.Errorf("sudoers not writable: %w", err)
	}
	log.Println("[UPDATE] sudoers updated")
	return true, nil
}

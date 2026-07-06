//go:build linux
// +build linux

package executor

import (
	"os/exec"
	"syscall"
)

// SetProcessGroup sets the process group ID for proper cleanup on Linux
func SetProcessGroup(cmd *exec.Cmd) {
	cmd.SysProcAttr = &syscall.SysProcAttr{Setpgid: true}
}

// TermProcessGroup sends SIGTERM to the whole process group so a child (borg) can shut
// down cleanly — committing a final checkpoint — instead of being SIGKILLed (Bug 27b).
func TermProcessGroup(cmd *exec.Cmd) error {
	if cmd.Process == nil {
		return nil
	}
	// Negative PID => signal the process group (the child is its group leader).
	return syscall.Kill(-cmd.Process.Pid, syscall.SIGTERM)
}

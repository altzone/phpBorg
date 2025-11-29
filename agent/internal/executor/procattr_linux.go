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

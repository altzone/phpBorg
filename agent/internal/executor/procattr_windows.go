//go:build windows
// +build windows

package executor

import (
	"os/exec"
)

// SetProcessGroup is a no-op on Windows
// Windows doesn't use process groups the same way as Unix
func SetProcessGroup(cmd *exec.Cmd) {
	// No-op on Windows
	// Windows uses job objects for process group management if needed
}

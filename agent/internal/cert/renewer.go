package cert

import (
	"context"
	"crypto/x509"
	"encoding/base64"
	"encoding/pem"
	"fmt"
	"log"
	"os"
	"path/filepath"
	"time"

	"github.com/phpborg/phpborg-agent/internal/api"
	"github.com/phpborg/phpborg-agent/internal/config"
)

const (
	// RenewalThreshold is the time before expiration to start renewal
	RenewalThreshold = 30 * 24 * time.Hour // 30 days
	// CheckInterval is how often to check certificate expiration
	CheckInterval = 24 * time.Hour // Daily
)

// Renewer handles automatic certificate renewal
type Renewer struct {
	config *config.Config
	client *api.Client
	stopCh chan struct{}
}

// NewRenewer creates a new certificate renewer
func NewRenewer(cfg *config.Config, client *api.Client) *Renewer {
	return &Renewer{
		config: cfg,
		client: client,
		stopCh: make(chan struct{}),
	}
}

// Start begins the certificate renewal background process
func (r *Renewer) Start(ctx context.Context) {
	// Only run if mTLS is configured
	if !r.config.UseTLS() {
		log.Println("[CERT] mTLS not configured, certificate renewal disabled")
		return
	}

	go r.renewalLoop(ctx)
}

// Stop stops the renewal background process
func (r *Renewer) Stop() {
	close(r.stopCh)
}

// renewalLoop periodically checks and renews certificates
func (r *Renewer) renewalLoop(ctx context.Context) {
	// Check immediately on start
	r.checkAndRenew(ctx)

	ticker := time.NewTicker(CheckInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-r.stopCh:
			return
		case <-ticker.C:
			r.checkAndRenew(ctx)
		}
	}
}

// checkAndRenew checks if certificate needs renewal and renews if necessary
func (r *Renewer) checkAndRenew(ctx context.Context) {
	expiry, err := r.getCertificateExpiry()
	if err != nil {
		log.Printf("[CERT] Failed to check certificate expiry: %v", err)
		return
	}

	timeUntilExpiry := time.Until(expiry)
	log.Printf("[CERT] Certificate expires in %v (at %s)", timeUntilExpiry.Round(time.Hour), expiry.Format(time.RFC3339))

	if timeUntilExpiry > RenewalThreshold {
		log.Printf("[CERT] Certificate valid for more than %v, no renewal needed", RenewalThreshold)
		return
	}

	log.Printf("[CERT] Certificate expires within %v, initiating renewal...", RenewalThreshold)

	if err := r.renewCertificate(ctx); err != nil {
		log.Printf("[CERT] Failed to renew certificate: %v", err)
		return
	}

	log.Println("[CERT] Certificate renewed successfully")
}

// getCertificateExpiry reads the current certificate and returns its expiration time
func (r *Renewer) getCertificateExpiry() (time.Time, error) {
	certPEM, err := os.ReadFile(r.config.TLS.CertFile)
	if err != nil {
		return time.Time{}, fmt.Errorf("failed to read certificate: %w", err)
	}

	block, _ := pem.Decode(certPEM)
	if block == nil {
		return time.Time{}, fmt.Errorf("failed to parse certificate PEM")
	}

	cert, err := x509.ParseCertificate(block.Bytes)
	if err != nil {
		return time.Time{}, fmt.Errorf("failed to parse certificate: %w", err)
	}

	return cert.NotAfter, nil
}

// renewCertificate requests and installs new certificates
func (r *Renewer) renewCertificate(ctx context.Context) error {
	// Request new certificate from server
	renewal, err := r.client.RenewCertificate(ctx)
	if err != nil {
		return fmt.Errorf("failed to request certificate renewal: %w", err)
	}

	// Decode certificates
	certData, err := base64.StdEncoding.DecodeString(renewal.Cert)
	if err != nil {
		return fmt.Errorf("failed to decode certificate: %w", err)
	}

	keyData, err := base64.StdEncoding.DecodeString(renewal.Key)
	if err != nil {
		return fmt.Errorf("failed to decode key: %w", err)
	}

	caData, err := base64.StdEncoding.DecodeString(renewal.CA)
	if err != nil {
		return fmt.Errorf("failed to decode CA: %w", err)
	}

	// Create backup of current certificates
	backupDir := filepath.Join(filepath.Dir(r.config.TLS.CertFile), "backup")
	if err := os.MkdirAll(backupDir, 0700); err != nil {
		log.Printf("[CERT] Warning: failed to create backup directory: %v", err)
	} else {
		timestamp := time.Now().Format("20060102-150405")
		r.backupFile(r.config.TLS.CertFile, filepath.Join(backupDir, fmt.Sprintf("agent-%s.crt", timestamp)))
		r.backupFile(r.config.TLS.KeyFile, filepath.Join(backupDir, fmt.Sprintf("agent-%s.key", timestamp)))
	}

	// Write new certificates atomically
	if err := r.writeFileAtomic(r.config.TLS.CertFile, certData, 0644); err != nil {
		return fmt.Errorf("failed to write certificate: %w", err)
	}

	if err := r.writeFileAtomic(r.config.TLS.KeyFile, keyData, 0600); err != nil {
		return fmt.Errorf("failed to write key: %w", err)
	}

	if err := r.writeFileAtomic(r.config.TLS.CAFile, caData, 0644); err != nil {
		return fmt.Errorf("failed to write CA: %w", err)
	}

	log.Printf("[CERT] New certificate expires at %s", renewal.ExpiresAt)

	return nil
}

// backupFile creates a backup copy of a file
func (r *Renewer) backupFile(src, dst string) {
	data, err := os.ReadFile(src)
	if err != nil {
		log.Printf("[CERT] Warning: failed to backup %s: %v", src, err)
		return
	}

	if err := os.WriteFile(dst, data, 0600); err != nil {
		log.Printf("[CERT] Warning: failed to write backup %s: %v", dst, err)
	}
}

// writeFileAtomic writes a file atomically by writing to a temp file first
func (r *Renewer) writeFileAtomic(path string, data []byte, perm os.FileMode) error {
	dir := filepath.Dir(path)
	tmpFile, err := os.CreateTemp(dir, ".tmp-")
	if err != nil {
		return fmt.Errorf("failed to create temp file: %w", err)
	}
	tmpPath := tmpFile.Name()

	// Clean up temp file on error
	defer func() {
		if tmpPath != "" {
			os.Remove(tmpPath)
		}
	}()

	if _, err := tmpFile.Write(data); err != nil {
		tmpFile.Close()
		return fmt.Errorf("failed to write temp file: %w", err)
	}

	if err := tmpFile.Close(); err != nil {
		return fmt.Errorf("failed to close temp file: %w", err)
	}

	if err := os.Chmod(tmpPath, perm); err != nil {
		return fmt.Errorf("failed to set permissions: %w", err)
	}

	// Atomic rename
	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("failed to rename temp file: %w", err)
	}

	tmpPath = "" // Prevent cleanup
	return nil
}

// NeedsRenewal checks if certificate needs renewal (for external use)
func (r *Renewer) NeedsRenewal() bool {
	if !r.config.UseTLS() {
		return false
	}

	expiry, err := r.getCertificateExpiry()
	if err != nil {
		return false
	}

	return time.Until(expiry) <= RenewalThreshold
}

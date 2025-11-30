package api

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/phpborg/phpborg-agent/internal/config"
)

// Client handles communication with phpBorg API
type Client struct {
	config     *config.Config
	httpClient *http.Client
	baseURL    string
}

// NewClient creates a new API client with mTLS or simple HTTP
func NewClient(cfg *config.Config) (*Client, error) {
	var transport *http.Transport

	// Check if mTLS is configured
	if cfg.UseTLS() {
		// Load client certificate for mTLS authentication
		cert, err := tls.LoadX509KeyPair(cfg.TLS.CertFile, cfg.TLS.KeyFile)
		if err != nil {
			return nil, fmt.Errorf("failed to load client certificate: %w", err)
		}

		// Note: We don't set RootCAs here because:
		// - RootCAs is for verifying the SERVER's SSL certificate
		// - We want to use system CA trust store (for Let's Encrypt, Sectigo, etc.)
		// - The mTLS CA is only for the SERVER to verify our CLIENT certificate
		// - The server-side uses our CA to verify agent certs, not the other way around

		// Configure TLS with client certs (mTLS) but use system CAs for server verification
		tlsConfig := &tls.Config{
			Certificates:       []tls.Certificate{cert},
			InsecureSkipVerify: cfg.Server.InsecureSkipVerify,
			// RootCAs: nil = use system CA trust store
		}

		transport = &http.Transport{
			TLSClientConfig: tlsConfig,
		}
	} else {
		// Simple transport without mTLS (uses Bearer token auth)
		tlsConfig := &tls.Config{
			InsecureSkipVerify: cfg.Server.InsecureSkipVerify,
		}
		transport = &http.Transport{
			TLSClientConfig: tlsConfig,
		}
	}

	return &Client{
		config: cfg,
		httpClient: &http.Client{
			Transport: transport,
			Timeout:   30 * time.Second,
		},
		baseURL: cfg.Server.URL,
	}, nil
}

// APIResponse represents a standard API response
type APIResponse struct {
	Success bool            `json:"success"`
	Message string          `json:"message"`
	Data    json.RawMessage `json:"data"`
	Error   *APIError       `json:"error,omitempty"`
}

// APIError represents an API error
type APIError struct {
	Message string `json:"message"`
	Code    string `json:"code"`
}

// Task represents a task from the API
type Task struct {
	ID             int                    `json:"id"`
	Type           string                 `json:"type"`
	Priority       string                 `json:"priority"`
	Payload        map[string]interface{} `json:"payload"`
	TimeoutSeconds int                    `json:"timeout_seconds"`
	CreatedAt      string                 `json:"created_at"`
}

// TasksResponse represents the response from GET /agent/tasks
type TasksResponse struct {
	Tasks []Task `json:"tasks"`
	Count int    `json:"count"`
}

// HeartbeatResponse represents the response from POST /agent/heartbeat
type HeartbeatResponse struct {
	ServerTime        string `json:"server_time"`
	NextHeartbeatIn   int    `json:"next_heartbeat_in"`
}

// doRequest performs an HTTP request with mTLS
func (c *Client) doRequest(ctx context.Context, method, path string, body interface{}) (*APIResponse, error) {
	var bodyReader io.Reader
	if body != nil {
		jsonBody, err := json.Marshal(body)
		if err != nil {
			return nil, fmt.Errorf("failed to marshal request body: %w", err)
		}
		bodyReader = bytes.NewReader(jsonBody)
	}

	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, bodyReader)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", "phpborg-agent/1.0")

	// For development/testing, use UUID as bearer token
	req.Header.Set("Authorization", "Bearer "+c.config.Agent.UUID)

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return nil, fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response body: %w", err)
	}

	var apiResp APIResponse
	if err := json.Unmarshal(respBody, &apiResp); err != nil {
		return nil, fmt.Errorf("failed to parse response: %w (body: %s)", err, string(respBody))
	}

	if !apiResp.Success {
		errMsg := "unknown error"
		if apiResp.Error != nil {
			errMsg = apiResp.Error.Message
		}
		return nil, fmt.Errorf("API error: %s", errMsg)
	}

	return &apiResp, nil
}

// SendHeartbeat sends a heartbeat to the server
func (c *Client) SendHeartbeat(ctx context.Context, version string, capabilities map[string]interface{}, osInfo string) (*HeartbeatResponse, error) {
	body := map[string]interface{}{
		"version": version,
	}
	if capabilities != nil {
		body["capabilities"] = capabilities
	}
	if osInfo != "" {
		body["os_info"] = osInfo
	}

	resp, err := c.doRequest(ctx, "POST", "/agent/heartbeat", body)
	if err != nil {
		return nil, err
	}

	var heartbeat HeartbeatResponse
	if err := json.Unmarshal(resp.Data, &heartbeat); err != nil {
		return nil, fmt.Errorf("failed to parse heartbeat response: %w", err)
	}

	return &heartbeat, nil
}

// GetTasks fetches pending tasks from the server
func (c *Client) GetTasks(ctx context.Context) (*TasksResponse, error) {
	resp, err := c.doRequest(ctx, "GET", "/agent/tasks", nil)
	if err != nil {
		return nil, err
	}

	var tasks TasksResponse
	if err := json.Unmarshal(resp.Data, &tasks); err != nil {
		return nil, fmt.Errorf("failed to parse tasks response: %w", err)
	}

	return &tasks, nil
}

// StartTask marks a task as started
func (c *Client) StartTask(ctx context.Context, taskID int) error {
	_, err := c.doRequest(ctx, "POST", fmt.Sprintf("/agent/tasks/%d/start", taskID), nil)
	return err
}

// UpdateProgress updates task progress with a simple message
func (c *Client) UpdateProgress(ctx context.Context, taskID int, progress int, message string) error {
	body := map[string]interface{}{
		"progress": progress,
	}
	if message != "" {
		body["message"] = message
	}

	_, err := c.doRequest(ctx, "POST", fmt.Sprintf("/agent/tasks/%d/progress", taskID), body)
	return err
}

// ProgressInfo contains detailed progress information for borg backup
type ProgressInfo struct {
	FilesCount       int64  `json:"files_count"`
	OriginalSize     int64  `json:"original_size"`
	CompressedSize   int64  `json:"compressed_size"`
	DeduplicatedSize int64  `json:"deduplicated_size"`
	CurrentPath      string `json:"current_path,omitempty"`
	Message          string `json:"message,omitempty"`
}

// UpdateProgressWithInfo updates task progress with detailed borg statistics
func (c *Client) UpdateProgressWithInfo(ctx context.Context, taskID int, progress int, info ProgressInfo) error {
	body := map[string]interface{}{
		"progress":          progress,
		"files_count":       info.FilesCount,
		"original_size":     info.OriginalSize,
		"compressed_size":   info.CompressedSize,
		"deduplicated_size": info.DeduplicatedSize,
	}
	if info.CurrentPath != "" {
		body["current_path"] = info.CurrentPath
	}
	if info.Message != "" {
		body["message"] = info.Message
	}

	_, err := c.doRequest(ctx, "POST", fmt.Sprintf("/agent/tasks/%d/progress", taskID), body)
	return err
}

// CompleteTask marks a task as completed
func (c *Client) CompleteTask(ctx context.Context, taskID int, result map[string]interface{}, exitCode int) error {
	body := map[string]interface{}{
		"exit_code": exitCode,
	}
	if result != nil {
		body["result"] = result
	}

	_, err := c.doRequest(ctx, "POST", fmt.Sprintf("/agent/tasks/%d/complete", taskID), body)
	return err
}

// FailTask marks a task as failed
func (c *Client) FailTask(ctx context.Context, taskID int, errorMsg string, exitCode int) error {
	body := map[string]interface{}{
		"error":     errorMsg,
		"exit_code": exitCode,
	}

	_, err := c.doRequest(ctx, "POST", fmt.Sprintf("/agent/tasks/%d/fail", taskID), body)
	return err
}

// TaskStatusResponse represents the response from GET /agent/tasks/{id}/status
type TaskStatusResponse struct {
	Status       string `json:"status"`
	ShouldCancel bool   `json:"should_cancel"`
}

// GetTaskStatus checks if a task has been cancelled
func (c *Client) GetTaskStatus(ctx context.Context, taskID int) (*TaskStatusResponse, error) {
	resp, err := c.doRequest(ctx, "GET", fmt.Sprintf("/agent/tasks/%d/status", taskID), nil)
	if err != nil {
		return nil, err
	}

	var status TaskStatusResponse
	if err := json.Unmarshal(resp.Data, &status); err != nil {
		return nil, fmt.Errorf("failed to parse task status response: %w", err)
	}

	return &status, nil
}

// UpdateInfo contains information about an available update
type UpdateInfo struct {
	Available      bool   `json:"available"`
	CurrentVersion string `json:"current_version"`
	LatestVersion  string `json:"latest_version"`
	DownloadURL    string `json:"download_url"`
	Checksum       string `json:"checksum"`
	ReleaseNotes   string `json:"release_notes,omitempty"`
}

// CheckUpdate checks if an update is available
func (c *Client) CheckUpdate(ctx context.Context, currentVersion string) (*UpdateInfo, error) {
	body := map[string]interface{}{
		"current_version": currentVersion,
	}

	resp, err := c.doRequest(ctx, "POST", "/agent/update/check", body)
	if err != nil {
		return nil, err
	}

	var info UpdateInfo
	if err := json.Unmarshal(resp.Data, &info); err != nil {
		return nil, fmt.Errorf("failed to parse update info: %w", err)
	}

	return &info, nil
}

// DownloadUpdate downloads the agent binary to a temporary file
// Returns the path to the downloaded file
func (c *Client) DownloadUpdate(ctx context.Context, destPath string) error {
	req, err := http.NewRequestWithContext(ctx, "GET", c.baseURL+"/agent/update/download", nil)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Authorization", "Bearer "+c.config.Agent.UUID)
	req.Header.Set("User-Agent", "phpborg-agent/1.0")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("download request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("download failed with status: %d", resp.StatusCode)
	}

	// Create destination file
	out, err := os.Create(destPath)
	if err != nil {
		return fmt.Errorf("failed to create destination file: %w", err)
	}
	defer out.Close()

	// Copy data
	_, err = io.Copy(out, resp.Body)
	if err != nil {
		return fmt.Errorf("failed to write file: %w", err)
	}

	return nil
}

// CertificateRenewalResponse contains new certificates from renewal
type CertificateRenewalResponse struct {
	Cert      string `json:"cert"`       // Base64 encoded certificate
	Key       string `json:"key"`        // Base64 encoded private key
	CA        string `json:"ca"`         // Base64 encoded CA certificate
	ExpiresAt string `json:"expires_at"` // Expiration date
}

// RenewCertificate requests a new certificate from the server
func (c *Client) RenewCertificate(ctx context.Context) (*CertificateRenewalResponse, error) {
	body := map[string]interface{}{
		"agent_uuid": c.config.Agent.UUID,
		"agent_name": c.config.Agent.Name,
	}

	resp, err := c.doRequest(ctx, "POST", "/agent/certificate/renew", body)
	if err != nil {
		return nil, err
	}

	var renewal CertificateRenewalResponse
	if err := json.Unmarshal(resp.Data, &renewal); err != nil {
		return nil, fmt.Errorf("failed to parse renewal response: %w", err)
	}

	return &renewal, nil
}

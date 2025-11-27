package api

import (
	"bytes"
	"context"
	"crypto/tls"
	"crypto/x509"
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

// NewClient creates a new API client with mTLS
func NewClient(cfg *config.Config) (*Client, error) {
	// Load client certificate
	cert, err := tls.LoadX509KeyPair(cfg.TLS.CertFile, cfg.TLS.KeyFile)
	if err != nil {
		return nil, fmt.Errorf("failed to load client certificate: %w", err)
	}

	// Load CA certificate
	caCert, err := os.ReadFile(cfg.TLS.CAFile)
	if err != nil {
		return nil, fmt.Errorf("failed to load CA certificate: %w", err)
	}

	caCertPool := x509.NewCertPool()
	if !caCertPool.AppendCertsFromPEM(caCert) {
		return nil, fmt.Errorf("failed to parse CA certificate")
	}

	// Configure TLS
	tlsConfig := &tls.Config{
		Certificates:       []tls.Certificate{cert},
		RootCAs:            caCertPool,
		InsecureSkipVerify: cfg.Server.InsecureSkipVerify,
	}

	transport := &http.Transport{
		TLSClientConfig: tlsConfig,
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

// UpdateProgress updates task progress
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

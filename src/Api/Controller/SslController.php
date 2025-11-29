<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\Queue\JobQueue;

/**
 * SSL/TLS Certificate Management API Controller
 *
 * All operations that require root privileges are dispatched as jobs
 * to be processed by the worker (which runs with appropriate privileges)
 */
final class SslController extends BaseController
{
    private SettingRepository $settingRepository;
    private JobQueue $jobQueue;

    public function __construct(Application $app)
    {
        $this->settingRepository = new SettingRepository($app->getConnection());
        $this->jobQueue = $app->getJobQueue();
    }

    /**
     * Get SSL status and certificate info
     * GET /api/ssl/status
     */
    public function getStatus(): void
    {
        try {
            $status = [
                'enabled' => $this->getSetting('ssl.enabled') === 'true',
                'type' => $this->getSetting('ssl.type') ?? 'none',
                'domain' => $this->getSetting('ssl.domain'),
                'issuer' => null,
                'valid_from' => null,
                'valid_to' => null,
                'days_remaining' => null,
                'auto_renew' => false,
                'cloudflare_configured' => $this->getSetting('ssl.cloudflare_configured') === 'true',
            ];

            // Read certificate info if exists
            $certFile = '/etc/phpborg/ssl/cert.pem';
            if (file_exists($certFile)) {
                $certContent = file_get_contents($certFile);
                $certInfo = openssl_x509_parse($certContent);

                if ($certInfo) {
                    $status['issuer'] = $certInfo['issuer']['CN'] ?? $certInfo['issuer']['O'] ?? 'Unknown';
                    $status['valid_from'] = date('Y-m-d H:i:s', $certInfo['validFrom_time_t']);
                    $status['valid_to'] = date('Y-m-d H:i:s', $certInfo['validTo_time_t']);
                    $status['days_remaining'] = (int) (($certInfo['validTo_time_t'] - time()) / 86400);
                    $status['auto_renew'] = file_exists('/etc/cron.d/phpborg-ssl-renew');
                }
            }

            $this->success($status);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validate certificate without applying (dispatches job)
     * POST /api/ssl/validate
     * Body: { cert: string, key: string, chain?: string }
     */
    public function validateCertificate(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['cert'])) {
            $this->error('Certificate is required', 400);
            return;
        }

        if (empty($data['key'])) {
            $this->error('Private key is required', 400);
            return;
        }

        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'validate',
                    'cert' => $data['cert'],
                    'key' => $data['key'],
                    'chain' => $data['chain'] ?? null,
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Validation job started'
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Generate self-signed certificate (dispatches job)
     * POST /api/ssl/self-signed
     * Body: { domain: string, days?: int }
     */
    public function generateSelfSigned(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['domain'])) {
            $this->error('Domain is required', 400);
            return;
        }

        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'self_signed',
                    'domain' => $data['domain'],
                    'days' => (int) ($data['days'] ?? 365),
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Self-signed certificate generation started'
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Request Let's Encrypt certificate via HTTP-01 (dispatches job)
     * POST /api/ssl/letsencrypt/http
     * Body: { domain: string, email: string }
     */
    public function requestLetsEncryptHttp(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['domain'])) {
            $this->error('Domain is required', 400);
            return;
        }

        if (empty($data['email'])) {
            $this->error('Email is required', 400);
            return;
        }

        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'letsencrypt_http',
                    'domain' => $data['domain'],
                    'email' => $data['email'],
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => "Let's Encrypt HTTP-01 certificate request started"
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Request Let's Encrypt certificate via DNS-01 with Cloudflare (dispatches job)
     * POST /api/ssl/letsencrypt/cloudflare
     * Body: { domain: string, email: string, cloudflare_token: string }
     */
    public function requestLetsEncryptCloudflare(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['domain'])) {
            $this->error('Domain is required', 400);
            return;
        }

        if (empty($data['email'])) {
            $this->error('Email is required', 400);
            return;
        }

        if (empty($data['cloudflare_token'])) {
            $this->error('Cloudflare API token is required', 400);
            return;
        }

        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'letsencrypt_dns',
                    'domain' => $data['domain'],
                    'email' => $data['email'],
                    'cloudflare_token' => $data['cloudflare_token'],
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => "Let's Encrypt DNS-01 certificate request started"
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Upload custom certificate (dispatches job)
     * POST /api/ssl/upload
     * Body: { cert: string, key: string, chain?: string, domain?: string }
     */
    public function uploadCertificate(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['cert'])) {
            $this->error('Certificate is required', 400);
            return;
        }

        if (empty($data['key'])) {
            $this->error('Private key is required', 400);
            return;
        }

        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'apply',
                    'cert' => $data['cert'],
                    'key' => $data['key'],
                    'chain' => $data['chain'] ?? null,
                    'domain' => $data['domain'] ?? null,
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Certificate upload started'
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Disable SSL (revert to HTTP) - dispatches job
     * POST /api/ssl/disable
     */
    public function disable(): void
    {
        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'disable',
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'SSL disable started'
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Renew Let's Encrypt certificate (dispatches job)
     * POST /api/ssl/renew
     */
    public function renewCertificate(): void
    {
        try {
            $jobId = $this->jobQueue->push(
                type: 'ssl_certificate',
                payload: [
                    'action' => 'renew',
                ],
                queue: 'default',
                maxAttempts: 1
            );

            $this->success([
                'job_id' => $jobId,
                'message' => 'Certificate renewal started'
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Test renewal (dry-run)
     * POST /api/ssl/test-renewal
     */
    public function testRenewal(): void
    {
        try {
            // This is a read-only operation, can run directly
            $type = $this->getSetting('ssl.type');

            if ($type !== 'letsencrypt') {
                $this->error("Renewal test is only available for Let's Encrypt certificates", 400);
                return;
            }

            exec('sudo certbot renew --cert-name phpborg --dry-run 2>&1', $output, $exitCode);
            $outputStr = implode("\n", $output);

            if ($exitCode !== 0) {
                $this->success([
                    'success' => false,
                    'error' => 'Dry-run failed',
                    'output' => $outputStr,
                ]);
                return;
            }

            $this->success([
                'success' => true,
                'output' => $outputStr,
            ]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Check auto-renewal status
     * GET /api/ssl/auto-renewal-status
     */
    public function getAutoRenewalStatus(): void
    {
        try {
            $status = [
                'enabled' => false,
                'cron_exists' => file_exists('/etc/cron.d/phpborg-ssl-renew'),
                'certbot_timer_active' => false,
                'next_renewal' => null,
                'last_renewal_check' => null,
            ];

            // Check certbot systemd timer
            exec('sudo systemctl is-active certbot.timer 2>&1', $output, $exitCode);
            $status['certbot_timer_active'] = ($exitCode === 0);

            // Get next renewal date
            exec('sudo certbot certificates --cert-name phpborg 2>&1', $output, $exitCode);
            $outputStr = implode("\n", $output);

            if (preg_match('/Expiry Date:\s*(\d{4}-\d{2}-\d{2})/', $outputStr, $matches)) {
                $expiryDate = new \DateTime($matches[1]);
                $renewDate = clone $expiryDate;
                $renewDate->modify('-30 days');
                $status['next_renewal'] = $renewDate->format('Y-m-d');
            }

            $status['enabled'] = $status['cron_exists'] || $status['certbot_timer_active'];

            $this->success($status);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Ensure auto-renewal is configured
     * POST /api/ssl/ensure-auto-renewal
     */
    public function ensureAutoRenewal(): void
    {
        try {
            $cronContent = "# phpBorg SSL auto-renewal\n";
            $cronContent .= "0 3 * * * root certbot renew --cert-name phpborg --quiet --deploy-hook \"systemctl reload nginx\"\n";

            file_put_contents('/etc/cron.d/phpborg-ssl-renew', $cronContent);
            chmod('/etc/cron.d/phpborg-ssl-renew', 0644);

            $this->success(['message' => 'Auto-renewal configured']);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Test Cloudflare API token
     * POST /api/ssl/cloudflare/test
     * Body: { token: string }
     */
    public function testCloudflareToken(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['token'])) {
            $this->error('Token is required', 400);
            return;
        }

        try {
            $ch = curl_init('https://api.cloudflare.com/client/v4/user/tokens/verify');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$data['token']}",
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($httpCode === 200 && ($result['success'] ?? false)) {
                $this->success([
                    'valid' => true,
                    'status' => $result['result']['status'] ?? 'unknown',
                ]);
            } else {
                $this->success([
                    'valid' => false,
                    'error' => $result['errors'][0]['message'] ?? 'Unknown error',
                ]);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get Cloudflare zones (domains)
     * POST /api/ssl/cloudflare/zones
     * Body: { token: string }
     */
    public function getCloudflareZones(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['token'])) {
            $this->error('Token is required', 400);
            return;
        }

        try {
            $ch = curl_init('https://api.cloudflare.com/client/v4/zones?per_page=50');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$data['token']}",
                    'Content-Type: application/json',
                ],
            ]);

            $response = curl_exec($ch);
            curl_close($ch);

            $result = json_decode($response, true);

            if ($result['success'] ?? false) {
                $zones = array_map(fn($zone) => [
                    'id' => $zone['id'],
                    'name' => $zone['name'],
                    'status' => $zone['status'],
                ], $result['result'] ?? []);

                $this->success(['zones' => $zones]);
            } else {
                $this->error($result['errors'][0]['message'] ?? 'Failed to get zones', 400);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get current domain configuration
     * GET /api/ssl/domain
     */
    public function getDomain(): void
    {
        try {
            $domain = $this->getSetting('ssl.domain');
            $this->success(['domain' => $domain]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Set domain configuration
     * POST /api/ssl/domain
     * Body: { domain: string }
     */
    public function setDomain(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['domain'])) {
            $this->error('Domain is required', 400);
            return;
        }

        try {
            $this->saveSetting('ssl.domain', $data['domain']);
            $this->success(['domain' => $data['domain']]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Get DNS challenge instructions for manual DNS-01 validation
     * POST /api/ssl/letsencrypt/dns-challenge
     * Body: { domain: string, email: string }
     */
    public function getDnsChallenge(): void
    {
        $data = $this->getJsonBody();

        if (empty($data['domain'])) {
            $this->error('Domain is required', 400);
            return;
        }

        if (empty($data['email'])) {
            $this->error('Email is required', 400);
            return;
        }

        try {
            // Use certbot to get DNS challenge (manual mode, non-interactive)
            $domain = escapeshellarg($data['domain']);
            $email = escapeshellarg($data['email']);

            // This just outputs the challenge info without completing
            $cmd = "sudo certbot certonly --manual --preferred-challenges dns -d {$domain} --email {$email} --agree-tos --non-interactive --dry-run 2>&1";
            exec($cmd, $output, $exitCode);

            $outputStr = implode("\n", $output);

            // Extract TXT record info
            if (preg_match('/_acme-challenge\.([^\s]+).*?with the following value:\s*([^\s]+)/s', $outputStr, $matches)) {
                $this->success([
                    'record_name' => '_acme-challenge.' . $data['domain'],
                    'record_type' => 'TXT',
                    'record_value' => $matches[2],
                    'instructions' => "Add a TXT record to your DNS with the name '_acme-challenge.{$data['domain']}' and the value shown above. Wait for DNS propagation (usually 1-5 minutes) then proceed.",
                ]);
            } else {
                $this->success([
                    'record_name' => '_acme-challenge.' . $data['domain'],
                    'record_type' => 'TXT',
                    'record_value' => null,
                    'instructions' => "Unable to get challenge token. Make sure your domain is publicly accessible.",
                    'output' => $outputStr,
                ]);
            }
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    // Helper methods

    private function getSetting(string $key): ?string
    {
        $setting = $this->settingRepository->findByKey($key);
        return $setting?->value;
    }

    private function saveSetting(string $key, string $value): void
    {
        $existing = $this->settingRepository->findByKey($key);
        if ($existing) {
            $this->settingRepository->updateValue($key, $value);
        } else {
            $this->settingRepository->create($key, $value, 'ssl', 'string', "SSL setting: {$key}");
        }
    }
}

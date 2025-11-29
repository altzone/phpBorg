<?php

declare(strict_types=1);

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Service\SslService;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

/**
 * SSL/TLS Certificate Management API Controller
 */
final class SslController extends BaseController
{
    private SslService $sslService;

    public function __construct(Application $app)
    {
        $settingRepository = new SettingRepository($app->getConnection());

        // Create logger
        $logger = new Logger('ssl');
        $logPathSetting = $settingRepository->findByKey('log_path');
        $logPath = $logPathSetting?->value ?? '/var/log/phpborg/app.log';
        $logger->pushHandler(new StreamHandler($logPath, Logger::INFO));

        $this->sslService = new SslService($settingRepository, $logger);
    }

    /**
     * Get SSL status and certificate info
     * GET /api/ssl/status
     */
    public function getStatus(): void
    {
        try {
            $status = $this->sslService->getStatus();
            $this->success($status);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Generate self-signed certificate
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

        $days = (int) ($data['days'] ?? 365);

        try {
            $result = $this->sslService->generateSelfSigned($data['domain'], $days);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Request Let's Encrypt certificate via HTTP-01
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
            $result = $this->sslService->requestLetsEncryptHttp($data['domain'], $data['email']);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Request Let's Encrypt certificate via DNS-01 with Cloudflare
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
            $result = $this->sslService->requestLetsEncryptDnsCloudflare(
                $data['domain'],
                $data['email'],
                $data['cloudflare_token']
            );
            $this->success($result);
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
            $result = $this->sslService->getDnsChallenge($data['domain'], $data['email']);
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Validate certificate without applying
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
            $result = $this->sslService->validateCertificate(
                $data['cert'],
                $data['key'],
                $data['chain'] ?? null
            );
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Upload custom certificate
     * POST /api/ssl/upload
     * Body: { cert: string, key: string, chain?: string }
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
            $result = $this->sslService->uploadCertificate(
                $data['cert'],
                $data['key'],
                $data['chain'] ?? null
            );
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Disable SSL (revert to HTTP)
     * POST /api/ssl/disable
     */
    public function disable(): void
    {
        try {
            $result = $this->sslService->disable();
            $this->success($result);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }

    /**
     * Renew Let's Encrypt certificate
     * POST /api/ssl/renew
     */
    public function renewCertificate(): void
    {
        try {
            $result = $this->sslService->renewCertificate();
            $this->success($result);
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
            $result = $this->sslService->testRenewal();
            $this->success($result);
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
            $result = $this->sslService->checkAutoRenewalStatus();
            $this->success($result);
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
            $result = $this->sslService->ensureAutoRenewal();
            $this->success($result);
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
            $result = $this->sslService->testCloudflareToken($data['token']);
            $this->success($result);
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
            $zones = $this->sslService->getCloudflareZones($data['token']);
            $this->success(['zones' => $zones]);
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
            $domain = $this->sslService->getDomain();
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
            $this->sslService->setDomain($data['domain']);
            $this->success(['domain' => $data['domain']]);
        } catch (\Exception $e) {
            $this->error($e->getMessage(), 500);
        }
    }
}

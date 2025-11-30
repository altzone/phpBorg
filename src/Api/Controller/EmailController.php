<?php

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Exception\PhpBorgException;
use PhpBorg\Logger\LoggerInterface;
use PhpBorg\Service\Repository\EncryptionService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailController extends BaseController
{
    private readonly SettingRepository $settingRepository;
    private readonly LoggerInterface $logger;
    private readonly EncryptionService $encryptionService;

    public function __construct(Application $app)
    {
        $this->settingRepository = new SettingRepository($app->getConnection());
        $this->logger = $app->getLogger();
        $this->encryptionService = new EncryptionService($app->getConfig());
    }

    /**
     * Send test email
     * POST /api/email/test
     * Body: { "to": "email@example.com" }
     */
    public function sendTest(): void
    {
        try {
            // Check authentication
            $currentUser = $_SERVER['USER'] ?? null;
            if (!$currentUser) {
                $this->error('Authentication required', 401, 'UNAUTHORIZED');
                return;
            }

            // Get email address from request
            $data = $this->getJsonBody();
            $toEmail = $data['to'] ?? null;

            if (!$toEmail) {
                $this->error('Email address is required', 400, 'MISSING_EMAIL');
                return;
            }

            // Validate email
            if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
                $this->error('Invalid email address', 400, 'INVALID_EMAIL');
                return;
            }

            // Check if inline SMTP config is provided (for setup wizard testing before save)
            $smtpSettings = [];
            if (!empty($data['smtp_config'])) {
                $config = $data['smtp_config'];
                $smtpSettings = [
                    'smtp.host' => $config['host'] ?? '',
                    'smtp.port' => $config['port'] ?? 587,
                    'smtp.username' => $config['username'] ?? '',
                    'smtp.password' => $config['password'] ?? '',
                    'smtp.encryption' => $config['encryption'] ?? 'tls',
                    'smtp.from_email' => $config['from_email'] ?? '',
                    'smtp.from_name' => $config['from_name'] ?? 'phpBorg',
                ];
            } else {
                // Get SMTP settings from database
                $settings = $this->settingRepository->findByCategory('smtp');
                foreach ($settings as $setting) {
                    $smtpSettings[$setting->key] = $setting->getTypedValue();
                }
            }

            // Validate required settings
            if (empty($smtpSettings['smtp.host']) || empty($smtpSettings['smtp.port'])) {
                $this->error('SMTP configuration is incomplete. Please configure SMTP host and port first.', 400, 'INCOMPLETE_SMTP_CONFIG');
                return;
            }

            // Log SMTP configuration (without password)
            $this->logger->info("Attempting to send test email", 'EMAIL', [
                'to' => $toEmail,
                'host' => $smtpSettings['smtp.host'] ?? 'not set',
                'port' => $smtpSettings['smtp.port'] ?? 'not set',
                'encryption' => $smtpSettings['smtp.encryption'] ?? 'none',
                'username' => $smtpSettings['smtp.username'] ?? 'not set',
                'from_email' => $smtpSettings['smtp.from_email'] ?? 'not set',
            ]);

            // Create PHPMailer instance
            $mail = new PHPMailer(true);

            // Capture debug output
            $debugOutput = '';

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = $smtpSettings['smtp.host'];
                $mail->Port = (int)$smtpSettings['smtp.port'];

                // Authentication (only if username and password provided)
                if (!empty($smtpSettings['smtp.username']) && !empty($smtpSettings['smtp.password'])) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpSettings['smtp.username'];
                    // Decrypt password if encrypted
                    $password = $this->encryptionService->decrypt($smtpSettings['smtp.password']);
                    $mail->Password = $password;
                    $this->logger->info("SMTP Auth enabled", 'EMAIL', ['username' => $smtpSettings['smtp.username']]);
                } else {
                    $mail->SMTPAuth = false;
                    $this->logger->info("SMTP Auth disabled (no credentials)", 'EMAIL');
                }

                // Encryption
                $encryption = strtolower($smtpSettings['smtp.encryption'] ?? 'none');
                if ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                    $this->logger->info("Using SSL/SMTPS encryption", 'EMAIL');
                } elseif ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                    $this->logger->info("Using TLS/STARTTLS encryption", 'EMAIL');
                } else {
                    $this->logger->info("No encryption", 'EMAIL');
                }

                // Disable SSL certificate verification for self-signed certs
                // This is common in internal mail servers
                $mail->SMTPOptions = [
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ];

                // Enable debug output capture
                $mail->SMTPDebug = 2;
                $mail->Debugoutput = function($str, $level) use (&$debugOutput) {
                    $debugOutput .= $str;
                };

                // Timeout settings
                $mail->Timeout = 30;

                // Recipients
                $fromEmail = $smtpSettings['smtp.from_email'] ?? $smtpSettings['smtp.username'] ?? 'noreply@phpborg.local';
                $fromName = $smtpSettings['smtp.from_name'] ?? 'phpBorg';

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail);

                // Content
                $mail->isHTML(true);
                $mail->CharSet = 'UTF-8';
                $mail->Subject = 'phpBorg - Test Email';
                $mail->Body = $this->getTestEmailBody($fromEmail);
                $mail->AltBody = $this->getTestEmailBodyPlain($fromEmail);

                // Send email
                $mail->send();

                $this->logger->info("Test email sent successfully", 'EMAIL', ['to' => $toEmail]);

                $this->success(
                    ['recipient' => $toEmail],
                    "Test email sent successfully to {$toEmail}"
                );

            } catch (Exception $e) {
                $this->logger->error("Failed to send test email", 'EMAIL', [
                    'error' => $mail->ErrorInfo,
                    'debug' => $debugOutput
                ]);
                $this->error('Failed to send email: ' . $mail->ErrorInfo, 500, 'EMAIL_SEND_FAILED');
            }

        } catch (PhpBorgException $e) {
            $this->error($e->getMessage(), 500, 'EMAIL_TEST_ERROR');
        } catch (\Exception $e) {
            $this->error('An error occurred: ' . $e->getMessage(), 500, 'EMAIL_TEST_ERROR');
        }
    }

    private function getTestEmailBody(string $fromEmail): string
    {
        $date = date('Y-m-d H:i:s');
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background-color: #3B82F6; color: white; padding: 20px; text-align: center; }
        .content { background-color: #f9f9f9; padding: 20px; border: 1px solid #ddd; }
        .footer { text-align: center; padding: 20px; font-size: 12px; color: #666; }
        .success { color: #10B981; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>phpBorg 2.0</h1>
        </div>
        <div class="content">
            <h2 class="success">&#x2713; Test Email Successful</h2>
            <p>This is a test email from your phpBorg backup system.</p>
            <p><strong>Configuration Details:</strong></p>
            <ul>
                <li><strong>From:</strong> {$fromEmail}</li>
                <li><strong>Sent at:</strong> {$date}</li>
                <li><strong>Status:</strong> SMTP configuration is working correctly</li>
            </ul>
            <p>If you received this email, your SMTP settings are configured properly and phpBorg will be able to send backup notifications.</p>
        </div>
        <div class="footer">
            <p>This is an automated test email from phpBorg 2.0</p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    private function getTestEmailBodyPlain(string $fromEmail): string
    {
        $date = date('Y-m-d H:i:s');
        return <<<TEXT
phpBorg 2.0 - Test Email

[SUCCESS] Test Email Successful

This is a test email from your phpBorg backup system.

Configuration Details:
- From: {$fromEmail}
- Sent at: {$date}
- Status: SMTP configuration is working correctly

If you received this email, your SMTP settings are configured properly and phpBorg will be able to send backup notifications.

---
This is an automated test email from phpBorg 2.0
TEXT;
    }
}

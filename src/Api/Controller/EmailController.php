<?php

namespace PhpBorg\Api\Controller;

use PhpBorg\Application;
use PhpBorg\Repository\SettingRepository;
use PhpBorg\Exception\PhpBorgException;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailController extends BaseController
{
    private readonly SettingRepository $settingRepository;

    public function __construct(Application $app)
    {
        $this->settingRepository = new SettingRepository($app->getConnection());
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

            // Get SMTP settings
            $settings = $this->settingRepository->findByCategory('email');
            $smtpSettings = [];
            foreach ($settings as $setting) {
                $smtpSettings[$setting->key] = $setting->getTypedValue();
            }

            // Validate required settings
            if (empty($smtpSettings['smtp_host']) || empty($smtpSettings['smtp_port'])) {
                $this->error('SMTP configuration is incomplete. Please configure SMTP settings first.', 400, 'INCOMPLETE_SMTP_CONFIG');
                return;
            }

            // Create PHPMailer instance
            $mail = new PHPMailer(true);

            try {
                // Server settings
                $mail->isSMTP();
                $mail->Host = $smtpSettings['smtp_host'];
                $mail->Port = (int)$smtpSettings['smtp_port'];

                // Authentication
                if (!empty($smtpSettings['smtp_username']) && !empty($smtpSettings['smtp_password'])) {
                    $mail->SMTPAuth = true;
                    $mail->Username = $smtpSettings['smtp_username'];
                    $mail->Password = $smtpSettings['smtp_password'];
                } else {
                    $mail->SMTPAuth = false;
                }

                // Encryption
                $encryption = $smtpSettings['smtp_encryption'] ?? 'tls';
                if ($encryption === 'ssl') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                } elseif ($encryption === 'tls') {
                    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                }

                // Enable verbose debug output (for logging)
                $mail->SMTPDebug = 0; // Set to 2 for detailed debug

                // Recipients
                $fromEmail = $smtpSettings['smtp_from_email'] ?? $smtpSettings['smtp_username'] ?? 'noreply@phpborg.local';
                $fromName = $smtpSettings['smtp_from_name'] ?? 'phpBorg';

                $mail->setFrom($fromEmail, $fromName);
                $mail->addAddress($toEmail);

                // Content
                $mail->isHTML(true);
                $mail->Subject = 'phpBorg - Test Email';
                $mail->Body = $this->getTestEmailBody($fromEmail);
                $mail->AltBody = $this->getTestEmailBodyPlain($fromEmail);

                // Send email
                $mail->send();

                $this->success(
                    ['recipient' => $toEmail],
                    "Test email sent successfully to {$toEmail}"
                );

            } catch (Exception $e) {
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
            <h2 class="success">✓ Test Email Successful</h2>
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

✓ Test Email Successful

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

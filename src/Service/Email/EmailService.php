<?php

declare(strict_types=1);

namespace PhpBorg\Service\Email;

use PhpBorg\Repository\SettingRepository;
use PhpBorg\Logger\LoggerInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Email service for sending emails via SMTP
 */
final class EmailService
{
    private ?array $smtpSettings = null;

    public function __construct(
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send an email
     *
     * @param string $to Recipient email address
     * @param string $subject Email subject
     * @param string $htmlBody HTML body content
     * @param string|null $plainBody Plain text body content (optional)
     * @return bool Success status
     * @throws \Exception on failure
     */
    public function send(string $to, string $subject, string $htmlBody, ?string $plainBody = null): bool
    {
        // Load SMTP settings if not already loaded
        if ($this->smtpSettings === null) {
            $this->loadSmtpSettings();
        }

        // Validate email address
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \Exception("Invalid email address: {$to}");
        }

        // Validate required settings
        if (empty($this->smtpSettings['smtp.host']) || empty($this->smtpSettings['smtp.port'])) {
            $this->logger->error('SMTP configuration is incomplete. Cannot send email.', 'EMAIL');
            return false;
        }

        // Check if SMTP is enabled
        if (isset($this->smtpSettings['smtp.enabled']) && !$this->smtpSettings['smtp.enabled']) {
            $this->logger->warning('SMTP is disabled. Email not sent.', 'EMAIL');
            return false;
        }

        // Create PHPMailer instance
        $mail = new PHPMailer(true);

        try {
            // Server settings
            $mail->isSMTP();
            $mail->Host = $this->smtpSettings['smtp.host'];
            $mail->Port = (int)$this->smtpSettings['smtp.port'];

            // Authentication (only if username and password provided)
            if (!empty($this->smtpSettings['smtp.username']) && !empty($this->smtpSettings['smtp.password'])) {
                $mail->SMTPAuth = true;
                $mail->Username = $this->smtpSettings['smtp.username'];
                $mail->Password = $this->smtpSettings['smtp.password'];
            } else {
                $mail->SMTPAuth = false;
            }

            // Encryption
            $encryption = strtolower($this->smtpSettings['smtp.encryption'] ?? 'none');
            if ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }

            // Enable verbose debug output (for logging)
            $mail->SMTPDebug = 0;

            // Recipients
            $fromEmail = $this->smtpSettings['smtp.from_email'] ?? $this->smtpSettings['smtp.username'] ?? 'noreply@phpborg.local';
            $fromName = $this->smtpSettings['smtp.from_name'] ?? $this->getAppName();

            $mail->setFrom($fromEmail, $fromName);
            $mail->addAddress($to);

            // Content
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = $plainBody ?? strip_tags($htmlBody);

            // Send email
            $mail->send();

            $this->logger->info("Email sent successfully to {$to}: {$subject}", 'EMAIL');
            return true;

        } catch (Exception $e) {
            $this->logger->error("Failed to send email to {$to}: " . $mail->ErrorInfo, 'EMAIL');
            throw new \Exception("Failed to send email: " . $mail->ErrorInfo);
        }
    }

    /**
     * Load SMTP settings from database
     */
    private function loadSmtpSettings(): void
    {
        $settings = $this->settingRepository->findByCategory('email');
        $this->smtpSettings = [];

        foreach ($settings as $setting) {
            $this->smtpSettings[$setting->key] = $setting->getTypedValue();
        }
    }

    /**
     * Get application name from settings
     */
    private function getAppName(): string
    {
        $generalSettings = $this->settingRepository->findByCategory('general');

        foreach ($generalSettings as $setting) {
            if ($setting->key === 'app.name') {
                return $setting->value ?? 'phpBorg';
            }
        }

        return 'phpBorg';
    }

    /**
     * Get the configured FROM email address
     */
    public function getFromEmail(): string
    {
        if ($this->smtpSettings === null) {
            $this->loadSmtpSettings();
        }

        return $this->smtpSettings['smtp.from_email'] ?? $this->smtpSettings['smtp.username'] ?? 'noreply@phpborg.local';
    }
}

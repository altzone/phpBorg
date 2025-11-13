<?php

declare(strict_types=1);

namespace PhpBorg\Service\Email;

use PhpBorg\Repository\SettingRepository;
use PhpBorg\Repository\BackupJobRepository;
use PhpBorg\Logger\LoggerInterface;

/**
 * Service for sending backup notification emails
 */
final class BackupNotificationService
{
    public function __construct(
        private readonly EmailService $emailService,
        private readonly BackupJobRepository $backupJobRepository,
        private readonly SettingRepository $settingRepository,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send backup success notification
     *
     * @param int $backupJobId Backup job ID
     * @param string $serverName Server name
     * @param string $archiveName Archive name
     * @param array $stats Backup statistics (optional)
     */
    public function sendSuccessNotification(
        int $backupJobId,
        string $serverName,
        string $archiveName,
        array $stats = []
    ): void {
        $job = $this->backupJobRepository->findById($backupJobId);

        if (!$job) {
            $this->logger->warning("Backup job #{$backupJobId} not found. Cannot send notification.", 'EMAIL');
            return;
        }

        // Check if success notifications are enabled
        if (!$job->notifyOnSuccess) {
            $this->logger->debug("Success notifications disabled for job #{$backupJobId}", 'EMAIL');
            return;
        }

        // Get notification email (for now, use SMTP from email as recipient)
        // TODO: Add notification_email field to backup_jobs table
        $recipientEmail = $this->getNotificationEmail();

        if (!$recipientEmail) {
            $this->logger->warning("No notification email configured. Cannot send notification.", 'EMAIL');
            return;
        }

        $appName = $this->getAppName();
        $subject = "✓ [{$appName}] Backup Successful: {$serverName}";

        $htmlBody = $this->generateSuccessEmailHtml(
            $appName,
            $serverName,
            $job->name,
            $archiveName,
            $stats
        );

        $plainBody = $this->generateSuccessEmailPlain(
            $appName,
            $serverName,
            $job->name,
            $archiveName,
            $stats
        );

        try {
            $this->emailService->send($recipientEmail, $subject, $htmlBody, $plainBody);
            $this->logger->info("Backup success notification sent for job #{$backupJobId}", 'EMAIL');
        } catch (\Exception $e) {
            $this->logger->error("Failed to send backup success notification: " . $e->getMessage(), 'EMAIL');
        }
    }

    /**
     * Send backup failure notification
     *
     * @param int $backupJobId Backup job ID
     * @param string $serverName Server name
     * @param string $errorMessage Error message
     */
    public function sendFailureNotification(
        int $backupJobId,
        string $serverName,
        string $errorMessage
    ): void {
        $job = $this->backupJobRepository->findById($backupJobId);

        if (!$job) {
            $this->logger->warning("Backup job #{$backupJobId} not found. Cannot send notification.", 'EMAIL');
            return;
        }

        // Check if failure notifications are enabled
        if (!$job->notifyOnFailure) {
            $this->logger->debug("Failure notifications disabled for job #{$backupJobId}", 'EMAIL');
            return;
        }

        // Get notification email
        $recipientEmail = $this->getNotificationEmail();

        if (!$recipientEmail) {
            $this->logger->warning("No notification email configured. Cannot send notification.", 'EMAIL');
            return;
        }

        $appName = $this->getAppName();
        $subject = "✗ [{$appName}] Backup Failed: {$serverName}";

        $htmlBody = $this->generateFailureEmailHtml(
            $appName,
            $serverName,
            $job->name,
            $errorMessage
        );

        $plainBody = $this->generateFailureEmailPlain(
            $appName,
            $serverName,
            $job->name,
            $errorMessage
        );

        try {
            $this->emailService->send($recipientEmail, $subject, $htmlBody, $plainBody);
            $this->logger->info("Backup failure notification sent for job #{$backupJobId}", 'EMAIL');
        } catch (\Exception $e) {
            $this->logger->error("Failed to send backup failure notification: " . $e->getMessage(), 'EMAIL');
        }
    }

    /**
     * Generate success email HTML template
     */
    private function generateSuccessEmailHtml(
        string $appName,
        string $serverName,
        string $jobName,
        string $archiveName,
        array $stats
    ): string {
        $date = date('Y-m-d H:i:s');

        $statsHtml = '';
        if (!empty($stats)) {
            $duration = $stats['duration'] ?? 'N/A';
            $originalSize = $this->formatBytes($stats['original_size'] ?? 0);
            $compressedSize = $this->formatBytes($stats['compressed_size'] ?? 0);
            $dedupSize = $this->formatBytes($stats['deduplicated_size'] ?? 0);
            $filesProcessed = number_format($stats['files_processed'] ?? 0);

            $statsHtml = <<<HTML
            <h3 style="color: #1F2937; margin-top: 20px; margin-bottom: 10px; font-size: 16px;">Backup Statistics</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB;"><strong>Duration:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB; text-align: right;">{$duration}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB;"><strong>Original Size:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB; text-align: right;">{$originalSize}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB;"><strong>Compressed Size:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB; text-align: right;">{$compressedSize}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB;"><strong>Deduplicated Size:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB; text-align: right;">{$dedupSize}</td>
                </tr>
                <tr>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB;"><strong>Files Processed:</strong></td>
                    <td style="padding: 8px; border-bottom: 1px solid #E5E7EB; text-align: right;">{$filesProcessed}</td>
                </tr>
            </table>
HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #1F2937; margin: 0; padding: 0; background-color: #F3F4F6;">
    <div style="max-width: 600px; margin: 40px auto; background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #10B981 0%, #059669 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px; font-weight: 600;">{$appName}</h1>
            <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Backup System</p>
        </div>

        <!-- Success Badge -->
        <div style="padding: 30px 20px; text-align: center; background-color: #F0FDF4; border-bottom: 3px solid #10B981;">
            <div style="display: inline-block; background-color: #10B981; color: white; padding: 12px 24px; border-radius: 50px; font-size: 18px; font-weight: 600;">
                ✓ Backup Successful
            </div>
        </div>

        <!-- Content -->
        <div style="padding: 30px 20px;">
            <p style="font-size: 16px; margin-bottom: 20px;">
                The backup job has completed successfully.
            </p>

            <h3 style="color: #1F2937; margin-top: 20px; margin-bottom: 10px; font-size: 16px;">Backup Details</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr style="background-color: #F9FAFB;">
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Server:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$serverName}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Job Name:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$jobName}</td>
                </tr>
                <tr style="background-color: #F9FAFB;">
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Archive:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB; font-family: 'Courier New', monospace; font-size: 13px;">{$archiveName}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Completed at:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$date}</td>
                </tr>
            </table>

            {$statsHtml}

            <div style="margin-top: 30px; padding: 15px; background-color: #EFF6FF; border-left: 4px solid #3B82F6; border-radius: 4px;">
                <p style="margin: 0; font-size: 14px; color: #1E40AF;">
                    <strong>📊 Tip:</strong> Your backup data is securely stored and can be restored at any time from the {$appName} dashboard.
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div style="background-color: #F9FAFB; padding: 20px; text-align: center; border-top: 1px solid #E5E7EB;">
            <p style="margin: 0; font-size: 12px; color: #6B7280;">
                This is an automated notification from {$appName}<br>
                Generated at {$date}
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate success email plain text version
     */
    private function generateSuccessEmailPlain(
        string $appName,
        string $serverName,
        string $jobName,
        string $archiveName,
        array $stats
    ): string {
        $date = date('Y-m-d H:i:s');

        $statsText = '';
        if (!empty($stats)) {
            $duration = $stats['duration'] ?? 'N/A';
            $originalSize = $this->formatBytes($stats['original_size'] ?? 0);
            $compressedSize = $this->formatBytes($stats['compressed_size'] ?? 0);
            $dedupSize = $this->formatBytes($stats['deduplicated_size'] ?? 0);
            $filesProcessed = number_format($stats['files_processed'] ?? 0);

            $statsText = <<<TEXT

Backup Statistics:
- Duration: {$duration}
- Original Size: {$originalSize}
- Compressed Size: {$compressedSize}
- Deduplicated Size: {$dedupSize}
- Files Processed: {$filesProcessed}
TEXT;
        }

        return <<<TEXT
{$appName} - Backup System

[SUCCESS] Backup Successful

The backup job has completed successfully.

Backup Details:
- Server: {$serverName}
- Job Name: {$jobName}
- Archive: {$archiveName}
- Completed at: {$date}
{$statsText}

---
This is an automated notification from {$appName}
Generated at {$date}
TEXT;
    }

    /**
     * Generate failure email HTML template
     */
    private function generateFailureEmailHtml(
        string $appName,
        string $serverName,
        string $jobName,
        string $errorMessage
    ): string {
        $date = date('Y-m-d H:i:s');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; line-height: 1.6; color: #1F2937; margin: 0; padding: 0; background-color: #F3F4F6;">
    <div style="max-width: 600px; margin: 40px auto; background-color: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);">
        <!-- Header -->
        <div style="background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); color: white; padding: 30px 20px; text-align: center;">
            <h1 style="margin: 0; font-size: 28px; font-weight: 600;">{$appName}</h1>
            <p style="margin: 10px 0 0 0; font-size: 14px; opacity: 0.9;">Backup System</p>
        </div>

        <!-- Failure Badge -->
        <div style="padding: 30px 20px; text-align: center; background-color: #FEF2F2; border-bottom: 3px solid #EF4444;">
            <div style="display: inline-block; background-color: #EF4444; color: white; padding: 12px 24px; border-radius: 50px; font-size: 18px; font-weight: 600;">
                ✗ Backup Failed
            </div>
        </div>

        <!-- Content -->
        <div style="padding: 30px 20px;">
            <p style="font-size: 16px; margin-bottom: 20px; color: #DC2626;">
                <strong>⚠️ Attention Required:</strong> The backup job has failed and requires your attention.
            </p>

            <h3 style="color: #1F2937; margin-top: 20px; margin-bottom: 10px; font-size: 16px;">Backup Details</h3>
            <table style="width: 100%; border-collapse: collapse; margin-bottom: 20px;">
                <tr style="background-color: #F9FAFB;">
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Server:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$serverName}</td>
                </tr>
                <tr>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Job Name:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$jobName}</td>
                </tr>
                <tr style="background-color: #F9FAFB;">
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;"><strong>Failed at:</strong></td>
                    <td style="padding: 12px; border-bottom: 1px solid #E5E7EB;">{$date}</td>
                </tr>
            </table>

            <h3 style="color: #1F2937; margin-top: 20px; margin-bottom: 10px; font-size: 16px;">Error Details</h3>
            <div style="background-color: #FEF2F2; border-left: 4px solid #EF4444; padding: 15px; border-radius: 4px; margin-bottom: 20px;">
                <p style="margin: 0; font-family: 'Courier New', monospace; font-size: 13px; color: #DC2626; word-break: break-word;">
                    {$errorMessage}
                </p>
            </div>

            <div style="margin-top: 30px; padding: 15px; background-color: #FFFBEB; border-left: 4px solid #F59E0B; border-radius: 4px;">
                <p style="margin: 0; font-size: 14px; color: #92400E;">
                    <strong>🔧 Next Steps:</strong><br>
                    1. Check the {$appName} dashboard for detailed logs<br>
                    2. Verify server connectivity and credentials<br>
                    3. Ensure sufficient disk space is available<br>
                    4. Contact your system administrator if the issue persists
                </p>
            </div>
        </div>

        <!-- Footer -->
        <div style="background-color: #F9FAFB; padding: 20px; text-align: center; border-top: 1px solid #E5E7EB;">
            <p style="margin: 0; font-size: 12px; color: #6B7280;">
                This is an automated notification from {$appName}<br>
                Generated at {$date}
            </p>
        </div>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Generate failure email plain text version
     */
    private function generateFailureEmailPlain(
        string $appName,
        string $serverName,
        string $jobName,
        string $errorMessage
    ): string {
        $date = date('Y-m-d H:i:s');

        return <<<TEXT
{$appName} - Backup System

[FAILURE] Backup Failed

⚠️  ATTENTION REQUIRED: The backup job has failed and requires your attention.

Backup Details:
- Server: {$serverName}
- Job Name: {$jobName}
- Failed at: {$date}

Error Details:
{$errorMessage}

Next Steps:
1. Check the {$appName} dashboard for detailed logs
2. Verify server connectivity and credentials
3. Ensure sufficient disk space is available
4. Contact your system administrator if the issue persists

---
This is an automated notification from {$appName}
Generated at {$date}
TEXT;
    }

    /**
     * Get notification email address
     * TODO: Add notification_email field to backup_jobs table
     */
    private function getNotificationEmail(): ?string
    {
        // For now, use the SMTP from_email as the notification recipient
        // In the future, this should be configurable per backup job
        return $this->emailService->getFromEmail();
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
     * Format bytes to human-readable format
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $exp = floor(log($bytes) / log(1024));
        $exp = min($exp, count($units) - 1);

        return sprintf('%.2f %s', $bytes / pow(1024, $exp), $units[$exp]);
    }
}

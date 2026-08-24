<?php
/**
 * LOKA - Email Queue Class
 * 
 * Handles queueing and processing of emails for background sending
 */

class EmailQueue
{
    private Database $db;
    
    public function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    /**
     * Add email to queue
     * 
     * @param string $toEmail Recipient email
     * @param string $subject Email subject
     * @param string $body Email body (HTML)
     * @param string|null $toName Recipient name
     * @param string|null $template Template key used
     * @param int $priority Email priority (1-10)
     * @param string|null $scheduledAt When to send the email
     * @param int|null $requestId Related request ID for Control No. tracking
     * @return int Inserted email queue ID
     */
    public function queue(
        string $toEmail,
        string $subject,
        string $body,
        ?string $toName = null,
        ?string $template = null,
        int $priority = 5,
        ?string $scheduledAt = null,
        ?int $requestId = null
    ): int {
        return $this->db->insert('email_queue', [
            'to_email' => $toEmail,
            'to_name' => $toName,
            'subject' => $subject,
            'body' => $body,
            'template' => $template,
            'priority' => $priority,
            'scheduled_at' => $scheduledAt,
            'request_id' => $requestId,  // Store request ID for audit trail
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Queue email using template
     * 
     * @param string $toEmail Recipient email
     * @param string $templateKey Template key from MAIL_TEMPLATES
     * @param array $data Template data (message, link, link_text)
     * @param string|null $toName Recipient name
     * @param int $priority Email priority (1-10, lower = higher priority)
     * @param int|null $requestId Request ID for Control No. in subject
     * @return int Inserted email queue ID
     */
    public function queueTemplate(
        string $toEmail,
        string $templateKey,
        array $data = [],
        ?string $toName = null,
        int $priority = 5,
        ?int $requestId = null
    ): int {
        // Get template
        $templates = MAIL_TEMPLATES;
        if (!isset($templates[$templateKey])) {
            throw new Exception("Email template '$templateKey' not found");
        }
        
        $template = $templates[$templateKey];
        $subject = $template['subject'];
        
        // FIX: Add Control No. to subject if request ID is provided
        if ($requestId !== null) {
            $subject = "Control No. {$requestId}: {$subject}";
        }
        
        // Build email body
        $body = $this->buildEmailBody($templateKey, $template, $data);
        
        // Optional sync send: only when MAIL_SYNC_SEND is explicitly enabled.
        // Default is queue-only — the cron (cron/process_queue.php) sends emails
        // in the background so form submissions return instantly.
        $syncSent = false;
        
        if (MAIL_ENABLED && MAIL_SYNC_SEND) {
            try {
                $mailer = new Mailer();
                $syncSent = $mailer->send($toEmail, $subject, $body, $toName);
                
                if ($syncSent) {
                    error_log("[SYNC-EMAIL] Email sent successfully: {$templateKey} to {$toEmail}");
                } else {
                    $errors = $mailer->getErrors();
                    error_log("[SYNC-EMAIL] Send failed (queued as backup): {$templateKey} to {$toEmail} - " . implode(', ', $errors));
                }
            } catch (Exception $e) {
                error_log("[SYNC-EMAIL] Send exception (queued as backup): {$templateKey} to {$toEmail} - " . $e->getMessage());
            }
        }
        
        // Add to queue database for tracking
        $queueId = $this->queue($toEmail, $subject, $body, $toName, $templateKey, $priority, null, $requestId);
        
        // If it was successfully sent synchronously, mark it as sent immediately
        if ($syncSent) {
            $this->markSent($queueId);
        }
        
        return $queueId;
    }
    
    /**
     * Build HTML email body from template
     */
    private function buildEmailBody(string $templateKey, array $template, array $data): string
    {
        $message = $data['message'] ?? $template['template'];
        $link = $data['link'] ?? null;
        $linkText = $data['link_text'] ?? 'View Details';
        
        // Build full URL - link already starts with /, so just append to SITE_URL
        $fullLink = $link ? (SITE_URL . $link) : null;
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>' . htmlspecialchars($template['subject']) . '</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background: #f4f4f4; }
                .container { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
                .header { background: #0d6efd; color: #fff; padding: 20px; text-align: center; }
                .header h1 { margin: 0; font-size: 24px; }
                .content { padding: 30px; }
                .message { margin-bottom: 20px; }
                .btn { display: inline-block; padding: 12px 24px; background: #0d6efd; color: #fff; text-decoration: none; border-radius: 5px; font-weight: bold; }
                .btn:hover { background: #0b5ed7; }
                .footer { background: #f8f9fa; padding: 20px; text-align: center; font-size: 12px; color: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>' . APP_NAME . '</h1>
                </div>
                <div class="content">
                    <div class="message">' . nl2br(htmlspecialchars($message)) . '</div>';
        
        if ($fullLink) {
            $html .= '<p><a href="' . htmlspecialchars($fullLink) . '" class="btn">' . htmlspecialchars($linkText) . '</a></p>';
        }
        
        $html .= '
                </div>
                <div class="footer">
                    <p>This is an automated message from ' . APP_NAME . '</p>
                    <p>Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $html;
    }
    
    /**
     * Get pending emails for processing
     * 
     * OPTIMIZATION: Removed 30-second bias to prevent email starvation
     * OPTIMIZATION: Increased default batch size from 10 to 50
     * FIX: All pending emails are processed fairly by priority, not just recent ones
     * 
     * @param int $limit Maximum emails to fetch (default 50)
     * @return array Pending emails
     */
    public function getPending(int $limit = 50): array
    {
        // FIX: Process ALL pending emails by priority, not just recent ones
        // This prevents email starvation where older emails never get sent
        return $this->db->fetchAll(
            "SELECT * FROM email_queue 
             WHERE status = 'pending' 
             AND attempts < max_attempts
             AND (scheduled_at IS NULL OR scheduled_at <= NOW())
             ORDER BY priority ASC, created_at ASC
             LIMIT ?",
            [$limit]
        );
    }
    
    /**
     * Mark email as processing
     */
    public function markProcessing(int $id): void
    {
        $this->db->update('email_queue', [
            'status' => 'processing',
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$id]);
    }
    
    /**
     * Mark email as sent
     */
    public function markSent(int $id): void
    {
        $this->db->update('email_queue', [
            'status' => 'sent',
            'sent_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$id]);
    }
    
    /**
     * Mark email as failed
     * 
     * OPTIMIZATION: Added exponential backoff delay
     * FIX: Prevents immediate retry spam
     * 
     * @param int $id Email ID
     * @param string $error Error message
     */
    public function markFailed(int $id, string $error): void
    {
        $email = $this->db->fetch("SELECT attempts, max_attempts FROM email_queue WHERE id = ?", [$id]);
        
        $newAttempts = ($email->attempts ?? 0) + 1;
        $maxAttempts = $email->max_attempts ?? 3;
        $status = $newAttempts >= $maxAttempts ? 'failed' : 'pending';
        
        // FIX: Exponential backoff delay to prevent spam-like retry
        // Delays: 5 min, 10 min, 20 min, 40 min, then failed
        $delayMinutes = min(60, 5 * pow(2, $newAttempts - 1));
        $retryAt = ($status === 'failed') ? null : date('Y-m-d H:i:s', strtotime("+{$delayMinutes} minutes"));
        
        $this->db->update('email_queue', [
            'status' => $status,
            'attempts' => $newAttempts,
            'error_message' => $error,
            'scheduled_at' => $retryAt,
            'updated_at' => date('Y-m-d H:i:s')
        ], 'id = ?', [$id]);
        
        error_log("Email #{$id} marked as {$status}. Attempt {$newAttempts}/{$maxAttempts}. Retry in {$delayMinutes}min: {$error}");
    }
    
    /**
     * Process the email queue
     * Returns array with counts of sent, failed, skipped
     * 
     * OPTIMIZATION: Reuses SMTP connection across batch
     * FIX: Duplicate Mailer instantiation removed
     */
    public function process(int $batchSize = 50): array
    {
        $results = ['sent' => 0, 'failed' => 0, 'skipped' => 0];
        $lockKey = 'email_queue_processing';
        
        // Acquire advisory lock (non-blocking) - prevents duplicate processing
        $lockAcquired = $this->db->fetchColumn("SELECT GET_LOCK(?, 0)", [$lockKey]);
        
        if (!$lockAcquired) {
            error_log("EmailQueue::process() - Another worker is processing, skipping");
            return $results;
        }
        
        try {
            $emails = $this->getPending($batchSize);
            
            if (empty($emails)) {
                return $results;
            }
            
            // FIX: Create ONE mailer instance OUTSIDE loop - reuses SMTP connection
            $mailer = new Mailer();
            
            foreach ($emails as $email) {
                // Use SELECT FOR UPDATE to prevent duplicate processing
                $locked = $this->db->fetch(
                    "SELECT id FROM email_queue 
                     WHERE id = ? AND status = 'pending' 
                     FOR UPDATE",
                    [$email->id]
                );
                
                if (!$locked) {
                    error_log("Email #{$email->id} skipped - already processed by another worker");
                    $results['skipped']++;
                    continue;
                }
                
                $this->markProcessing($email->id);
                
                try {
                    // FIX: Removed duplicate instantiation - uses existing $mailer
                    $sent = $mailer->send(
                        $email->to_email,
                        $email->subject,
                        $email->body,
                        $email->to_name
                    );
                    
                    if ($sent) {
                        $this->markSent($email->id);
                        $results['sent']++;
                    } else {
                        $errors = $mailer->getErrors();
                        $errorMsg = !empty($errors) ? implode(', ', $errors) : 'Send returned false';
                        $this->markFailed($email->id, $errorMsg);
                        $results['failed']++;
                        error_log("Email #{$email->id} failed to {$email->to_email}: {$errorMsg}");
                    }
                } catch (Exception $e) {
                    $errorMsg = $e->getMessage();
                    $this->markFailed($email->id, $errorMsg);
                    $results['failed']++;
                    error_log("Email #{$email->id} exception: {$errorMsg}");
                }
            }
            
            if ($results['sent'] > 0 || $results['failed'] > 0) {
                error_log("EmailQueue processed: {$results['sent']} sent, {$results['failed']} failed, {$results['skipped']} skipped");
            }
        } catch (Exception $e) {
            error_log("EmailQueue::process() exception: " . $e->getMessage());
        } finally {
            // Always release the advisory lock
            $this->db->fetchColumn("SELECT RELEASE_LOCK(?)", [$lockKey]);
        }
        
        return $results;
    }
    
    /**
     * Get queue statistics
     * 
     * OPTIMIZATION: Added recent failure count for alerting
     * 
     * @return array Statistics including recent failures
     */
    public function getStats(): array
    {
        return [
            'pending' => $this->db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'pending'"),
            'processing' => $this->db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'processing'"),
            'sent' => $this->db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'sent'"),
            'failed' => $this->db->fetchColumn("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'"),
            // FIX: Track recent failures for alerting
            'recent_failures' => $this->db->fetchColumn(
                "SELECT COUNT(*) FROM email_queue 
                 WHERE status = 'failed' 
                 AND updated_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
            )
        ];
    }
    
    /**
     * Clean old sent emails (older than X days)
     */
    public function cleanup(int $daysOld = 30): int
    {
        $result = $this->db->query(
            "DELETE FROM email_queue WHERE status = 'sent' AND sent_at < DATE_SUB(NOW(), INTERVAL ? DAY)",
            [$daysOld]
        );
        return $result->rowCount();
    }
}

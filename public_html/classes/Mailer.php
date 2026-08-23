<?php
/**
 * LOKA - Simple SMTP Mailer Class
 * Sends emails via SMTP without external dependencies
 */

class Mailer
{
    private string $host;
    private int $port;
    private string $username;
    private string $password;
    private string $encryption;
    private string $fromAddress;
    private string $fromName;
    private $socket;
    private array $errors = [];

    public function __construct()
    {
        $this->host = MAIL_HOST;
        $this->port = MAIL_PORT;
        $this->username = MAIL_USERNAME;
        $this->password = MAIL_PASSWORD;
        $this->encryption = MAIL_ENCRYPTION;
        $this->fromAddress = MAIL_FROM_ADDRESS;
        $this->fromName = MAIL_FROM_NAME;
        // FIX: Initialize socket tracker for connection reuse
        $this->socket = null;
    }

    /**
     * Send an email
     */
    public function send(string $to, string $subject, string $body, ?string $toName = null, bool $isHtml = true): bool
    {
        // Reset errors for this send attempt
        $this->errors = [];
        
        if (!MAIL_ENABLED) {
            $this->errors[] = "Email sending is disabled (MAIL_ENABLED is false)";
            return false;
        }
        
        // Validate mail configuration
        if (empty($this->host)) {
            $this->errors[] = "MAIL_HOST is not configured";
            return false;
        }
        
        if (empty($this->username) || empty($this->password)) {
            $this->errors[] = "MAIL_USERNAME and MAIL_PASSWORD must be configured";
            return false;
        }
        
        if (empty($this->fromAddress) || !filter_var($this->fromAddress, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "MAIL_FROM_ADDRESS must be a valid email address";
            return false;
        }
        
        // Validate email address
        if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->errors[] = "Invalid email address: {$to}";
            return false;
        }

        try {
            // Connect to SMTP server
            $this->connect();
            
            // Say hello
            $this->sendCommand("EHLO " . gethostname(), 250);
            
            // Start TLS if required
            if ($this->encryption === 'tls') {
                $this->sendCommand("STARTTLS", 220);
                
                // Enable TLS encryption
                $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
                if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                    $cryptoMethod = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
                }
                
                if (!@stream_socket_enable_crypto($this->socket, true, $cryptoMethod)) {
                    throw new Exception("Failed to enable TLS encryption");
                }
                
                // Re-send EHLO after TLS
                $this->sendCommand("EHLO " . gethostname(), 250);
            }
            
            // Authenticate
            $this->sendCommand("AUTH LOGIN", 334);
            $this->sendCommand(base64_encode($this->username), 334);
            $this->sendCommand(base64_encode($this->password), 235);
            
            // Set sender and recipient
            $this->sendCommand("MAIL FROM:<{$this->fromAddress}>", 250);
            $this->sendCommand("RCPT TO:<{$to}>", 250);
            
            // Send data
            $this->sendCommand("DATA", 354);
            
            // Build message
            $headers = $this->buildHeaders($to, $subject, $isHtml, $toName);
            
            // Send headers and body separately for proper SMTP handling
            $fullMessage = $headers . "\r\n\r\n" . $body . "\r\n.";
            
            // For DATA command, we need to send the message and then check for 250 response
            fwrite($this->socket, $fullMessage . "\r\n");
            $response = $this->getResponse();
            $code = (int) substr($response, 0, 3);
            
            if ($code !== 250) {
                throw new Exception("SMTP error sending message: Expected 250, got {$code}. Response: {$response}");
            }
            
            // Quit
            $this->sendCommand("QUIT", 221);
            
            $this->disconnect();
            
            return true;
            
        } catch (Exception $e) {
            $this->errors[] = $e->getMessage();
            error_log("Mailer error: " . $e->getMessage());
            $this->disconnect();
            return false;
        }
    }

    /**
     * Send email using template
     */
    public function sendTemplate(string $to, string $templateKey, array $data = [], ?string $toName = null): bool
    {
        if (!isset(MAIL_TEMPLATES[$templateKey])) {
            $this->errors[] = "Template not found: {$templateKey}";
            return false;
        }

        $template = MAIL_TEMPLATES[$templateKey];
        $subject = $template['subject'];
        
        // Build HTML body
        $body = $this->buildHtmlBody($template['subject'], $template['template'], $data);
        
        return $this->send($to, $subject, $body, $toName, true);
    }

    /**
     * Build email headers
     */
    private function buildHeaders(string $to, string $subject, bool $isHtml, ?string $toName = null): string
    {
        $headers = [];
        $headers[] = "Date: " . date('r');
        $headers[] = "From: {$this->fromName} <{$this->fromAddress}>";
        
        // Format To header with name if provided
        if ($toName) {
            $headers[] = "To: {$toName} <{$to}>";
        } else {
            $headers[] = "To: {$to}";
        }
        
        // Encode subject to handle special characters
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'Q');
        $headers[] = "Subject: {$encodedSubject}";
        $headers[] = "MIME-Version: 1.0";
        
        if ($isHtml) {
            $headers[] = "Content-Type: text/html; charset=UTF-8";
        } else {
            $headers[] = "Content-Type: text/plain; charset=UTF-8";
        }
        
        $headers[] = "X-Mailer: LOKA Fleet Management";
        
        return implode("\r\n", $headers);
    }

    /**
     * Build HTML email body
     */
    private function buildHtmlBody(string $title, string $content, array $data = []): string
    {
        $message = $data['message'] ?? $content;
        $link = $data['link'] ?? null;
        $linkText = $data['link_text'] ?? 'View Details';
        
        $html = '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="margin:0;padding:0;font-family:Arial,sans-serif;background-color:#f4f4f4;">
    <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f4f4;padding:20px;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" style="background-color:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 2px 4px rgba(0,0,0,0.1);">
                    <!-- Header -->
                    <tr>
                        <td style="background-color:#0d6efd;padding:30px;text-align:center;">
                            <h1 style="color:#ffffff;margin:0;font-size:24px;">LOKA Fleet Management</h1>
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:40px 30px;">
                            <h2 style="color:#333333;margin:0 0 20px 0;font-size:20px;">' . htmlspecialchars($title) . '</h2>
                            <p style="color:#666666;font-size:16px;line-height:1.6;margin:0 0 20px 0;">' . nl2br(htmlspecialchars($message)) . '</p>';
        
        if ($link) {
            // If link already starts with http, use as-is, otherwise prepend SITE_URL
            $fullLink = (strpos($link, 'http') === 0) ? $link : SITE_URL . $link;
            $html .= '
                            <p style="text-align:center;margin:30px 0;">
                                <a href="' . htmlspecialchars($fullLink) . '" style="display:inline-block;background-color:#0d6efd;color:#ffffff;padding:12px 30px;text-decoration:none;border-radius:5px;font-weight:bold;">' . htmlspecialchars($linkText) . '</a>
                            </p>';
        }
        
        $html .= '
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="background-color:#f8f9fa;padding:20px 30px;text-align:center;border-top:1px solid #eeeeee;">
                            <p style="color:#999999;font-size:12px;margin:0;">This is an automated message from LOKA Fleet Management System.</p>
                            <p style="color:#999999;font-size:12px;margin:10px 0 0 0;">Please do not reply to this email.</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
        
        return $html;
    }

    /**
     * Connect to SMTP server
     * 
     * OPTIMIZATION: Reuse existing connection if still valid
     * FIX: Prevents multiple TCP connections per batch
     */
    private function connect(): void
    {
        // FIX: Reuse existing connection if still valid
        if ($this->socket && is_resource($this->socket) && !feof($this->socket)) {
            // Connection is already established and valid
            return;
        }
        
        $host = $this->encryption === 'ssl' ? "ssl://{$this->host}" : $this->host;
        
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ]);
        
        $this->socket = @stream_socket_client(
            "{$host}:{$this->port}",
            $errno,
            $errstr,
            60,
            STREAM_CLIENT_CONNECT,
            $context
        );
        
        if (!$this->socket) {
            throw new Exception("Could not connect to SMTP server: {$errstr} ({$errno})");
        }
        
        // Set stream timeout
        stream_set_timeout($this->socket, 60);
        
        // Get initial server response
        $response = $this->getResponse();
        $code = (int) substr($response, 0, 3);
        
        if ($code !== 220) {
            throw new Exception("SMTP server did not send ready response. Got: {$response}");
        }
    }

    /**
     * Send SMTP command
     */
    private function sendCommand(string $command, int $expectedCode): string
    {
        fwrite($this->socket, $command . "\r\n");
        $response = $this->getResponse();
        
        $code = (int) substr($response, 0, 3);
        
        if ($code !== $expectedCode) {
            throw new Exception("SMTP error: Expected {$expectedCode}, got {$code}. Response: {$response}");
        }
        
        return $response;
    }

    /**
     * Get SMTP response
     */
    private function getResponse(): string
    {
        $response = '';
        $timeout = 60; // seconds
        $startTime = time();
        
        while (true) {
            // Check for timeout
            if (time() - $startTime > $timeout) {
                throw new Exception("SMTP response timeout after {$timeout} seconds");
            }
            
            // Check if socket is still valid
            if (!is_resource($this->socket) || feof($this->socket)) {
                throw new Exception("SMTP connection lost");
            }
            
            // Set socket timeout
            stream_set_timeout($this->socket, 5);
            
            $line = @fgets($this->socket, 515);
            
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    throw new Exception("SMTP read timeout");
                }
                throw new Exception("SMTP read error");
            }
            
            $response .= $line;
            
            // Check if this is the last line of the response (4th character is space)
            if (strlen($line) >= 4 && substr($line, 3, 1) === ' ') {
                break;
            }
        }
        
        return trim($response);
    }

    /**
     * Disconnect from SMTP server
     */
    private function disconnect(): void
    {
        if ($this->socket) {
            fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Get errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
    
    /**
     * Explicit cleanup when object is destroyed
     * Ensures SMTP connection is properly closed
     */
    public function __destruct()
    {
        $this->disconnect();
    }
}

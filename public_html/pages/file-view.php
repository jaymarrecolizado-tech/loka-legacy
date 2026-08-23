<?php
/**
 * LOKA - View Uploaded File
 *
 * Serves uploaded files through PHP with permission checking
 * This prevents direct access to the uploads directory
 */

$filePath = get('file', '');
$fileType = get('type', 'unknown');

// Security: Validate file path
if (empty($filePath) || strpos($filePath, '..') !== false || strpos($filePath, 'uploads/') !== 0) {
    http_response_code(403);
    exit('Access denied');
}

// Build full path
$fullPath = __DIR__ . '/../' . $filePath;

// Check if file exists
if (!file_exists($fullPath)) {
    http_response_code(404);
    exit('File not found');
}

// Get file info
$fileName = basename($filePath);
$fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Set MIME type based on extension
$mimeTypes = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'txt' => 'text/plain',
    'csv' => 'text/csv',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'zip' => 'application/zip',
];

$mimeType = $mimeTypes[$fileExtension] ?? 'application/octet-stream';

// Clear output buffer
while (ob_get_level()) {
    ob_end_clean();
}

// Set headers
header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: public, max-age=86400'); // Cache for 1 day
header('Content-Disposition: inline; filename="' . $fileName . '"');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// Output file
readfile($fullPath);
exit;

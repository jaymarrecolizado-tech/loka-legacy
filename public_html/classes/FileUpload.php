<?php
/**
 * LOKA - File Upload Class
 *
 * Handles secure file uploads with validation
 */

class FileUpload
{
    private string $uploadDir;
    private int $maxFileSize = 5242880; // 5MB default
    private array $allowedTypes = [];
    private array $errors = [];

    /**
     * Constructor
     *
     * @param string $uploadDir Upload directory path
     */
    public function __construct(string $uploadDir)
    {
        // Ensure upload directory exists
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                throw new Exception("Failed to create upload directory: {$uploadDir}");
            }
        }

        $this->uploadDir = rtrim($uploadDir, '/\\') . DIRECTORY_SEPARATOR;

        // Create .htaccess to protect uploads
        $htaccessPath = $this->uploadDir . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Order Deny,Allow\nDeny from all\n");
        }
    }

    /**
     * Set maximum file size in bytes
     *
     * @param int $bytes Maximum file size
     * @return self
     */
    public function setMaxFileSize(int $bytes): self
    {
        $this->maxFileSize = $bytes;
        return $this;
    }

    /**
     * Set allowed MIME types
     *
     * @param array $types Array of allowed MIME types
     * @return self
     */
    public function setAllowedTypes(array $types): self
    {
        $this->allowedTypes = $types;
        return $this;
    }

    /**
     * Set allowed file extensions
     *
     * @param array $extensions Array of allowed extensions (e.g., ['pdf', 'jpg', 'png'])
     * @return self
     */
    public function setAllowedExtensions(array $extensions): self
    {
        $this->allowedExtensions = $extensions;
        return $this;
    }

    /**
     * Upload a single file
     *
     * @param array $fileFile File from $_FILES array
     * @param string $prefix Optional prefix for filename
     * @return string|false Relative path to uploaded file, or false on failure
     */
    public function upload(array $fileFile, string $prefix = ''): string|false
    {
        // Clear previous errors
        $this->errors = [];

        // Check if file was uploaded
        if (!isset($fileFile['tmp_name']) || !is_uploaded_file($fileFile['tmp_name'])) {
            if ($fileFile['error'] === UPLOAD_ERR_NO_FILE) {
                // No file uploaded, not an error
                return '';
            }
            $this->errors[] = 'No file was uploaded';
            return false;
        }

        // Check for upload errors
        if ($fileFile['error'] !== UPLOAD_ERR_OK) {
            $this->errors[] = $this->getUploadErrorMessage($fileFile['error']);
            return false;
        }

        // Validate file size
        if ($fileFile['size'] > $this->maxFileSize) {
            $this->errors[] = 'File size exceeds maximum allowed size of ' . $this->formatBytes($this->maxFileSize);
            return false;
        }

        // Validate file exists and is readable
        if (!file_exists($fileFile['tmp_name']) || !is_readable($fileFile['tmp_name'])) {
            $this->errors[] = 'Uploaded file is not accessible';
            return false;
        }

        // Get file info
        $fileName = $fileFile['name'];
        $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $fileMime = mime_content_type($fileFile['tmp_name']);

        // Validate file extension
        if (!empty($this->allowedExtensions) && !in_array($fileExt, $this->allowedExtensions)) {
            $this->errors[] = 'File extension "' . $fileExt . '" is not allowed';
            return false;
        }

        // Validate MIME type
        if (!empty($this->allowedTypes) && !in_array($fileMime, $this->allowedTypes)) {
            $this->errors[] = 'File type "' . $fileMime . '" is not allowed';
            return false;
        }

        // Security: Check file contents match extension
        if (!$this->validateFileContent($fileFile['tmp_name'], $fileExt)) {
            $this->errors[] = 'File content does not match the file extension';
            return false;
        }

        // Generate unique filename
        $uniqueName = $prefix . '_' . uniqid() . '_' . bin2hex(random_bytes(4)) . '.' . $fileExt;
        $uploadPath = $this->uploadDir . $uniqueName;

        // Move uploaded file
        if (!move_uploaded_file($fileFile['tmp_name'], $uploadPath)) {
            $this->errors[] = 'Failed to move uploaded file';
            return false;
        }

        // Set proper permissions
        chmod($uploadPath, 0644);

        // Return relative path for database storage
        return 'uploads/' . basename($this->uploadDir) . '/' . $uniqueName;
    }

    /**
     * Upload multiple files
     *
     * @param array $files Files array from $_FILES
     * @param string $prefix Optional prefix for filenames
     * @return array Array of file paths
     */
    public function uploadMultiple(array $files, string $prefix = ''): array
    {
        $uploadedFiles = [];

        // Reorganize files array if multiple files with same name
        if (isset($files['name']) && is_array($files['name'])) {
            $fileCount = count($files['name']);
            for ($i = 0; $i < $fileCount; $i++) {
                $fileArray = [
                    'name' => $files['name'][$i],
                    'type' => $files['type'][$i],
                    'tmp_name' => $files['tmp_name'][$i],
                    'error' => $files['error'][$i],
                    'size' => $files['size'][$i]
                ];

                $uploadedPath = $this->upload($fileArray, $prefix);
                if ($uploadedPath !== false && $uploadedPath !== '') {
                    $uploadedFiles[] = $uploadedPath;
                }
            }
        }

        return $uploadedFiles;
    }

    /**
     * Delete a file
     *
     * @param string $filePath Path to file (relative to public_html or absolute)
     * @return bool True if deleted, false otherwise
     */
    public static function delete(string $filePath): bool
    {
        // Make path absolute if relative
        $fullPath = strpos($filePath, '/') === 0 ? $filePath : dirname(__DIR__) . '/' . $filePath;

        if (file_exists($fullPath)) {
            return unlink($fullPath);
        }
        return false;
    }

    /**
     * Get upload errors
     *
     * @return array Array of error messages
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Check if there are any errors
     *
     * @return bool
     */
    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    /**
     * Validate file content matches extension
     *
     * @param string $filePath Path to uploaded file
     * @param string $extension File extension
     * @return bool
     */
    private function validateFileContent(string $filePath, string $extension): bool
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        // Basic validation map
        $mimeMap = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ];

        if (isset($mimeMap[$extension])) {
            $expectedMimes = (array) $mimeMap[$extension];
            return in_array($mimeType, $expectedMimes);
        }

        return true;
    }

    /**
     * Get upload error message
     *
     * @param int $errorCode Upload error code
     * @return string
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'The uploaded file exceeds the upload_max_filesize directive in php.ini',
            UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the MAX_FILE_SIZE directive specified in the HTML form',
            UPLOAD_ERR_PARTIAL => 'The uploaded file was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload',
            default => 'Unknown upload error'
        };
    }

    /**
     * Format bytes to human readable
     *
     * @param int $bytes Number of bytes
     * @param int $precision Decimal precision
     * @return string
     */
    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Create an upload handler for PDF documents
     *
     * @param string $subdirectory Subdirectory within uploads
     * @return self
     */
    public static function createPdfHandler(string $subdirectory): self
    {
        $uploadDir = __DIR__ . '/../uploads/' . $subdirectory;
        $handler = new self($uploadDir);
        $handler->setAllowedTypes(['application/pdf']);
        $handler->setAllowedExtensions(['pdf']);
        $handler->setMaxFileSize(10485760); // 10MB
        return $handler;
    }

    /**
     * Create an upload handler for images
     *
     * @param string $subdirectory Subdirectory within uploads
     * @return self
     */
    public static function createImageHandler(string $subdirectory): self
    {
        $uploadDir = __DIR__ . '/../uploads/' . $subdirectory;
        $handler = new self($uploadDir);
        $handler->setAllowedTypes(['image/jpeg', 'image/png', 'image/gif']);
        $handler->setAllowedExtensions(['jpg', 'jpeg', 'png', 'gif']);
        $handler->setMaxFileSize(5242880); // 5MB
        return $handler;
    }

    /**
     * Create an upload handler for trip ticket documents
     *
     * @param int $requestId Request ID for subdirectory organization
     * @return self
     */
    public static function createTripTicketHandler(int $requestId): self
    {
        $uploadDir = __DIR__ . '/../uploads/trip_tickets/' . $requestId;
        $handler = new self($uploadDir);
        $handler->setAllowedTypes([
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/gif',
            'application/zip',
            'application/x-zip-compressed'
        ]);
        $handler->setAllowedExtensions(['pdf', 'jpg', 'jpeg', 'png', 'gif', 'zip']);
        $handler->setMaxFileSize(10485760); // 10MB
        return $handler;
    }
}

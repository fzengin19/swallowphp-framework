<?php

namespace SwallowPHP\Framework\Session\Handler;

use SessionHandlerInterface;

/**
 * File-based session handler storing sessions within the application's storage path.
 */
class FileSessionHandler implements SessionHandlerInterface
{
    protected string $savePath;
    protected int $filePermission;

    /**
     * Create a new file session handler instance.
     *
     * @param string $savePath The directory where session files will be stored.
     * @param int $filePermission Permissions for created session files.
     * @throws \InvalidArgumentException If save path is not provided.
     * @throws \RuntimeException If save path is not a directory or not writable.
     */
    public function __construct(string $savePath, int $filePermission = 0600)
    {
        if (empty($savePath)) {
            throw new \InvalidArgumentException('Session save path cannot be empty.');
        }
        $this->savePath = rtrim($savePath, '/\\');
        $this->filePermission = $filePermission;

        // Ensure the save path exists and is writable
        if (!is_dir($this->savePath)) {
             if (!@mkdir($this->savePath, 0700, true) && !is_dir($this->savePath)) { // Use more restrictive default permissions for dir
                 throw new \RuntimeException("Session save path directory does not exist and cannot be created: {$this->savePath}");
             }
        }
        if (!is_writable($this->savePath)) {
            throw new \RuntimeException("Session save path directory is not writable: {$this->savePath}");
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $path, string $name): bool
    {
        // Directory existence/writability checked in constructor
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true; // Nothing specific to close for file handler
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $id): string|false
    {
        $filePath = $this->getSessionFilePath($id);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return ''; // Return empty string for non-existent session (as per PHP behavior)
        }

        // Use file locking for basic concurrency control
        $handle = @fopen($filePath, 'r');
        if (!$handle) {
             error_log("Session read error: Could not open file {$filePath}");
             return false; // Indicate failure
        }

        try {
            if (@flock($handle, LOCK_SH)) { // Shared lock for reading
                $data = stream_get_contents($handle);
                flock($handle, LOCK_UN);
                return $data !== false ? $data : '';
            } else {
                 error_log("Session read error: Could not acquire lock for file {$filePath}");
                 return false; // Indicate failure (or maybe return empty string?)
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $id, string $data): bool
    {
        $filePath = $this->getSessionFilePath($id);

        // Use exclusive lock for writing
        $handle = @fopen($filePath, 'c'); // Open for writing; create if not exists; truncate to zero length
        if (!$handle) {
             error_log("Session write error: Could not open file {$filePath} for writing.");
            return false;
        }

        try {
            if (@flock($handle, LOCK_EX)) {
                // Truncate the file before writing
                if (!@ftruncate($handle, 0)) {
                     error_log("Session write error: Could not truncate file {$filePath}.");
                     flock($handle, LOCK_UN);
                     return false;
                }
                // Write data
                $bytesWritten = @fwrite($handle, $data);
                // Ensure data is written to disk
                @fflush($handle);
                flock($handle, LOCK_UN);

                // Set permissions if file was newly created? fopen with 'c' might not always create.
                // Check if file exists now and set permissions if needed.
                 if (!file_exists($filePath)) {
                    // This case shouldn't happen often with 'c' mode, but handle defensively.
                    // Re-attempt creation/permission setting might be needed.
                 } elseif (decoct(fileperms($filePath) & 0777) != decoct($this->filePermission)) {
                     @chmod($filePath, $this->filePermission);
                 }


                return $bytesWritten !== false && $bytesWritten === strlen($data);
            } else {
                 error_log("Session write error: Could not acquire lock for file {$filePath}.");
                 return false;
            }
        } finally {
            @fclose($handle);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function destroy(string $id): bool
    {
        $filePath = $this->getSessionFilePath($id);
        if (file_exists($filePath)) {
            return @unlink($filePath);
        }
        return true; // Session didn't exist, considered success
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $max_lifetime): int|false
    {
        $count = 0;
        $files = glob($this->savePath . '/sess_*'); // Find files starting with sess_ prefix

        if ($files === false) {
             error_log("Session garbage collection failed: Could not glob session path {$this->savePath}");
             return false; // Indicate failure
        }

        foreach ($files as $file) {
            // Check if file is older than maxlifetime and delete it
            if (is_file($file) && @filemtime($file) + $max_lifetime < time()) {
                if (@unlink($file)) {
                    $count++;
                } else {
                     error_log("Session garbage collection error: Could not delete file {$file}");
                }
            }
        }
        return $count; // Return number of deleted sessions
    }

    /**
     * Get the full path to the session file.
     * Uses PHP's default naming convention 'sess_SESSIONID'.
     *
     * @param string $id Session ID.
     * @return string
     */
    protected function getSessionFilePath(string $id): string
    {
        // Basic validation/sanitization for session ID to prevent path traversal
        if (!preg_match('/^[a-zA-Z0-9,-]+$/', $id)) {
             throw new \InvalidArgumentException("Invalid characters in session ID: {$id}");
        }
        return $this->savePath . '/sess_' . $id;
    }
}
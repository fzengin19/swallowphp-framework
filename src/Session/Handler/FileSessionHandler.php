<?php

namespace SwallowPHP\Framework\Session\Handler;

use SessionHandlerInterface;
use Psr\Log\LoggerInterface; // Import LoggerInterface
use Psr\Log\NullLogger; // Import NullLogger for fallback

/**
 * File-based session handler storing sessions within the application's storage path.
 */
class FileSessionHandler implements SessionHandlerInterface
{
    protected string $savePath;
    protected int $filePermission;
    protected LoggerInterface $logger; // Added logger property

    /**
     * Create a new file session handler instance.
     *
     * @param string $savePath The directory where session files will be stored.
     * @param LoggerInterface|null $logger Optional logger instance.
     * @param int $filePermission Permissions for created session files.
     * @throws \InvalidArgumentException If save path is not provided.
     * @throws \RuntimeException If save path is not a directory or not writable.
     */
    public function __construct(string $savePath, ?LoggerInterface $logger = null, int $filePermission = 0600)
    {
        if (empty($savePath)) {
            throw new \InvalidArgumentException('Session save path cannot be empty.');
        }
        $this->savePath = rtrim($savePath, '/\\');
        $this->filePermission = $filePermission;
        $this->logger = $logger ?? new NullLogger(); // Use provided logger or NullLogger

        // Ensure the save path exists and is writable
        if (!is_dir($this->savePath)) {
             if (!@mkdir($this->savePath, 0700, true) && !is_dir($this->savePath)) {
                 $this->logger->critical("Session save path directory does not exist and cannot be created", ['path' => $this->savePath]);
                 throw new \RuntimeException("Session save path directory does not exist and cannot be created: {$this->savePath}");
             }
        }
        if (!is_writable($this->savePath)) {
            $this->logger->critical("Session save path directory is not writable", ['path' => $this->savePath]);
            throw new \RuntimeException("Session save path directory is not writable: {$this->savePath}");
        }
    }

    /** {@inheritdoc} */
    public function open(string $path, string $name): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function close(): bool
    {
        return true;
    }

    /** {@inheritdoc} */
    public function read(string $id): string|false
    {
        $filePath = $this->getSessionFilePath($id);

        if (!file_exists($filePath) || !is_readable($filePath)) {
            return '';
        }

        $handle = @fopen($filePath, 'r');
        if (!$handle) {
             $this->logger->error("Session read error: Could not open file", ['path' => $filePath]);
             return false;
        }

        try {
            if (@flock($handle, LOCK_SH)) {
                $data = stream_get_contents($handle);
                flock($handle, LOCK_UN);
                return $data !== false ? $data : '';
            } else {
                 $this->logger->warning("Session read error: Could not acquire lock", ['path' => $filePath]);
                 return false;
            }
        } finally {
            @fclose($handle);
        }
    }

    /** {@inheritdoc} */
    public function write(string $id, string $data): bool
    {
        $filePath = $this->getSessionFilePath($id);

        $handle = @fopen($filePath, 'c');
        if (!$handle) {
             $this->logger->error("Session write error: Could not open file for writing", ['path' => $filePath]);
            return false;
        }

        try {
            if (@flock($handle, LOCK_EX)) {
                if (!@ftruncate($handle, 0)) {
                     $this->logger->error("Session write error: Could not truncate file", ['path' => $filePath]);
                     flock($handle, LOCK_UN);
                     return false;
                }
                $bytesWritten = @fwrite($handle, $data);
                @fflush($handle);
                flock($handle, LOCK_UN);

                 // Set permissions if file permissions are incorrect
                 clearstatcache(true, $filePath); // Clear stat cache before checking perms
                 if (file_exists($filePath) && decoct(fileperms($filePath) & 0777) != decoct($this->filePermission)) {
                     @chmod($filePath, $this->filePermission);
                 }

                return $bytesWritten !== false && $bytesWritten === strlen($data);
            } else {
                 $this->logger->warning("Session write error: Could not acquire lock", ['path' => $filePath]);
                 return false;
            }
        } finally {
            @fclose($handle);
        }
    }

    /** {@inheritdoc} */
    public function destroy(string $id): bool
    {
        $filePath = $this->getSessionFilePath($id);
        if (file_exists($filePath)) {
            if (!@unlink($filePath)) {
                 $this->logger->error("Session destroy error: Could not delete file", ['path' => $filePath]);
                 return false;
            }
        }
        return true;
    }

    /** {@inheritdoc} */
    public function gc(int $max_lifetime): int|false
    {
        $count = 0;
        $files = glob($this->savePath . '/sess_*');

        if ($files === false) {
             $this->logger->error("Session garbage collection failed: Could not glob session path", ['path' => $this->savePath]);
             return false;
        }

        foreach ($files as $file) {
            if (is_file($file) && @filemtime($file) + $max_lifetime < time()) {
                if (!@unlink($file)) {
                     $this->logger->error("Session garbage collection error: Could not delete file", ['path' => $file]);
                } else {
                     $count++;
                }
            }
        }
        return $count;
    }

    /** Get the full path to the session file. */
    protected function getSessionFilePath(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9,-]+$/', $id)) {
             $this->logger->error("Invalid characters detected in session ID", ['session_id' => $id]); // Log invalid ID
             throw new \InvalidArgumentException("Invalid characters in session ID."); // Throw exception
        }
        return $this->savePath . '/sess_' . $id;
    }
}
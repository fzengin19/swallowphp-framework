<?php

namespace SwallowPHP\Framework\Log;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Psr\Log\LogLevel;
use Stringable;
use DateTimeImmutable;

/**
 * Simple file-based PSR-3 logger implementation.
 */
class FileLogger implements LoggerInterface
{
    // Use LoggerTrait to implement emergency(), alert() etc. based on log()
    use LoggerTrait;

    private string $logFilePath;
    private int $minLevelValue;
    private array $logLevels = [
        LogLevel::DEBUG     => 100,
        LogLevel::INFO      => 200,
        LogLevel::NOTICE    => 250,
        LogLevel::WARNING   => 300,
        LogLevel::ERROR     => 400,
        LogLevel::CRITICAL  => 500,
        LogLevel::ALERT     => 550,
        LogLevel::EMERGENCY => 600,
    ];

    /**
     * Constructor.
     *
     * @param string $logFilePath Absolute path to the log file.
     * @param string $minLevel Minimum log level to record (from Psr\Log\LogLevel constants).
     * @throws \InvalidArgumentException If path is invalid or level is invalid.
     * @throws \RuntimeException If log directory/file cannot be created or is not writable.
     */
    public function __construct(string $logFilePath, string $minLevel = LogLevel::DEBUG)
    {
        if (empty($logFilePath)) {
            throw new \InvalidArgumentException("Log file path cannot be empty.");
        }
        $this->logFilePath = $logFilePath;

        $minLevelUpper = strtoupper($minLevel);
        if (!defined(LogLevel::class . '::' . $minLevelUpper)) {
             throw new \InvalidArgumentException("Invalid minimum log level specified: {$minLevel}");
        }
        $this->minLevelValue = $this->logLevels[$minLevel] ?? $this->logLevels[LogLevel::DEBUG];

        // Ensure log directory exists and is writable
        $logDir = dirname($this->logFilePath);
        if (!is_dir($logDir)) {
            if (!@mkdir($logDir, 0755, true) && !is_dir($logDir)) {
                 throw new \RuntimeException("Failed to create log directory: {$logDir}");
            }
        }
         if (!is_writable($logDir)) {
             throw new \RuntimeException("Log directory is not writable: {$logDir}");
         }
         // Ensure log file exists and is writable (or can be created)
         if (!file_exists($this->logFilePath)) {
              if (!@touch($this->logFilePath)) {
                   throw new \RuntimeException("Log file does not exist and could not be created: {$this->logFilePath}");
              }
              @chmod($this->logFilePath, 0644); // Set appropriate permissions
         }
         if (!is_writable($this->logFilePath)) {
              throw new \RuntimeException("Log file is not writable: {$this->logFilePath}");
         }
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level Log level (string from Psr\Log\LogLevel).
     * @param string|Stringable $message Log message.
     * @param array $context Context data.
     * @return void
     * @throws \Psr\Log\InvalidArgumentException If log level is invalid.
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (!isset($this->logLevels[$level])) {
            throw new \Psr\Log\InvalidArgumentException("Invalid log level specified: {$level}");
        }

        // Check if the level is high enough to be logged
        if ($this->logLevels[$level] < $this->minLevelValue) {
            return;
        }

        // Interpolate context into the message string
        $interpolatedMessage = $this->interpolate((string) $message, $context);

        // Format the log entry
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i:s.u'); // Added microseconds
        $logEntry = sprintf(
            "[%s] %s.%s: %s %s\n",
            $timestamp,
            config('app.env', 'production'), // Add environment
            strtoupper($level),
            $interpolatedMessage,
            !empty($context) ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : ''
        );

        // Append to the log file
        // Use LOCK_EX for basic concurrency control
        @file_put_contents($this->logFilePath, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Interpolates context values into the message placeholders.
     * Placeholders are like {key}.
     *
     * @param string $message The message with placeholders.
     * @param array $context The context data.
     * @return string The interpolated message.
     */
    private function interpolate(string $message, array $context = []): string
    {
        // build a replacement array with braces around the context keys
        $replace = [];
        foreach ($context as $key => $val) {
            // check that the value can be cast to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            } elseif (is_object($val)) {
                 // Handle objects without __toString (e.g., Exceptions)
                 if ($val instanceof \Throwable) {
                      $replace['{' . $key . '}'] = '[Exception ' . get_class($val) . ': ' . $val->getMessage() . ' in ' . $val->getFile() . ':' . $val->getLine() . ']';
                 } else {
                      $replace['{' . $key . '}'] = '[object ' . get_class($val) . ']';
                 }
            } elseif (is_array($val)) {
                 $replace['{' . $key . '}'] = '[array]'; // Simple representation for arrays
            } else {
                 $replace['{' . $key . '}'] = '[unstringable]';
            }
        }

        // interpolate replacement values into the message and return
        return strtr($message, $replace);
    }
}
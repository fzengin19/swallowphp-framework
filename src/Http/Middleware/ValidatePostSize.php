<?php

namespace SwallowPHP\Framework\Http\Middleware;

use Closure;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\PayloadTooLargeException;

class ValidatePostSize extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $method = strtoupper($request->getMethod());
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        // 1) Detect oversized payloads via Content-Length vs post_max_size
        $contentLength = $request->server('CONTENT_LENGTH');
        $contentLength = is_numeric($contentLength) ? (int) $contentLength : 0;

        $postMaxBytes = $this->iniSizeToBytes((string) ini_get('post_max_size'));
        if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
            throw new PayloadTooLargeException(
                'The request payload exceeds the server post_max_size limit.'
            );
        }

        // 2) Detect upload-specific errors (when PHP created $_FILES entries)
        if (!empty($_FILES) && is_array($_FILES)) {
            if ($this->hasUploadSizeError($_FILES)) {
                throw new PayloadTooLargeException(
                    'Uploaded file exceeds the allowed size limit.'
                );
            }
        }

        return $next($request);
    }

    private function hasUploadSizeError(array $files): bool
    {
        // $_FILES can be nested and/or multi-file arrays.
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }

            if (array_key_exists('error', $file)) {
                $err = $file['error'];

                // Multi-upload: error is an array
                if (is_array($err)) {
                    foreach ($err as $subErr) {
                        if ((int) $subErr === UPLOAD_ERR_INI_SIZE || (int) $subErr === UPLOAD_ERR_FORM_SIZE) {
                            return true;
                        }
                    }
                } else {
                    if ((int) $err === UPLOAD_ERR_INI_SIZE || (int) $err === UPLOAD_ERR_FORM_SIZE) {
                        return true;
                    }
                }
            }

            // Nested file structures (rare but possible)
            foreach ($file as $v) {
                if (is_array($v) && $this->hasUploadSizeError([$v])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Convert ini size strings like "2M", "128K", "1G" into bytes.
     * Returns 0 when unlimited/invalid.
     */
    private function iniSizeToBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }

        // Numeric bytes already
        if (is_numeric($value)) {
            return max(0, (int) $value);
        }

        if (!preg_match('/^(\d+(?:\.\d+)?)\s*([KMGTP]?)B?$/i', $value, $m)) {
            return 0;
        }

        $number = (float) $m[1];
        $unit = strtoupper($m[2] ?? '');

        $multiplier = match ($unit) {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
            'P' => 1024 ** 5,
            default => 1,
        };

        $bytes = (int) floor($number * $multiplier);
        return max(0, $bytes);
    }
}



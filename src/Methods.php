<?php

use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Foundation\Env;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use SwallowPHP\Framework\Database\Model; // Though not directly used here
use SwallowPHP\Framework\Routing\Router;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException; // Import PHPMailer exception
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Request; // Updated Request namespace
use SwallowPHP\Framework\Contracts\CacheInterface; // Add CacheInterface use statement
use SwallowPHP\Framework\Session\SessionManager; // Import SessionManager
use Psr\Log\LoggerInterface; // Import LoggerInterface
use Psr\Log\LogLevel; // Import LogLevel

if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     * @param  array|string|null  $key
     * @param  mixed  $default
     * @return mixed|\SwallowPHP\Framework\Foundation\Config
     */
    function config($key = null, $default = null)
    {
        $config = App::container()->get(\SwallowPHP\Framework\Foundation\Config::class);
        if (is_null($key)) { return $config; }
        if (is_array($key)) {
            foreach ($key as $k => $v) { $config->set($k, $v); }
            return null;
        }
        return $config->get($key, $default);
    }
}

if (!function_exists('env')) {
    function env($key, $default = null)
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('shortenText')) {
    function shortenText($text, $length)
    {
        return mb_strlen($text) <= $length ? $text : mb_substr(strip_tags($text), 0, $length) . '...';
    }
}

if (!function_exists('method')) {
    function method($method)
    {
        echo '<input type="hidden" name="_method" value="' . htmlspecialchars($method, ENT_QUOTES, 'UTF-8') . '">';
    }
}

if (!function_exists('route')) {
    function route($name, $params = [])
    {
        return App::container()->get(Router::class)->getRouteByName($name, $params);
    }
}

if (!function_exists('slug')) {
    function slug($value)
    {
        $trMap = ['ç' => 'c','Ç' => 'C','ğ' => 'g','Ğ' => 'G','ı' => 'i','İ' => 'I','ö' => 'o','Ö' => 'O','ş' => 's','Ş' => 'S','ü' => 'u','Ü' => 'U'];
        $value = strtr($value, $trMap);
        $value = preg_replace('/[\p{P}+]/u', '-', $value);
        $value = preg_replace('/\s+/', '-', $value);
        $value = preg_replace('/[^a-zA-Z0-9-]+/', '', $value);
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/-{2,}/', '-', $value);
        return trim($value, '-');
    }
}

if (!function_exists('redirectToRoute')) {
    function redirectToRoute($urlName, $params = [])
    {
        header('Location: ' . App::container()->get(Router::class)->getRouteByName($urlName, $params));
        exit();
    }
}

if (!function_exists('mailto')) {
    function mailto($to, $subject, $message, $headers = []): bool // Added return type hint
    {
        // Get logger instance early, handle potential failure
        $logger = null;
        try {
            $logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) {
            error_log("Failed to get Logger in mailto() helper: " . $e->getMessage());
            // Decide if mail sending should proceed without logging, or fail? Fail for safety.
            return false;
        }

        $mail = new PHPMailer(true);
        try {
            // Get configuration from config('mail.mailers.smtp.*') and config('mail.from')
            $smtpConfig = config('mail.mailers.smtp', []);
            $fromConfig = config('mail.from', []);

            $mail->Timeout = config('mail.timeout', 10);
            $mail->SMTPAutoTLS = $smtpConfig['autotls'] ?? false;
            $mail->isSMTP();
            $mail->Host = $smtpConfig['host'] ?? null;
            $mail->SMTPAuth = ($smtpConfig['username'] ?? null) !== null; // Enable auth if username is set
            $mail->Username = $smtpConfig['username'] ?? null;
            $mail->Password = $smtpConfig['password'] ?? null;
            $mail->SMTPSecure = $smtpConfig['encryption'] ?? false; // false, 'tls', 'ssl'
            $mail->Port = $smtpConfig['port'] ?? 587;
            $mail->setFrom($fromConfig['address'] ?? 'hello@example.com', $fromConfig['name'] ?? 'Example');
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->CharSet = 'UTF-8';
            foreach ($headers as $key => $value) {
                $mail->addCustomHeader($key, $value);
            }

            $mail->send();
            // No need to check ErrorInfo, PHPMailer throws Exception on error when constructed with true

            return true;
        } catch (PHPMailerException $e) { // Catch specific PHPMailer exception
            $logger->error("Mail sending failed (PHPMailer): " . $e->errorMessage(), ['exception' => $e]);
            return false;
        } catch (\Exception $e) { // Catch other potential exceptions
            $logger->error("Mail sending failed (General): " . $e->getMessage(), ['exception' => $e]);
            return false;
        }
    }
}

if (!function_exists('request')) {
    function request(): Request // Add return type hint
    {
        return App::container()->get(Request::class);
    }
}

if (!function_exists('formatDateForHumans')) {
    /**
     * Formats a datetime string or object into a human-readable relative time difference.
     * @param string|\DateTime|null $datetimeInput
     * @return string
     */
    function formatDateForHumans(string|\DateTime|null $datetimeInput): string
    {
        if (!$datetimeInput) return '';
        $now = time();
        try {
            $then = ($datetimeInput instanceof \DateTime) ? $datetimeInput : new \DateTime($datetimeInput);
        } catch (\Exception $e) {
            return is_string($datetimeInput) ? htmlspecialchars($datetimeInput, ENT_QUOTES, 'UTF-8') : ''; // Sanitize output
        }
        $thenTimestamp = $then->getTimestamp();
        $diff = $now - $thenTimestamp;
        if ($diff < 60) { return "$diff saniye önce"; }
        elseif ($diff < 3600) { return floor($diff / 60) . ' dakika önce'; }
        elseif ($diff < 86400) { return floor($diff / 3600) . ' saat önce'; }
        elseif ($diff < 604800) { return floor($diff / 86400) . ' gün önce'; }
        else { return $then->format('d F Y'); } // Consider localizing format
    }
}

if (!function_exists('hasRoute')) {
    function hasRoute($name)
    {
        return App::container()->get(Router::class)->hasRoute($name);
    }
}

if (!function_exists('redirect')) {
    function redirect($uri, $code = 302): void // Add return type hint
    {
        header('Location: ' . $uri, true, $code);
        exit();
    }
}

if (!function_exists('send')) {
    // This function seems intended for debugging, use dd() or similar instead.
    function send($data): void
    {
        echo '<pre>';
        print_r($data);
        echo '</pre>';
    }
}

if (!function_exists('webpImage')) {
    function webpImage($source, $quality = 75, $removeOld = false, $fileName = null): string // Added return type hint
    {
        // Needs error handling and potentially configuration for destination path
        if (!extension_loaded('gd')) { error_log('GD extension is not loaded for webpImage'); return $source; }
        if (!file_exists($source) || !is_readable($source)) { error_log("Source file not found or not readable: {$source}"); return $source; }

        $destinationDir = defined('BASE_PATH') ? constant('BASE_PATH') . '/public/files' : 'files';
        // Attempt to create directory if it doesn't exist
        if (!is_dir($destinationDir)) {
            // Check mkdir result and existence after attempt
            if (!mkdir($destinationDir, 0755, true) && !is_dir($destinationDir)) {
                error_log("Failed to create destination directory: {$destinationDir}");
                return $source; // Return original source on directory creation failure
            }
        }
        // Check writability after ensuring directory exists
        if (!is_writable($destinationDir)) {
             error_log("Destination directory not writable: {$destinationDir}");
             return $source;
        }

        $name = $fileName ?? pathinfo($source, PATHINFO_FILENAME) . '_' . uniqid() . '.webp'; // Use original filename base
        $destination = $destinationDir . '/' . $name;

        $info = getimagesize($source); // Remove error suppression
        if (!$info) { error_log("Could not get image size: {$source}"); return $source; }

        $image = null;
        switch ($info['mime']) {
            // Remove error suppression, check return values
            case 'image/jpeg': $image = imagecreatefromjpeg($source); break;
            case 'image/gif': $image = imagecreatefromgif($source); break;
            case 'image/png': $image = imagecreatefrompng($source); break;
            case 'image/webp': $image = imagecreatefromwebp($source); break; // Already webp? Maybe just return?
            default: error_log("Unsupported image type: {$info['mime']}"); return $source;
        }
        if (!$image) { error_log("Could not create image resource from: {$source}"); return $source; }

        // Handle transparency for PNG and GIF (WebP supports alpha)
        if ($info['mime'] === 'image/png' || $info['mime'] === 'image/gif') {
            imagepalettetotruecolor($image);
            imagealphablending($image, false); // Important: disable blending
            imagesavealpha($image, true); // Important: save alpha channel
        }

        $success = imagewebp($image, $destination, $quality); // Remove error suppression
        imagedestroy($image);

        if (!$success) {
            error_log("Failed to create webp image at: {$destination}");
            return $source;
        }

        if ($removeOld) {
            if (!unlink($source)) { // Check unlink result
                error_log("Failed to remove original image: {$source}");
            }
        }
        // Return the relative path or just the filename? Returning filename for now.
        return $name;
    }
}

if (!function_exists('getFile')) {
    function getFile($name): string // Added return type hint
    {
        // This assumes 'files' is directly under the public directory accessible via APP_URL
        // Use config('app.url') instead of env() directly
        return rtrim(config('app.url', 'http://localhost'), '/') . '/files/' . ltrim($name, '/');
    }
}

if (!function_exists('db')) {
    function db(): Database // Add return type hint
    {
        return App::container()->get(Database::class);
    }
}

if (!function_exists('sendJson')) {
    function sendJson($data, $status = 200): void // Add return type hint
    {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=UTF-8');
            http_response_code($status);
        }
        // Ensure JSON is valid UTF-8
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT);
        // Consider adding exit() here if this should always terminate the script
    }
}

if (!function_exists('cache')) {
    /**
     * Get the available cache instance.
     * If $driver is null, the default driver will be returned.
     *
     * @param string|null $driver Specify a driver name (e.g., 'file', 'sqlite').
     * @return CacheInterface
     * @throws \RuntimeException If the specified driver cannot be resolved.
     */
    function cache(?string $driver = null): CacheInterface
    {
        // Use CacheManager to resolve the specific or default driver
        // Need to ensure CacheManager class exists
        if (!class_exists(\SwallowPHP\Framework\Cache\CacheManager::class)) {
             throw new \RuntimeException('CacheManager class not found.');
        }
        return \SwallowPHP\Framework\Cache\CacheManager::driver($driver);
    }
}

if (!function_exists('getIp')) {
    function getIp(): ?string // Add return type hint
    {
        return request()->getClientIp();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field.
     */
    function csrf_field(): void
    {
        $logger = null;
        try {
            $logger = App::container()->get(LoggerInterface::class); // Get logger for errors
            if (!class_exists(\SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::class)) {
                 if ($logger) $logger->error('CSRF Middleware Class Not Found');
                 echo '<!-- CSRF Middleware Class Not Found -->'; return;
            }
            $token = \SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::getToken();
            echo '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        } catch (\RuntimeException $e) {
            // Log session start error using PSR-3 logger
            if ($logger) $logger->error("CSRF Field Error: " . $e->getMessage(), ['exception' => $e]);
            else error_log("CSRF Field Error (Logger unavailable): " . $e->getMessage()); // Fallback
            echo '<!-- CSRF Token Error -->';
        } catch (\Throwable $t) {
             // Catch any other error during container access or token generation
             if ($logger) $logger->critical("Unexpected error in csrf_field(): " . $t->getMessage(), ['exception' => $t]);
             else error_log("Unexpected error in csrf_field() (Logger unavailable): " . $t->getMessage());
             echo '<!-- CSRF Token Error -->';
        }
    }
}

if (!function_exists('view')) {
    /**
     * Render a view file, optionally using a layout, and return an HTML response.
     * @param string $view The name of the view file (e.g., 'users.index').
     * @param array $data Data to pass to the view and layout.
     * @param string|null $layout The name of the layout file (optional).
     * @param int $status The HTTP status code.
     * @return \SwallowPHP\Framework\Http\Response
     * @throws \SwallowPHP\Framework\Exceptions\ViewNotFoundException
     * @throws \RuntimeException
     */
    function view(string $view, array $data = [], ?string $layout = null, int $status = 200): \SwallowPHP\Framework\Http\Response
    {
        $appViewPath = config('app.view_path', null);
        // Framework's default view path (assuming framework is in vendor)
        $frameworkViewPath = dirname(__DIR__, 2) . '/src/resources/views';
        // error_log('Framework View Path: ' . $frameworkViewPath); // DEBUGGING - Remove after confirmation

        // Function to find the view file in given paths
        $findViewFile = function(string $viewName, ?string $primaryPath, string $fallbackPath): ?string {
            $viewFilePath = str_replace('.', '/', $viewName) . '.php';

            // Check primary (app) path first
            if ($primaryPath && is_dir($primaryPath)) {
                 $fullPath = rtrim($primaryPath, '/\\') . '/' . $viewFilePath;
                 if (file_exists($fullPath)) {
                     return $fullPath;
                 }
            }
            // Check fallback (framework) path
            if (is_dir($fallbackPath)) {
                 $fullPath = rtrim($fallbackPath, '/\\') . '/' . $viewFilePath;
                 if (file_exists($fullPath)) {
                     return $fullPath;
                 }
            }
            return null;
        };

        // Find the main view file
        $viewFile = $findViewFile($view, $appViewPath, $frameworkViewPath);
        if ($viewFile === null) {
            throw new ViewNotFoundException("View [{$view}] not found in configured paths.");
        }

        // Render the main view content
        extract($data);
        ob_start();
        try { include $viewFile; } catch (\Throwable $e) { ob_end_clean(); throw $e; }
        $content = ob_get_clean();

        // Handle layout if specified
        if ($layout !== null) {
            $layoutFile = $findViewFile($layout, $appViewPath, $frameworkViewPath);
            if ($layoutFile === null) {
                throw new ViewNotFoundException("Layout [{$layout}] not found in configured paths.");
            }
            $slot = $content; // Make view content available as $slot in layout
            ob_start();
            // Extract data again for layout scope
            try { extract($data); include $layoutFile; } catch (\Throwable $e) { ob_end_clean(); throw $e; }
            $finalContent = ob_get_clean();
        } else {
            $finalContent = $content;
        }

        return \SwallowPHP\Framework\Http\Response::html($finalContent, $status);
    }
}

if (!function_exists('session')) {
    /**
     * Get the session manager instance or get/set a session value.
     * @param string|array|null $key Key to get/set or array to set multiple.
     * @param mixed $default Default value if getting a non-existent key.
     * @return \SwallowPHP\Framework\Session\SessionManager|mixed
     */
    function session(string|array|null $key = null, mixed $default = null)
    {
        $session = App::container()->get(SessionManager::class);
        if (is_null($key)) { return $session; }
        if (is_array($key)) {
            foreach ($key as $k => $v) { $session->put($k, $v); }
            return null;
        }
        return $session->get((string)$key, $default);
    }
}

if (!function_exists('flash')) {
    /**
     * Flash a message to the session.
     * @param string $key The key for the flash message.
     * @param mixed $value The message or data to flash.
     * @return void
     */
    function flash(string $key, mixed $value): void
    {
        session()->flash($key, $value);
    }
}

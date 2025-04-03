<?php

use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Foundation\Env;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use SwallowPHP\Framework\Database\Model; // Though not directly used here
use SwallowPHP\Framework\Routing\Router;
use PHPMailer\PHPMailer\PHPMailer;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Request; // Updated Request namespace
use SwallowPHP\Framework\Contracts\CacheInterface; // Add CacheInterface use statement
use SwallowPHP\Framework\Session\SessionManager; // Import SessionManager

if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * @param  array|string|null  $key
     * @param  mixed  $default
     * @return mixed|\SwallowPHP\Framework\Foundation\Config
     */
    function config($key = null, $default = null)
    {
        $config = App::container()->get(\SwallowPHP\Framework\Foundation\Config::class);
        if (is_null($key)) {
            return $config;
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                 $config->set($k, $v);
            }
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
    function mailto($to, $subject, $message, $headers = [])
    {
        $mail = new PHPMailer(true);
        try {
            $mail->Timeout = 10;
            $mail->SMTPAutoTLS = false;
            $mail->isSMTP();
            $mail->Host = env('SMTP_MAIL_HOST');
            $mail->SMTPAuth = true;
            $mail->Username = env('SMTP_MAIL_USERNAME');
            $mail->Password = env('SMTP_MAIL_PASSWORD');
            $mail->SMTPSecure = false;
            $mail->Port = env('SMTP_MAIL_PORT');
            $mail->setFrom(env('SMTP_MAIL_FROM_ADDRESS'), env('SMTP_MAIL_FROM_NAME'));
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            $mail->CharSet = 'UTF-8';
            foreach ($headers as $key => $value) {
                $mail->addCustomHeader($key, $value);
            }
            $mail->send();
            if ($mail->ErrorInfo) {
                throw new Exception($mail->ErrorInfo);
            }
            return true;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('printVariable')) {
    function printVariable(string $variableName)
    {
        if (isset(${$variableName})) {
            echo ${$variableName};
        }
    }
}

if (!function_exists('removeDuplicates')) {
    function removeDuplicates($array, $excludeValues)
    {
        $result = [];
        $uniqueValues = [];
        foreach ($array as $value) {
            if (!in_array($value, $uniqueValues) || in_array($value, $excludeValues)) {
                $result[] = $value;
                $uniqueValues[] = $value;
            }
        }
        return $result;
    }
}

if (!function_exists('request')) {
    function request()
    {
        return App::container()->get(Request::class);
    }
}

if (!function_exists('formatDateForHumans')) {
    /**
     * Formats a datetime string or object into a human-readable relative time difference.
     *
     * @param string|\DateTime|null $datetimeInput The datetime string or DateTime object.
     * @return string The formatted string or the original input on error.
     */
    function formatDateForHumans(string|\DateTime|null $datetimeInput): string
    {
        if (!$datetimeInput) return '';
        $now = time();
        try {
            $then = ($datetimeInput instanceof \DateTime) ? $datetimeInput : new \DateTime($datetimeInput);
        } catch (\Exception $e) {
            return is_string($datetimeInput) ? $datetimeInput : '';
        }
        $thenTimestamp = $then->getTimestamp();
        $diff = $now - $thenTimestamp;
        if ($diff < 60) { return "$diff saniye önce"; }
        elseif ($diff < 3600) { return floor($diff / 60) . ' dakika önce'; }
        elseif ($diff < 86400) { return floor($diff / 3600) . ' saat önce'; }
        elseif ($diff < 604800) { return floor($diff / 86400) . ' gün önce'; }
        else { return $then->format('d F Y'); }
    }
}

if (!function_exists('hasRoute')) {
    function hasRoute($name)
    {
        return App::container()->get(Router::class)->hasRoute($name);
    }
}

if (!function_exists('redirect')) {
    function redirect($uri, $code = 302) // Add default status code
    {
        header('Location: ' . $uri, true, $code);
        exit(); // Ensure script stops after redirect header
    }
}

if (!function_exists('send')) {
    function send($data)
    {
        print_r($data);
    }
}

if (!function_exists('webpImage')) {
    function webpImage($source, $quality = 75, $removeOld = false, $fileName = null)
    {
        if (!file_exists($source)) { return $source; }
        $name = $fileName ?? uniqid() . '.webp';
        $destination = 'files/' . $name; // Consider making 'files/' configurable
        $info = @getimagesize($source);
        if (!$info) return $source; // Not an image
        $isAlpha = false;
        switch ($info['mime']) {
            case 'image/jpeg': $image = @imagecreatefromjpeg($source); break;
            case 'image/gif': $image = @imagecreatefromgif($source); break;
            case 'image/png': $image = @imagecreatefrompng($source); break;
            case 'image/webp': $image = @imagecreatefromwebp($source); $isAlpha = true; break;
            default: return $source;
        }
        if (!$image) return $source; // Could not create image resource
        if ($isAlpha || $info['mime'] === 'image/png') { // Check PNG for alpha too
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }
        if (!@imagewebp($image, $destination, $quality)) {
            imagedestroy($image);
            return $source; // Failed to create webp
        }
        imagedestroy($image);
        if ($removeOld) { @unlink($source); }
        return $name;
    }
}

if (!function_exists('getFile')) {
    function getFile($name)
    {
        // Ensure APP_URL ends with a slash if needed, or handle base path better
        return rtrim(env('APP_URL', 'http://localhost'), '/') . '/files/' . ltrim($name, '/');
    }
}

if (!function_exists('db')) {
    function db()
    {
        return App::container()->get(Database::class);
    }
}

if (!function_exists('sendJson')) {
    function sendJson($data, $status = 200) // Add status code option
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
            http_response_code($status);
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
    }
}

if (!function_exists('cache')) {
    /**
     * Get the available cache instance.
     * @param string|null $driver Specify a driver or use default.
     * @return CacheInterface
     */
    function cache(?string $driver = null): CacheInterface
    {
        // If specific driver requested, CacheManager needs modification to handle this
        if ($driver) {
             // return App::container()->get(CacheManager::class)->driver($driver); // If manager is registered
             throw new \LogicException("Getting specific cache drivers via helper not implemented yet.");
        }
        return App::container()->get(CacheInterface::class);
    }
}

if (!function_exists('getIp')) {
    function getIp()
    {
        // Use Request service for consistency
        return request()->getClientIp();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field.
     */
    function csrf_field(): void
    {
        try {
            if (!class_exists(\SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::class)) {
                 echo '<!-- CSRF Middleware Class Not Found -->'; return;
            }
            $token = \SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::getToken();
            echo '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        } catch (\RuntimeException $e) {
            error_log("CSRF Field Error: " . $e->getMessage());
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
     * @return \SwallowPHP\Framework\Http\Response
     * @throws \SwallowPHP\Framework\Exceptions\ViewNotFoundException
     * @throws \RuntimeException
     */
    function view(string $view, array $data = [], ?string $layout = null): \SwallowPHP\Framework\Http\Response
    {
        $viewPath = config('app.view_path', '');
        if (empty($viewPath) || !is_dir($viewPath)) {
            throw new \RuntimeException("View path is not configured or invalid in config/app.php (app.view_path). Path: " . ($viewPath ?: 'Not Set'));
        }
        $viewFile = $viewPath . '/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new ViewNotFoundException("View file not found: {$viewFile}");
        }
        extract($data);
        ob_start();
        try { include $viewFile; } catch (\Throwable $e) { ob_end_clean(); throw $e; }
        $content = ob_get_clean();
        if ($layout !== null) {
            $layoutFile = $viewPath . '/' . str_replace('.', '/', $layout) . '.php';
            if (!file_exists($layoutFile)) { throw new ViewNotFoundException("Layout file not found: {$layoutFile}"); }
            $slot = $content;
            ob_start();
            try { extract($data); include $layoutFile; } catch (\Throwable $e) { ob_end_clean(); throw $e; }
            $finalContent = ob_get_clean();
        } else {
            $finalContent = $content;
        }
        return \SwallowPHP\Framework\Http\Response::html($finalContent);
    }
}

// <<<--- YENİ FONKSİYONLAR --- >>>

if (!function_exists('session')) {
    /**
     * Get the session manager instance or get/set a session value.
     *
     * @param string|array|null $key Key to get/set or array to set multiple.
     * @param mixed $default Default value if getting a non-existent key.
     * @return \SwallowPHP\Framework\Session\SessionManager|mixed
     */
    function session(string|array|null $key = null, mixed $default = null)
    {
        $session = App::container()->get(SessionManager::class);

        if (is_null($key)) {
            return $session; // Return the manager instance
        }

        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $session->put($k, $v); // Set multiple values
            }
            return null; // Return nothing when setting
        }

        // If $default is not null, it might be ambiguous whether getting or setting.
        // Let's stick to: session('key') gets, session(['key' => 'val']) sets.
        return $session->get((string)$key, $default); // Get a value
    }
}

if (!function_exists('flash')) {
    /**
     * Flash a message to the session.
     *
     * @param string $key The key for the flash message.
     * @param mixed $value The message or data to flash.
     * @return void
     */
    function flash(string $key, mixed $value): void
    {
        // Use the session() helper to get the manager instance
        session()->flash($key, $value);
    }
}

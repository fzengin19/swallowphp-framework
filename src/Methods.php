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





if (!function_exists('config')) {
    /**
     * Get / set the specified configuration value.
     *
     * If an array is passed as the key, we will assume you want to set an array of values.
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
            // Set array of configuration values
            foreach ($key as $k => $v) {
                 $config->set($k, $v);
            }
            return null; // Or return void? Or true?
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
        // Get Router instance from container
        return App::container()->get(Router::class)->getRouteByName($name, $params);
    }
}

if (!function_exists('slug')) {
    function slug($value)
    {
        $trMap = [
            'ç' => 'c',
            'Ç' => 'C',
            'ğ' => 'g',
            'Ğ' => 'G',
            'ı' => 'i',
            'İ' => 'I',
            'ö' => 'o',
            'Ö' => 'O',
            'ş' => 's',
            'Ş' => 'S',
            'ü' => 'u',
            'Ü' => 'U'
        ];

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
        // Get Router instance from container
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
        // Get Request instance from container
        return App::container()->get(Request::class);
    }
}

if (!function_exists('formatDateForHumans')) {
    function formatDateForHumans($datetimeString)
    {
        $now = time();
        try {
            $then = new \DateTime($datetimeString);
        } catch (\Exception $e) {
            // Handle invalid datetime string
            return $datetimeString; // Return original string on error
        }
        $thenTimestamp = $then->getTimestamp();
        $diff = $now - $thenTimestamp;

        if ($diff < 60) {
            return "$diff saniye önce";
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' dakika önce';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' saat önce';
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . ' gün önce';
        } else {
            return $then->format('d F Y'); // Example: 27 March 2025
        }
    }
}



if (!function_exists('hasRoute')) {
    function hasRoute($name)
    {
        // Get Router instance from container
        return App::container()->get(Router::class)->hasRoute($name);
    }
}

if (!function_exists('redirect')) {
    function redirect($uri, $code)
    {
        header('Location: ' . $uri, true, $code);
    }
}

if (!function_exists('send')) {
    function send($data)
    {
        print_r($data);
        // die; // Avoid using die in helper functions
    }
}

if (!function_exists('webpImage')) {
    function webpImage($source, $quality = 75, $removeOld = false, $fileName = null)
    {
        if (!file_exists($source)) {
            return $source;
        }

        $name = $fileName ?? uniqid() . '.webp';
        $destination = 'files/' . $name;

        $info = getimagesize($source);
        $isAlpha = false;

        switch ($info['mime']) {
            case 'image/jpeg':
                $image = imagecreatefromjpeg($source);
                break;
            case 'image/gif':
                $image = imagecreatefromgif($source);
                break;
            case 'image/png':
                $image = imagecreatefrompng($source);
                break;
            case 'image/webp':
                $image = imagecreatefromwebp($source);
                $isAlpha = true;
                break;
            default:
                return $source;
        }

        if ($isAlpha) {
            imagepalettetotruecolor($image);
            imagealphablending($image, true);
            imagesavealpha($image, true);
        }

        imagewebp($image, $destination, $quality);
        if ($removeOld) {
            unlink($source);
        }

        return $name;
    }
}



if (!function_exists('getFile')) {
    function getFile($name)
    {
        return env('APP_URL') . '/files/' . $name;
    }
}

if (!function_exists('db')) {
    function db()
    {
        // Get Database instance from container
        return App::container()->get(Database::class);
    }
}

if (!function_exists('sendJson')) {
    function sendJson($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        // die; // Avoid using die in helper functions
    }
}


if (!function_exists('cache')) {
    /**
     * Get the available cache instance.
     *
     * @param string|null $driver Specify a driver or use default.
     * @return CacheInterface
     */
    function cache(?string $driver = null): CacheInterface
    {
        // Get Cache instance from container via manager
        // If specific driver needed, CacheManager::driver($driver) could be used if manager is adapted
        return App::container()->get(CacheInterface::class);
    }
}


if (!function_exists('getIp')) {
    function getIp()
    {
        return request()->getClientIp();
    }
}


if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field.
     *
     * @return void
     */
    function csrf_field()
    {
        // Ensure the middleware class is available
        if (!class_exists(\SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::class)) {
             // Handle error appropriately, maybe log or throw an exception
             // For now, we'll output a comment in the HTML
             echo '<!-- CSRF Middleware not found -->';
             return;
        }
        try {
            $token = \SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::getToken();
            echo '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        } catch (\RuntimeException $e) {
            // Handle session start error
            error_log("CSRF Field Error: " . $e->getMessage());
            echo '<!-- CSRF Token Error -->';
        }
    }
}



if (!function_exists('view')) {
    /**
     * Render a view file, optionally using a layout, and return an HTML response.
     *
     * @param string $view The name of the view file (e.g., 'users.index' maps to 'users/index.php').
     * @param array $data Data to pass to the view and layout.
     * @param string|null $layout The name of the layout file to wrap the view in (optional).
     * @return \SwallowPHP\Framework\Http\Response
     * @throws \SwallowPHP\Framework\Exceptions\ViewNotFoundException If view or layout file is not found.
     */
    function view(string $view, array $data = [], ?string $layout = null): \SwallowPHP\Framework\Http\Response
    {
        $viewPath = config('app.view_path', '');
        if (empty($viewPath)) {
            throw new \RuntimeException("View path is not configured in config/app.php (app.view_path).");
        }

        // Convert dot notation to directory separator
        $viewFile = $viewPath . '/' . str_replace('.', '/', $view) . '.php';

        if (!file_exists($viewFile)) {
            throw new ViewNotFoundException("View file not found: {$viewFile}");
        }

        // Extract data for the view
        extract($data);

        // Render the main view content
        ob_start();
        try {
            include $viewFile;
        } catch (\Throwable $e) {
            ob_end_clean(); // Clean buffer on error during view include
            throw $e; // Re-throw the error
        }
        $content = ob_get_clean();

        // If a layout is specified, render the layout with the content
        if ($layout !== null) {
            $layoutFile = $viewPath . '/' . str_replace('.', '/', $layout) . '.php';
            if (!file_exists($layoutFile)) {
                throw new ViewNotFoundException("Layout file not found: {$layoutFile}");
            }

            // Make view content available to the layout (e.g., as $slot)
            $slot = $content; // You can name this variable differently if preferred

            ob_start();
            try {
                // Extract data again for the layout (layout might need same data)
                extract($data);
                include $layoutFile;
            } catch (\Throwable $e) {
                ob_end_clean(); // Clean buffer on error during layout include
                throw $e; // Re-throw the error
            }
            $finalContent = ob_get_clean();
        } else {
            $finalContent = $content;
        }

        // Return an HTML response object
        return \SwallowPHP\Framework\Http\Response::html($finalContent);
    }
}


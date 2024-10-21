<?php

use SwallowPHP\Framework\Database;
use SwallowPHP\Framework\Env;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use SwallowPHP\Framework\Model;
use SwallowPHP\Framework\Router;
use PHPMailer\PHPMailer\PHPMailer;
use SwallowPHP\Framework\App;

global $settings;

if (!function_exists('settings')) {
    function settings(): Model
    {
        global $settings;
        return $settings ?? ($settings = Model::table('settings')->first());
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

if (!function_exists('view')) {
    function view($view, $data = [])
    {
        $viewPath = str_replace('.', '/', $view);
        $viewFile = App::getViewDirectory() . $viewPath . '.php';

        if (!file_exists($viewFile)) {
            throw new ViewNotFoundException('view file does not exist (' . $viewFile . ')');
        }

        extract($data);

        ob_start();
        require $viewFile;
        return ob_get_clean();
    }
}

if (!function_exists('route')) {
    function route($name, $params = [])
    {
        return Router::getRouteByName($name, $params);
    }
}

if (!function_exists('_include')) {
    function _include($view)
    {
        $viewFile = '../views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new ViewNotFoundException('view file does not exist (' . $viewFile . ')');
        }
        require_once $viewFile;
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

if (!function_exists('addFlashMessage')) {
    function addFlashMessage($message, $status)
    {
        $_SESSION['_flash'] = [
            'message' => $message,
            'status' => $status
        ];
    }
}

if (!function_exists('displayFlashMessage')) {
    function displayFlashMessage()
    {
        if (isset($_SESSION['_flash'])) {
            $flashMessage = $_SESSION['_flash'];
            unset($_SESSION['_flash']);

            $messageClass = ($flashMessage['status'] === 'error') ? 'bg-red-100 border border-red-400 text-red-700' : 'bg-green-100 border border-green-400 text-green-700';
            $messageTitle = ($flashMessage['status'] === 'error') ? 'Hata!' : 'Başarılı!';
            $icon = ($flashMessage['status'] === 'error') ?
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-red-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>' :
                '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-green-600"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>';

            echo "<div class=\"$messageClass rounded p-4 mb-4\" role=\"alert\">
                <div class=\"flex items-center\">
                    <div class=\"mr-2\">$icon</div>
                    <div>
                        <strong class=\"font-bold\">$messageTitle</strong>
                        <span class=\"block sm:inline\">{$flashMessage['message']}</span>
                    </div>
                </div>
            </div>";
        }
    }
}

if (!function_exists('redirectToRoute')) {
    function redirectToRoute($urlName, $params = [])
    {
        header('Location: ' . Router::getRouteByName($urlName, $params));
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

if (!function_exists('_include_js')) {
    function _include_js($path, $version = false, $mode = 'default')
    {
        if (!in_array($mode, ['include', 'default', 'async'])) {
            throw new InvalidArgumentException('Invalid mode provided. Use "include", "default", or "async".');
        }

        $jsFile = __DIR__ . '/../public/' . $path;
        if (!file_exists($jsFile)) {
            throw new Exception('JavaScript file not found (' . $jsFile . ')');
        }

        $version = $version === true ? time() : $version;
        $path .= $version !== false ? "?v=$version" : '';

        switch ($mode) {
            case 'include':
                echo '<script>' . file_get_contents($jsFile) . '</script>';
                break;
            case 'default':
                echo '<script src="' . Env::get('APP_URL') . '/' . $path . '"></script>';
                break;
            case 'async':
                echo '<script async src="' . Env::get('APP_URL') . '/' . $path . '"></script>';
                break;
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
        return Router::getRequest();
    }
}

if (!function_exists('formatDateForHumans')) {
    function formatDateForHumans($datetimeString)
    {
        $now = time();
        $then = strtotime($datetimeString);
        $diff = $now - $then;

        if ($diff < 60) {
            return "$diff saniye önce";
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' dakika önce';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' saat önce';
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . ' gün önce';
        } else {
            return strftime('%d %B %Y', $then);
        }
    }
}

if (!function_exists('_include_css')) {
    function _include_css($path, $version = false, $mode = 'default')
    {
        if (!in_array($mode, ['include', 'default', 'lazyload'])) {
            throw new InvalidArgumentException('Invalid mode provided. Use "include", "default", or "lazyload".');
        }

        $cssFile = __DIR__ . '/../public/' . $path;
        if (!file_exists($cssFile)) {
            throw new Exception('CSS file not found (' . $cssFile . ')');
        }

        $version = $version === true ? time() : $version;
        $path .= $version !== false ? "?v=$version" : '';

        switch ($mode) {
            case 'include':
                echo '<style>' . file_get_contents($cssFile) . '</style>';
                break;
            case 'default':
                echo '<link rel="stylesheet" href="' . Env::get('APP_URL') . '/' . $path . '">';
                break;
            case 'lazyload':
                echo '<link rel="stylesheet" href="' . Env::get('APP_URL') . '/' . $path . '" media="print" onload="this.media=\'all\'">';
                break;
        }
    }
}

if (!function_exists('hasRoute')) {
    function hasRoute($name)
    {
        return Router::hasRoute($name);
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
        die;
    }
}

if (!function_exists('webpImage')) {
    function webpImage($source, $quality = 75, $removeOld = false)
    {
        if (!file_exists($source)) {
            return $source;
        }

        $name = uniqid() . '.webp';
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

if (!function_exists('__include')) {
    function __include($view)
    {
        $viewFile = '../views/' . str_replace('.', '/', $view) . '.php';
        if (!file_exists($viewFile)) {
            throw new ViewNotFoundException('view file does not exist (' . $viewFile . ')');
        }
        require $viewFile;
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
        return new Database();
    }
}

if (!function_exists('sendJson')) {
    function sendJson($data)
    {
        header('Content-Type: application/json');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        die;
    }
}

if (!function_exists('getIp')) {
    function getIp()
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    }
}

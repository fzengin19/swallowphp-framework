<?php

use SwallowPHP\Framework\Database;
use SwallowPHP\Framework\Env;
use SwallowPHP\Framework\Exceptions\ViewNotFoundException;
use SwallowPHP\Framework\Model;
use SwallowPHP\Framework\Router;
use PHPMailer\PHPMailer\PHPMailer;
use SwallowPHP\Framework\App;



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
        return Router::getRouteByName($name, $params);
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


if (!function_exists('csrf_field')) {
    /**
     * Generate a CSRF token hidden input field.
     *
     * @return void
     */
    function csrf_field()
    {
        // Ensure the middleware class is available
        if (!class_exists(\SwallowPHP\Framework\Middleware\VerifyCsrfToken::class)) {
             // Handle error appropriately, maybe log or throw an exception
             // For now, we'll output a comment in the HTML
             echo '<!-- CSRF Middleware not found -->';
             return;
        }
        try {
            $token = \SwallowPHP\Framework\Middleware\VerifyCsrfToken::getToken();
            echo '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
        } catch (\RuntimeException $e) {
            // Handle session start error
            error_log("CSRF Field Error: " . $e->getMessage());
            echo '<!-- CSRF Token Error -->';
        }
    }
}


<?php


use Framework\Database;
use Framework\Env;
use Framework\Exceptions\ViewNotFoundException;
use Framework\Model;
use Framework\Request;
use Framework\Router;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
global $settings;



/**
 * Retrieves the settings from the database if not already loaded and returns them.
 *
 * @return FrameworkModel The settings retrieved from the database.
 */
function settings():Model
{    global $settings;
    if($settings == null)
    $settings = Model::table('settings')->first();
    return $settings;
}
/**
 * Retrieve the value of an environment variable.
 *
 * @param string $key The key of the environment variable
 * @param mixed $default The default value to return if the environment variable is not set
 * @return mixed The value of the environment variable, or the default value if the variable is not set
 */
function env($key, $default = null)
{
    return Env::get($key, $default);
}

function shortenText($text, $length) {
    // Check the length of the text
    if (mb_strlen($text) <= $length) {
        return $text; // If it doesn't exceed the length limit, return the text as it is
    } else {
        // Trim the text to the specified length and append "..." at the end
        $shortened_text = mb_substr(strip_tags($text), 0, $length) . '...';
        return $shortened_text;
    }
}

function method($method)
{
    echo '<input type="hidden" name="_method" value="' . $method . '"> </input>';
}

/**
 * Generates a view by rendering a PHP template file.
 *
 * @param string $view The name of the view file to render.
 * @param array $data The data to pass to the view file (optional).
 * @throws ViewNotFoundException If the specified view file does not exist.
 * @return string The HTML content generated by the view.
 */
function view($view, $data = [])
{
    $viewPath = str_replace('.', '/', $view);
    $viewFile =  '../views/' . $viewPath . '.php';
    if (!file_exists($viewFile)) {
        throw new ViewNotFoundException('view file does not exist  (' . $viewFile . ') ');
    }
    foreach ($data as $key => $value) {
        ${$key} = $value;
    }
    
    ob_start(); // Çıktı tamponunu başlat
    require $viewFile;
    $html = ob_get_clean(); // Önceki çıktıyı temizle ve sakla

    return $html;
}

/**
 * Retrieves the URI for a given route name.
 *
 * @param string $name The name of the route.
 * @throws RouteNotFoundException If the route is not found.
 * @return string The URI of the route.
 */
function route($name, $params = [])
{
    return Router::getRouteByName($name, $params);
}
/**
 * Includes a PHP view file.
 *
 * @param string $view The name of the view file to include.
 * @throws ViewNotFoundException If the view file cannot be found or included.
 */
function _include($view)
{
    $view = str_replace('.', '/', $view);
    $viewFile =  '../views/' . $view . '.php';
    if (!file_exists($viewFile)) {
        throw new ViewNotFoundException('view file does not exist  (' . $viewFile . ') ');
    }
    require_once $viewFile;
}
function slug($value)
{
    $trMap = array(
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
        'Ü' => 'U',
    );

    $value = strtr($value, $trMap);
    $value = trim($value);
    $value = preg_replace('/[\p{P}+]/u', '-', $value);
    $value = preg_replace('/\s+/', '-', $value);
    $value = preg_replace('/[^a-zA-Z0-9-]+/', '', $value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/-{2,}/', '-', $value);
    $value = trim($value, '-');
    return $value;
}

/**
 * Sets a flash message in the session variable.
 *
 * @param string $message The message to be stored.
 * @param string $status The status of the message.
 */
function addFlashMessage($message, $status)
{
    // Flash mesajlarını bir session değişkeninde sakla
    $_SESSION['_flash'] = [
        'message' => $message,
        'status' => $status
    ];
}

/**
 * Display the flash message from the session.
 *
 */
function displayFlashMessage()
{
    // Flash mesajlarını session'dan al
    if (isset($_SESSION['_flash'])) {
        $flashMessage = $_SESSION['_flash'];
        unset($_SESSION['_flash']);

        $messageClass = ($flashMessage['status'] === 'error') ? 'bg-red-100 border border-red-400 text-red-700' : 'bg-green-100 border border-green-400 text-green-700';
        $messageTitle = ($flashMessage['status'] === 'error') ? 'Hata!' : 'Başarılı!';
        $icon = ($flashMessage['status'] === 'error') ? '
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-red-600">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>' : '
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" class="w-6 h-6 text-green-600">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>';

        echo '<div class="' . $messageClass . ' rounded p-4 mb-4" role="alert">
            <div class="flex items-center">
                <div class="mr-2">' . $icon . '</div>
                <div>
                    <strong class="font-bold">' . $messageTitle . '</strong>
                    <span class="block sm:inline">' . $flashMessage['message'] . '</span>
                </div>
            </div>
        </div>';
    }
}
/**
 * Redirects to a specific route.
 *
 * @param string $urlName The name of the route to redirect to.
 * @param array $params (optional) Parameters to be passed to the route.
 * @throws RouteNotFoundException If the route with the given name is not found.
 * @return void
 */
function redirectToRoute($urlName, $params = [])
{
    header('Location: ' . Router::getRouteByName($urlName, $params));
    exit();
}



/**
 * Sends an email using the PHPMailer library.
 *
 * @param string $to The email address of the recipient.
 * @param string $subject The subject of the email.
 * @param string $message The body of the email.
 * @param array $headers Additional headers for the email.
 * @return bool Returns true if the email was sent successfully, false otherwise.
 */
function mailto($to, $subject, $message, $headers = [])
{
    $mail = new PHPMailer(true);

    try {
        // Sunucu ayarları
        $mail->Timeout = 10;
        $mail->SMTPAutoTLS = false;
        $mail->isSMTP(); // SMTP kullanılacak
        $mail->Host = env('SMTP_MAIL_HOST'); // SMTP sunucu adresi
        $mail->SMTPAuth = true; // SMTP kimlik doğrulama
        $mail->Username = env('SMTP_MAIL_USERNAME'); // SMTP kullanıcı adı
        $mail->Password = env('SMTP_MAIL_PASSWORD'); // SMTP parola
        $mail->SMTPSecure = false; // Güvenli bağlantı türü: tls veya ssl
        $mail->Port = env('SMTP_MAIL_PORT'); // SMTP bağlantı noktası

        // Gönderen bilgileri
        $mail->setFrom(env('SMTP_MAIL_FROM_ADDRESS'), env('SMTP_MAIL_FROM_NAME'));

        // Alıcı bilgileri
        $mail->addAddress($to);

        // E-posta içeriği
        $mail->isHTML(true); // HTML formatında e-posta
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->CharSet = 'UTF-8';

        // Ek başlıkları ekleme
        foreach ($headers as $key => $value) {
            $mail->addCustomHeader($key, $value);
        }

        // E-postayı gönder
        $mail->send();

        if ($mail->ErrorInfo) {
            throw new Exception($mail->ErrorInfo);
        }

        return true;
    } catch (Exception $e) {
        return false;
    }
}

function printVariable(string $variableName)
{
    if (isset(${$variableName}))
        echo ${$variableName};
}

/**
 * Includes a JavaScript file in the HTML output with different modes.
 *
 * @param string $path The path of the JavaScript file relative to the root directory.
 * @param bool|int|string $version (Optional) The version to append to the file path. If set to true, the current timestamp will be used as the version. Defaults to false.
 * @param string $mode The mode for including the JavaScript: 'include', 'default', or 'async'.
 *   - 'include': Embeds the JavaScript content directly in the HTML output.
 *   - 'default': Includes the JavaScript file using a script tag with a 'src' attribute.
 *   - 'async': Includes the JavaScript file using a script tag with 'async' attribute for asynchronous loading.
 * @throws JsFileNotFoundException If the JavaScript file is not found.
 * @throws InvalidArgumentException If an invalid mode is provided.
 * @return void
 */
function _include_js($path, $version = false, $mode = 'default')
{
    if ($mode !== 'include' && $mode !== 'default' && $mode !== 'async') {
        throw new InvalidArgumentException('Invalid mode provided. Use "include", "default", or "async".');
    }

    $jsFile = __DIR__. '/../public/' . $path;
    if (!file_exists($jsFile)) {
        throw new Exception('JavaScript file not found (' . $jsFile . ')');
    }

    if ($version === true) {
        $version = time();
    }

    if ($version !== false) {
        $path .= '?v=' . $version;
    }

    if ($mode === 'include') {
        // Include JavaScript content directly in HTML
        echo '<script>';
        echo file_get_contents($jsFile);
        echo '</script>';
    } elseif ($mode === 'default') {
        // Default mode: Include JavaScript using script tag
        echo '<script src="' . Env::get('APP_URL') . '/' . $path . '"></script>';
    } elseif ($mode === 'async') {
        // Asynchronous loading
        echo '<script async src="' . Env::get('APP_URL') . '/' . $path . '"></script>';
    }
}
function removeDuplicates($array, $excludeValues) {
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



function request(){
    return new Request();
}


function formatDateForHumans($datetimeString) {
    $now = time();
    $then = strtotime($datetimeString);
    $diff = $now - $then;

    if ($diff < 60) {
        return "$diff saniye önce";
    } elseif ($diff < 3600) {
        return floor($diff / 60) . " dakika önce";
    } elseif ($diff < 86400) {
        return floor($diff / 3600) . " saat önce";
    } elseif ($diff < 604800) {
        return floor($diff / 86400) . " gün önce";
    } else {
        return strftime("%d %B %Y", $then);
    }
}



/**
 * Includes a CSS file in the HTML document with different modes.
 *
 * @param string $path The path to the CSS file.
 * @param bool|int|string $version (Optional) The version of the CSS file. If set to true, it will use the current timestamp as the version. Defaults to false.
 * @param string $mode The mode for including the CSS: 'include', 'default', or 'lazyload'.
 *   - 'include': Embeds the CSS content directly in the HTML output.
 *   - 'default': Includes the CSS file using a link tag with a 'href' attribute.
 *   - 'lazyload': Includes the CSS file using a link tag with the 'media' attribute for lazy loading.
 * @throws CssFileNotFoundException If the CSS file is not found.
 * @throws InvalidArgumentException If an invalid mode is provided.
 * @return void
 */
function _include_css($path, $version = false, $mode = 'default')
{
    if ($mode !== 'include' && $mode !== 'default' && $mode !== 'lazyload') {
        throw new InvalidArgumentException('Invalid mode provided. Use "include", "default", or "lazyload".');
    }

    $cssFile = __DIR__. '/../public/' . $path;
    if (!file_exists($cssFile)) {
        throw new Exception('Css file not found (' . $cssFile . ')');
    }

    if ($version === true) {
        $version = time();
    }

    if ($version !== false) {
        $path .= '?v=' . $version;
    }

    if ($mode === 'include') {
        // Include CSS content directly in HTML
        echo '<style>';
        echo file_get_contents($cssFile);
        echo '</style>';
    } elseif ($mode === 'default') {
        // Default mode: Include CSS using link tag
        echo '<link rel="stylesheet" href="' . Env::get('APP_URL') . '/' . $path . '">';
    } else {
        // Lazy load with the "media" attribute
        $lazyLoadAttribute = 'media="print" onload="this.media=\'all\'"';
        echo '<link rel="stylesheet" href="' . Env::get('APP_URL') . '/' . $path . '" ' . $lazyLoadAttribute . '>';
    }
}


/**
 * Check if a route with the given name exists in the Router.
 *
 * @param string $name The name of the route to check.
 * @return bool True if the route exists, false otherwise.
 */
function hasRoute($name)
{
    return Router::hasRoute($name);
}

/**
 * Sends a redirect header to the client with the specified URI and HTTP status code.
 *
 * @param string $uri The URI to redirect to.
 * @param int $code The HTTP status code to send with the redirect.
 * @return void
 */
function redirect($uri, $code)
{
    header('Location: ' . $uri, true, $code);
}

/**
 * Sends the provided data to the output buffer and terminates the script.
 *
 * @param mixed $data The data to be sent to the output buffer.
 * @return void
 */
function send($data)
{
    print_r($data);
    die;
}

// /**
//  * Sends an email using the PHPMailer library.
//  *
//  * @param string $to The email address of the recipient.
//  * @param string $subject The subject of the email.
//  * @param string $message The body of the email.
//  * @return bool Returns true if the email was sent successfully, false otherwise.
//  */
// function mailto($to, $subject, $message)
// {
//     $mail = new PHPMailer(true);

//     try {
//         // Sunucu ayarları
//         $mail->isSMTP(); // SMTP kullanılacak
//         $mail->Host = Env::get('SMTP_MAIL_HOST'); // SMTP sunucu adresi
//         $mail->SMTPAuth = true; // SMTP kimlik doğrulama
//         $mail->Username = Env::get('SMTP_MAIL_USERNAME'); // SMTP kullanıcı adı
//         $mail->Password = Env::get('SMTP_MAIL_PASSWORD'); // SMTP parola
//         $mail->SMTPSecure = Env::get('SMTP_MAIL_ENCRYPTION'); // Güvenli bağlantı türü: tls veya ssl
//         $mail->Port = Env::get('SMTP_MAIL_PORT'); // SMTP bağlantı noktası

//         // Gönderen bilgileri
//         $mail->setFrom(Env::get('SMTP_MAIL_FROM_ADDRESS'), Env::get('SMTP_MAIL_FROM_NAME'));

//         // Alıcı bilgileri
//         $mail->addAddress($to);
//         // E-posta içeriği
//         $mail->isHTML(true); // HTML formatında e-posta
//         $mail->Subject = $subject;
//         $mail->Body = $message;

//         // E-postayı gönder
//         $mail->send();
//         if ($mail->ErrorInfo) {
//             throw new Exception($mail->ErrorInfo);
//         }
//         return true;
//     } catch (Exception $e) {
//         return false;
//     }
// }

/**
 * Generates a WebP image from the given source image file.
 *
 * @param string $source The path to the source image file.
 * @param int $quality The quality of the generated WebP image (default is 80).
 * @param bool $removeOld Whether to remove the old source image file after generating the WebP image (default is false).
 * @return string The name of the generated WebP image file.
 */
function webpImage($source, $quality = 75, $removeOld = false)
{

    if (!file_exists($source)) {
        return $source;
    }

    $name = uniqid() . '.webp';
    $destination = 'files/' . $name;

    $info = getimagesize($source);
    $isAlpha = false;

    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/gif') {
        $image = imagecreatefromgif($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } elseif ($info['mime'] == 'image/webp') {
        $image = imagecreatefromwebp($source);
    } else {
        return $source;
    }

    if ($info['mime'] == 'image/webp') {
        $isAlpha = true;
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

function __include($view)
{
    $viewPath = str_replace('.', '/', $view);
    $viewFile =  '../views/' . $viewPath . '.php';
    if (!file_exists($viewFile)) {
        throw new ViewNotFoundException('view file does not exist  (' . $viewFile . ') ');
    }
    require $viewFile;
}

/**
 * Get the URL of a file based on its name.
 *
 * @param string $name The name of the file.
 * @return string The URL of the file.
 */
function getFile($name)
{
    return env('APP_URL') . '/files/' . $name;
}

/**
 * Creates and returns a new instance of the Database class.
 *
 * @return Database A new instance of the Database class.
 */
function db()
{
    return new Database();
}



/**
 * Sends the given data as JSON response and terminates the script execution.
 *
 * @param mixed $data The data to be sent as JSON.
 * @return void This function does not return any value.
 */
function sendJson($data)
{
    header('Content-Type: application/json');
    print_r(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT));
    die;
}

/**
 * Returns the IP address of the client making the request.
 *
 * @return string The IP address of the client as a string.
 */
function getIp()
{
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        return $_SERVER['HTTP_X_FORWARDED_FOR'];
    }

    return $_SERVER['REMOTE_ADDR'];
}

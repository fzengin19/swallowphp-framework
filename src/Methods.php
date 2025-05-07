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
        $trMap = ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U'];
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
    function redirectToRoute(string $urlName, array $params = []): void
    {
        $router = App::container()->get(Router::class);
        $url    = $router->getRouteByName($urlName, $params);

        // Queued cookie'leri gönder
        if (
            class_exists(\SwallowPHP\Framework\Http\Cookie::class)
            && method_exists(\SwallowPHP\Framework\Http\Cookie::class, 'sendQueuedCookies')
        ) {
            \SwallowPHP\Framework\Http\Cookie::sendQueuedCookies();
        }

        // Response sınıfını kullanarak yönlendir ve header + exit yerine send() metodunu kullan
        \SwallowPHP\Framework\Http\Response::redirect($url)
            ->send();

        exit();
    }
}

if (!function_exists('mailto')) {
    function mailto($to, $subject, $message, $headers = []): bool
    {
        // Logger setup
        try {
            $logger = App::container()->get(LoggerInterface::class);
        } catch (\Throwable $e) {
            error_log("Failed to get Logger in mailto(): " . $e->getMessage());
            return false;
        }

        // Convert recipients to array
        $recipients = is_array($to) ? $to : [$to];
        $total = count($recipients);
        $logger->info("Mailto started", ['total_recipients' => $total, 'subject' => $subject]);

        // Validate recipients
        $validRecipients = [];
        foreach ($recipients as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $validRecipients[] = $email;
            } else {
                $logger->warning("Invalid email address: $email");
            }
        }

        $validTotal = count($validRecipients);
        if ($validTotal === 0) {
            $logger->error("No valid recipients found");
            return false;
        }
        $logger->info('Found ' . $validTotal . ' valid email address');


        // Load configuration
        $smtpConfig  = config('mail.mailers.smtp', []);
        $fromConfig  = config('mail.from', []);
        $timeout     = config('mail.timeout', 10);
        $batchSize   = config('mail.max_recipients_per_mail', 50);

        $logger->info("SMTP configuration loaded", [
            'host'       => $smtpConfig['host'] ?? '',
            'port'       => $smtpConfig['port'] ?? 587,
            'encryption' => $smtpConfig['encryption'] ?? false,
            'auth'       => isset($smtpConfig['username']),
            'autotls'    => $smtpConfig['autotls'] ?? false,
            'timeout'    => $timeout,
            'batch_size' => $batchSize,
        ]);

        // Mail sending closure
        $sendMail = function ($mail, $batch) use ($logger, &$batchNo) {
            try {
                $mail->send();
                $logger->info("Batch #{$batchNo} sent successfully", ['batch_recipients' => $batch]);
                return true;
            } catch (PHPMailerException $e) {
                $logger->error("Batch #{$batchNo} failed (PHPMailer): " . $e->errorMessage(), ['exception' => $e]);
                return false;
            } catch (\Exception $e) {
                $logger->error("Batch #{$batchNo} failed (General): " . $e->getMessage(), ['exception' => $e]);
                return false;
            }
        };

        // Single recipient case
        if ($validTotal === 1) {
            $mail = new PHPMailer(true);
            $mail->Timeout      = $timeout;
            $mail->SMTPAutoTLS  = $smtpConfig['autotls'] ?? false;
            $mail->isSMTP();
            $mail->Host         = $smtpConfig['host'] ?? '';
            $mail->SMTPAuth     = isset($smtpConfig['username']);
            $mail->Username     = $smtpConfig['username'] ?? '';
            $mail->Password     = $smtpConfig['password'] ?? '';
            $mail->SMTPSecure   = $smtpConfig['encryption'] ?? false;
            $mail->Port         = $smtpConfig['port'] ?? 587;
            $mail->setFrom(
                $fromConfig['address'] ?? 'hello@example.com',
                $fromConfig['name']    ?? 'Example'
            );
            $mail->addAddress($validRecipients[0]);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $message;
            $mail->CharSet = 'UTF-8';

            foreach ($headers as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $mail->addCustomHeader($key, $value);
                }
            }

            $batchNo = 1;
            return $sendMail($mail, [$validRecipients[0]]);
        }

        // Bulk recipient case
        $batches = array_chunk($validRecipients, $batchSize);
        $allSent = true;
        $batchNo = 0;

        foreach ($batches as $batch) {
            $batchNo++;
            $logger->info("Preparing batch #{$batchNo}", ['batch_recipients' => $batch]);

            $mail = new PHPMailer(true);
            $mail->Timeout      = $timeout;
            $mail->SMTPAutoTLS  = $smtpConfig['autotls'] ?? false;
            $mail->isSMTP();
            $mail->Host         = $smtpConfig['host'] ?? '';
            $mail->SMTPAuth     = isset($smtpConfig['username']);
            $mail->Username     = $smtpConfig['username'] ?? '';
            $mail->Password     = $smtpConfig['password'] ?? '';
            $mail->SMTPSecure   = $smtpConfig['encryption'] ?? false;
            $mail->Port         = $smtpConfig['port'] ?? 587;
            $mail->setFrom(
                $fromConfig['address'] ?? 'hello@example.com',
                $fromConfig['name']    ?? 'Example'
            );

            foreach ($batch as $address) {
                $mail->addBCC($address);
            }

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $message;
            $mail->CharSet = 'UTF-8';

            foreach ($headers as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $mail->addCustomHeader($key, $value);
                }
            }

            if (!$sendMail($mail, $batch)) {
                $allSent = false;
            }
        }

        return $allSent;
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
        if ($diff < 60) {
            return "$diff saniye önce";
        } elseif ($diff < 3600) {
            return floor($diff / 60) . ' dakika önce';
        } elseif ($diff < 86400) {
            return floor($diff / 3600) . ' saat önce';
        } elseif ($diff < 604800) {
            return floor($diff / 86400) . ' gün önce';
        } else {
            return $then->format('d F Y');
        } // Consider localizing format
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
    /**
     * Attempts to convert a source image to AVIF format.
     * If it fails or AVIF support is not available, it attempts to convert to WebP format.
     *
     * @param string $source Path to the source file.
     * @param int $quality Compression quality (0-100, default 75). Used for both AVIF and WebP.
     * @param bool $removeOld Whether to delete the old file after conversion (default false).
     * @param string|null $fileName Optional destination file name (without extension).
     * If not specified, a unique ID will be generated.
     * @param string $destinationDir Directory where images will be saved (default 'files/').
     * @return string The new file name (with extension) if successful,
     * or the original source file path if it fails.
     */
    function webpImage(string $source, int $quality = 75, bool $removeOld = false, ?string $fileName = null, string $destinationDir = 'files/'): string
    {
        // 1. Basic file and directory checks
        if (!file_exists($source) || !is_readable($source)) {
            return $source;
        }

        if (!is_dir($destinationDir)) {
            @mkdir($destinationDir, 0755, true); // Attempt to create the directory if it doesn't exist
        }

        if (!is_writable($destinationDir)) {
            return $source;
        }

        // 2. Get image information and load
        $imageInfo = @getimagesize($source);
        if (!$imageInfo) {
            return $source;
        }

        $mime = $imageInfo['mime'] ?? null;
        $image = null;

        switch ($mime) {
            case 'image/jpeg':
                $image = @imagecreatefromjpeg($source);
                break;
            case 'image/gif':
                $image = @imagecreatefromgif($source);
                break;
            case 'image/png':
                $image = @imagecreatefrompng($source);
                break;
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    $image = @imagecreatefromwebp($source);
                }
                break;
            case 'image/avif': // If source is already AVIF, we can still reprocess (quality change, etc.)
                if (function_exists('imagecreatefromavif')) {
                    $image = @imagecreatefromavif($source);
                }
                break;
            default:
                return $source; // Unsupported type
        }

        if (!$image) {
            return $source; // Image could not be loaded
        }

        // 3. Alpha channel (transparency) management
        // For formats that can support transparency like PNG, GIF, WebP, AVIF
        if (in_array($mime, ['image/gif', 'image/png', 'image/webp', 'image/avif'])) {
            if (!imageistruecolor($image)) {
                @imagepalettetotruecolor($image); // Convert to true color if it's palette-based
            }
            @imagealphablending($image, false); // Disable alpha blending
            @imagesavealpha($image, true);    // Save the full alpha channel
        }

        // 4. Determine the base name for the target file
        $baseOutputName = $fileName ? preg_replace('/[^A-Za-z0-9\-_]/', '', basename($fileName)) : null;
        if (empty($baseOutputName)) { // If $fileName is null or becomes empty after cleaning
            $baseOutputName = uniqid();
        }

        $convertedFileName = null; // Name of the successfully converted file

        // 5. Attempt to convert to AVIF
        if (function_exists('imageavif')) {
            $avifFileName = $baseOutputName . '.avif';
            $destinationPath = rtrim($destinationDir, '/') . '/' . $avifFileName;

            // Remove the @ sign (This comment was in the original code)
            $avifSaveResult = imageavif($image, $destinationPath, $quality);
            if ($avifSaveResult) {
                $convertedFileName = $avifFileName;
            } else {
                $lastError = error_get_last();
                if ($lastError) { // Check if logger exists to prevent fatal error
                    logger()->warning("webpImage AVIF Conversion Error: " . htmlspecialchars($lastError['message']));
                }
            }
        } else {
                logger()->warning("imageavif function not found.");
        }

        // 6. If AVIF failed or is not supported, try to convert to WebP
        if (!$convertedFileName && function_exists('imagewebp')) {
            $webpFileName = $baseOutputName . '.webp'; // Use the same base name
            $destinationPath = rtrim($destinationDir, '/') . '/' . $webpFileName;
            if (@imagewebp($image, $destinationPath, $quality)) {
                $convertedFileName = $webpFileName;
            }
        }

        // 7. Free up resources
        @imagedestroy($image);

        // 8. Result
        if ($convertedFileName) {
            if ($removeOld) {
                if (file_exists($source)) { // Re-check existence before deleting
                    @unlink($source);
                }
            }
            return $convertedFileName; // Return only the file name
        } else {
            return $source; // Return the original source if no conversion was successful
        }
    }
}
if (!function_exists('logger')) {
    function logger()
    {
        return App::container()->get(LoggerInterface::class);
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
                echo '<!-- CSRF Middleware Class Not Found -->';
                return;
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
if (!function_exists('minifyHtml')) {
    /**
     * Güvenli HTML + inline JS/CSS minify.
     * <pre>, <textarea> bloklarını korur; <style> ve <script> içeriğini güvenli şekilde minify eder.
     *
     * @param string $html
     * @return string
     */
    function minifyHtml(string $html): string
    {
        // Preserve <pre> ve <textarea>
        $preservePattern = '/(<(pre|textarea)[^>]*>)(.*?)(<\/\2>)/si';
        $preserved = [];
        $i = 0;
        $html = preg_replace_callback($preservePattern, function ($m) use (&$preserved, &$i) {
            $ph = "___PRESERVE_{$i}___";
            $preserved[$ph] = $m[0];
            $i++;
            return $ph;
        }, $html);

        // Extract ve minify <style>
        $html = preg_replace_callback('/<style\b[^>]*>(.*?)<\/style>/si', function ($m) {
            $css = $m[1];
            // Remove comments
            $css = preg_replace('/\/\*.*?\*\//s', '', $css);
            // Collapse whitespace
            $css = preg_replace('/\s+/', ' ', $css);
            // Remove space around symbols
            $css = preg_replace(['/ *([{};:,]) */', '/;}/'], ['$1', '}'], $css);
            return '<style>' . $css . '</style>';
        }, $html);

        // Extract ve minify <script>
        $html = preg_replace_callback('/<script\b[^>]*>(.*?)<\/script>/si', function ($m) {
            $js = $m[1];

            // --- JavaScript Minification Düzeltmeleri ---
            // UYARI: Regex tabanlı JavaScript küçültme doğası gereği sınırlıdır ve riskli olabilir.
            // Kodun yapısını özel bir ayrıştırıcı (parser) gibi anlayamaz.
            // Bu değişiklikler, orijinaline göre daha güvenli olmayı ve kodu bozmamayı hedefler.

            // 1. Blok yorumlarını kaldır (/* ... */)
            // Genellikle güvenlidir, ancak string literalleri içinde /* veya */ varsa dikkatli olunmalıdır.
            $js = preg_replace('/\/\*[\s\S]*?\*\//', '', $js);

            // 2. Satır yorumlarını kaldır (// ...)
            // Orijinal regex: $js = preg_replace('/(^|\s)\/\/[^\n\r]*/m', '', $js);
            // Bu, önemli yeni satırları veya boşlukları kaldırabilir.
            // Daha güvenli bir yaklaşım:
            //    a) Sadece yorum içeren satırları (başında isteğe bağlı boşluk olabilir) tamamen kaldırır (yeni satırı dahil).
            //    b) Kodun sonundaki yorumları (öncesindeki boşluk/tablarla birlikte) kaldırır. 'http://' gibi kullanımları bozmamak için (?<![:\'"]) kullanılır.
            $js = preg_replace(
                [
                    '/^\s*\/\/[^\r\n]*(\r\n|\r|\n)?/m', // Tamamen yorum satırlarını (newline dahil) kaldır
                    '/(?<![:\'"])[ \t]*\/\/[^\r\n]*/'    // Satır sonu yorumlarını (öncesindeki boşluklarla) kaldır
                ],
                '',
                $js
            );

            // 3. Yeni satır karakterlerini normalize et (Windows, Mac, Linux uyumu)
            $js = str_replace(["\r\n", "\r"], "\n", $js);

            // 4. Her satırın başındaki ve sonundaki yatay boşlukları (space, tab) kaldır
            $js = preg_replace('/^[ \t]+|[ \t]+$/m', '', $js);

            // 5. Birden fazla yatay boşluğu (space, tab) tek bir boşluğa indirge
            // Örn: "  " -> " " (Yeni satırlara dokunmaz)
            $js = preg_replace('/[ \t]{2,}/', ' ', $js);

            // 6. Art arda gelen boş yeni satırları tek bir yeni satıra indirge
            // Bu, kodun okunabilirliğini biraz korurken gereksiz boşlukları azaltır.
            $js = preg_replace('/\n\s*\n/', "\n", $js);
            
            // 7. İsteğe bağlı: Bazı özel karakterlerin etrafındaki boşlukları kaldırma (; , () {} vb.)
            // Bu adım JS için risklidir ve dikkatli yapılmalıdır. Şimdilik devre dışı bırakılmıştır.
            // Örneğin:
            // $js = preg_replace('/\s*([;,(]){1}\s*/', '$1', $js);
            // $js = preg_replace('/\s*([)]){1}\s*/', '$1', $js);
            // Bu tür agresif değişiklikler genellikle JS kodunu bozar.

            // 8. Script bloğunun tamamının başındaki ve sonundaki genel boşlukları (yeni satırlar dahil) temizle
            $js = trim($js);

            return '<script>' . $js . '</script>';
        }, $html);

        // Genel HTML minify
        $search = [
            '//s',  // HTML yorumları (IE conditional comments hariç)
            '/>\s+</',               // Tag arası boşluk
            '/\s*(\/?>)/',           // Tag sonu
            '/\s{2,}/',              // Çoklu boşluk
        ];
        $replace = [
            '',
            '><',
            '$1',
            ' ',
        ];
        $html = preg_replace($search, $replace, $html);

        // Geri restore
        return str_replace(array_keys($preserved), array_values($preserved), trim($html));
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
        $findViewFile = function (string $viewName, ?string $primaryPath, string $fallbackPath): ?string {
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
        try {
            include $viewFile;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
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
            try {
                extract($data);
                include $layoutFile;
            } catch (\Throwable $e) {
                ob_end_clean();
                throw $e;
            }
            $finalContent = ob_get_clean();
        } else {
            $finalContent = $content;
        }

        // Conditionally minify the final HTML content
        if (config('app.minify_html', false)) {
            $finalContent = minifyHtml($finalContent);
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
        if (is_null($key)) {
            return $session;
        }
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                $session->put($k, $v);
            }
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

if (!function_exists('isRoute')) {
    /**
     * Check if the current route matches the given name or one of the given names in the array.
     *
     * @param string|string[] $name The name of the route or an array of route names to check.
     * @return bool True if the current route matches the name or any of the names in the array, false otherwise.
     */
    function isRoute(string|array $name): bool // PHP 8.0+ Union Type
    {
        try {
            /** @var \SwallowPHP\Framework\Routing\Router $router */
            $router = App::container()->get(Router::class);
            // Get the currently matched route from the Router
            $matchedRoute = $router->getCurrentRoute();

            // If no route is matched yet, or route has no name, return false
            if ($matchedRoute === null || $matchedRoute->getName() === null) {
                return false;
            }

            $currentRouteName = $matchedRoute->getName();

            if (is_array($name)) {
                // Check if the current route name exists in the provided array of names
                return in_array($currentRouteName, $name, true); // Use strict comparison
            } else {
                // Original behavior: Check if the current route name matches the single provided name
                return $currentRouteName === $name;
            }
        } catch (\Throwable $e) {
            // Log error or handle cases where router/route isn't available yet
            $logger = null;
            // Attempt to get logger, but don't fail if it's not available
            $logger = App::container()->get(LoggerInterface::class);


            if ($logger) {
                $logger->warning(
                    "Could not check current route name in isRoute() helper: " . $e->getMessage(),
                    ['exception' => $e]
                );
            }

            // Return false if any error occurred during the check
            return false;
        }
    }
}

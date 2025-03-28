# Middleware

Middleware, HTTP istekleri uygulamanıza girmeden önce veya yanıtlar tarayıcıya gönderilmeden hemen önce filtrelemek veya işlem yapmak için bir mekanizma sağlar. Örneğin, bir middleware kullanıcının kimliğinin doğrulanıp doğrulanmadığını kontrol edebilir, CSRF koruması uygulayabilir veya isteklere özel başlıklar ekleyebilir.

## Middleware Tanımlama

Her middleware sınıfı, `SwallowPHP\Framework\Http\Middleware\Middleware` abstract sınıfını genişletmeli ve bir `handle` metodu tanımlamalıdır.

```php
<?php

namespace App\Http\Middleware; // Middleware'lerinizi genellikle App\Http\Middleware altında tutun

use Closure;
use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Response; // Gerekirse Response sınıfını kullanın

class ExampleMiddleware extends Middleware
{
    /**
     * Gelen isteği işle.
     *
     * @param  \SwallowPHP\Framework\Http\Request  $request
     * @param  \Closure  $next  // Bir sonraki middleware'i veya controller action'ını temsil eden Closure
     * @return mixed // Genellikle bir Response nesnesi
     */
    public function handle(Request $request, Closure $next): mixed
    {
        // --- İstek Öncesi İşlemler ---
        // Örneğin: Gelen isteği loglama
        // Log::info('Gelen İstek: ' . $request->getUri());

        // İsteği bir sonraki adıma (başka bir middleware veya controller) gönder
        $response = $next($request);

        // --- Yanıt Sonrası İşlemler ---
        // Örneğin: Yanıta özel bir başlık ekleme
        if ($response instanceof Response) {
            $response->header('X-Processed-By', 'ExampleMiddleware');
        }

        // Son yanıtı döndür
        return $response;
    }
}
```

-   **`handle(Request $request, Closure $next)`:** Bu metot iki parametre alır:
    -   `$request`: Mevcut `Request` nesnesi.
    -   `$next`: Pipeline'daki bir sonraki adımı temsil eden bir `Closure`. Bu closure'ı `$next($request)` şeklinde çağırmalısınız. Bu çağrı, ya bir sonraki middleware'in `handle` metodunu ya da (eğer son middleware ise) hedeflenen Controller action'ını çalıştırır ve genellikle bir `Response` nesnesi döndürür.
-   **İstek Öncesi:** `$next($request)` çağrılmadan *önce* yapılan işlemlerdir.
-   **Yanıt Sonrası:** `$next($request)` çağrıldıktan *sonra* yapılan işlemlerdir. Dönen `$response` üzerinde değişiklik yapabilirsiniz.

## Middleware Kaydetme ve Kullanma

Middleware'ler genellikle rotalara atanarak kullanılır. Rota tanımlarken `middleware()` metodunu kullanabilirsiniz.

```php
// routes/web.php

use App\Http\Middleware\ExampleMiddleware;
use App\Http\Middleware\AuthMiddleware; // Varsayımsal bir Auth middleware

// Tek bir rotaya middleware atama
Router::get('/profile', 'UserController@profile')
      ->middleware(AuthMiddleware::class);

// Birden fazla middleware atama (dizi içinde)
Router::post('/settings', 'SettingsController@update')
      ->middleware([
          AuthMiddleware::class,
          ExampleMiddleware::class
      ]);

// Rota grubuna middleware atama
Router::group(['middleware' => [AuthMiddleware::class]], function () {
    Router::get('/dashboard', 'DashboardController@index');
    Router::get('/account', 'AccountController@show');
});
```

Middleware'ler, rotada tanımlandıkları sırayla çalıştırılır.

## Global Middleware

Bazı middleware'ler (örneğin `VerifyCsrfToken`), her HTTP isteğinde çalıştırılmalıdır. Bu tür middleware'ler şu anda `App::run()` metodu içinde manuel olarak çağrılmaktadır. İleride, bu global middleware'leri yönetmek için `config/app.php` gibi bir yerde merkezi bir yapılandırma eklenebilir.

## Framework Middleware'leri

SwallowPHP, bazı yerleşik middleware'ler ile birlikte gelir:

-   **`VerifyCsrfToken`:** CSRF (Cross-Site Request Forgery) saldırılarına karşı koruma sağlar.
    -   **Namespace:** `SwallowPHP\Framework\Http\Middleware`
    -   **Kullanım:** Bu middleware genellikle global olarak uygulanır (`App::run()` içinde). `HEAD`, `GET`, `OPTIONS` gibi "okuma" isteklerini ve `$except` dizisinde belirtilen URI'ları otomatik olarak geçer. Diğer tüm isteklerde (POST, PUT, PATCH, DELETE) CSRF token doğrulaması yapar.
    -   **Nasıl Çalışır:**
        1.  Oturumda saklanan `_token` değerini alır (`VerifyCsrfToken::getToken()` ile oluşturulur/alınır).
        2.  Gelen istekteki token'ı arar:
            -   Önce `_token` isimli input alanına bakar (`$request->get('_token')`).
            -   Bulamazsa `X-CSRF-TOKEN` başlığına bakar (`$request->header('X-CSRF-TOKEN')`).
            -   Yine bulamazsa `X-XSRF-TOKEN` başlığına bakar (`$request->header('X-XSRF-TOKEN')`).
        3.  Oturumdaki token ile istekteki token'ı `hash_equals()` kullanarak (zamanlama saldırılarına karşı güvenli) karşılaştırır.
        4.  Eşleşme olmazsa veya token'lardan biri eksikse `CsrfTokenMismatchException` fırlatır (HTTP 419).
    -   **Formlarda Kullanım:** State değiştiren (POST, PUT, PATCH, DELETE) tüm HTML formlarınıza `csrf_field()` helper fonksiyonunu eklemelisiniz. Bu, içinde geçerli CSRF token'ını barındıran gizli bir `_token` input alanı oluşturur.
        ```html
        <form method="POST" action="/profile">
            <?php csrf_field(); ?>
            <!-- Diğer form alanları -->
            <button type="submit">Kaydet</button>
        </form>
        ```
    -   **JavaScript İstekleri (AJAX):** AJAX istekleri yaparken (özellikle POST, PUT, DELETE vb.), CSRF token'ını `X-CSRF-TOKEN` başlığı ile göndermeniz gerekir. Genellikle bu token, sayfanın `<head>` kısmındaki bir meta etikette saklanır ve JavaScript ile okunarak her AJAX isteğine eklenir.
        ```html
        <!-- Layout dosyasında -->
        <meta name="csrf-token" content="<?= htmlspecialchars(\SwallowPHP\Framework\Http\Middleware\VerifyCsrfToken::getToken(), ENT_QUOTES, 'UTF-8') ?>">

        <!-- JavaScript (örnek - Axios ile) -->
        <script>
            const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

            axios.post('/api/endpoint', { data: 'value' }, {
                headers: {
                    'X-CSRF-TOKEN': csrfToken
                }
            })
            .then(response => { /* ... */ })
            .catch(error => { /* ... */ });
        </script>
        ```
    -   **Doğrulamadan Muaf Tutma (`$except`):** Belirli URI'ları CSRF doğrulamasından muaf tutmak için `VerifyCsrfToken` sınıfı içindeki `$except` dizisine ekleyebilirsiniz. Bu genellikle harici servislerden gelen webhook'lar gibi durumlar için kullanılır. Wildcard (`*`) desteği basittir.
        ```php
        // src/Http/Middleware/VerifyCsrfToken.php
        protected $except = [
            'stripe/*', // Stripe webhook'ları
            'api/v1/public-endpoint' // Belirli bir API endpoint'i
        ];
        ```
-   **`RateLimiter`:** İstek sınırlaması uygular. Belirli bir zaman aralığında bir IP adresinden gelen istek sayısını sınırlar.
    -   **Namespace:** `SwallowPHP\Framework\Http\Middleware`
    -   **Kullanım:** Bu sınıf doğrudan bir middleware olarak değil, `Route` sınıfının `rateLimit()` metodu aracılığıyla kullanılır. `Router`, eşleşen rotanın rate limit ayarı varsa `RateLimiter::execute()` metodunu çağırır.
    -   **Nasıl Çalışır:**
        1.  Rota tanımından `rateLimit(int $limit, ?int $ttl = null)` ile belirtilen limiti (`$limit`) ve isteğe bağlı TTL'i (`$ttl`) alır. `$ttl` belirtilmezse `config('cache.ttl', 60)` kullanılır.
        2.  İsteği yapanın IP adresini (`Request::getClientIp()`) ve rota adını/URI'ını kullanarak benzersiz bir cache anahtarı oluşturur (örn. `rate_limit:user.profile:192.168.1.10`).
        3.  Cache'den bu anahtara ait veriyi okur (istek sayısı ve son istek zamanı).
        4.  İstek sayısını bir artırır ve son istek zamanını günceller.
        5.  Güncellenmiş veriyi, belirlenen `$ttl` süresiyle cache'e geri yazar.
        6.  İstek sayısı (`$requestCount`), belirlenen limiti (`$rateLimit`) aşıyorsa, `RateLimitExceededException` fırlatır (HTTP 429).
        7.  Ayrıca yanıta `X-RateLimit-Limit`, `X-RateLimit-Remaining` ve (limit aşıldıysa) `Retry-After` başlıklarını ekler.
    -   **Örnek (Rota Tanımı):**
        ```php
        // routes/web.php

        // /api/users rotasına dakikada 60 istek limiti uygula
        Router::get('/api/users', 'ApiController@users')
              ->rateLimit(60); // TTL varsayılan (config('cache.ttl')) kullanılır

        // /login rotasına 5 dakikada 10 istek limiti uygula
        Router::post('/login', 'AuthController@login')
              ->rateLimit(10, 300); // Limit: 10, TTL: 300 saniye (5 dakika)
        ```
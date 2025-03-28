# Özel Exception Sınıfları

SwallowPHP, belirli hata durumlarını daha anlamlı bir şekilde temsil etmek için çeşitli özel exception sınıfları tanımlar. Bu exception'lar, `ExceptionHandler` tarafından yakalanarak uygun HTTP yanıtlarına dönüştürülür.

## `AuthorizationException`

**Namespace:** `SwallowPHP\Framework\Exceptions`

Bu exception, kullanıcının belirli bir eylemi gerçekleştirmek için gerekli yetkiye sahip olmadığı durumlarda fırlatılır.

-   **Varsayılan HTTP Durum Kodu:** 401 (Unauthorized) - Ancak bazen 403 (Forbidden) daha uygun olabilir, bu durumda exception oluşturulurken kod belirtilebilir.
-   **Varsayılan Mesaj:** 'Access Denied: You are not authorized to perform this action.'

**Ne Zaman Fırlatılır?**

Genellikle middleware katmanında veya controller metotlarının başında, kullanıcının rolü veya izinleri kontrol edilirken yetersiz yetki tespit edildiğinde fırlatılır.

**Örnek Kullanım (Middleware):**

```php
// AdminMiddleware.php
namespace App\Http\Middleware;

use Closure;
use SwallowPHP\Framework\Http\Middleware\Middleware;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Auth\Auth; // Auth sınıfını kullan
use SwallowPHP\Framework\Exceptions\AuthorizationException; // Exception'ı kullan

class AdminMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        // Auth::isAdmin() veya benzeri bir yetki kontrolü
        if (!Auth::isAdmin()) {
            // Yetkisiz erişim durumunda exception fırlat
            throw new AuthorizationException('Admin access required.', 403); // 403 Forbidden kodu ile
        }

        // Yetki varsa, sonraki adıma geç
        return $next($request);
    }
}
```

**ExceptionHandler Tarafından İşlenmesi:**

`ExceptionHandler`, bu exception'ı yakaladığında, exception'ın kodunu (varsayılan 401 veya belirtilmişse 403) ve mesajını kullanarak uygun bir HTTP yanıtı (genellikle JSON veya basit HTML hata sayfası) oluşturur.


## `CsrfTokenMismatchException`

**Namespace:** `SwallowPHP\Framework\Exceptions`

Bu exception, gelen istekteki CSRF token'ının oturumdaki token ile eşleşmediği veya eksik olduğu durumlarda fırlatılır. Bu genellikle Cross-Site Request Forgery (CSRF) saldırılarını önlemek için kullanılır.

-   **Varsayılan HTTP Durum Kodu:** 419 (Authentication Timeout) - Bu, Laravel tarafından popüler hale getirilen ve genellikle CSRF hataları için kullanılan standart olmayan bir koddur.
-   **Varsayılan Mesaj:** 'CSRF token mismatch'

**Ne Zaman Fırlatılır?**

Genellikle `VerifyCsrfToken` middleware'i tarafından, `POST`, `PUT`, `PATCH`, `DELETE` gibi state değiştiren isteklerde CSRF token doğrulaması başarısız olduğunda fırlatılır.

**Örnek (Framework İçinde - VerifyCsrfToken Middleware):**

```php
// VerifyCsrfToken.php içinde (basitleştirilmiş örnek)
protected function tokensMatch($request)
{
    $sessionToken = $this->getTokenFromSession();
    $requestToken = $request->get('_token') ?? $request->header('X-CSRF-TOKEN');

    return is_string($sessionToken) &&
           is_string($requestToken) &&
           hash_equals($sessionToken, $requestToken);
}

public function handle($request, Closure $next)
{
    if (
        $this->isReading($request) ||
        $this->inExceptArray($request) ||
        $this->tokensMatch($request)
    ) {
        return $next($request);
    }

    throw new CsrfTokenMismatchException(); // Token eşleşmezse fırlat
}
```

**ExceptionHandler Tarafından İşlenmesi:**

`ExceptionHandler`, bu exception'ı yakaladığında, 419 durum kodunu ve ilgili mesajı içeren bir yanıt oluşturur. Geliştirme sırasında bu genellikle bir hata sayfasıdır, production ortamında ise kullanıcı dostu bir mesaj veya önceki sayfaya yönlendirme daha uygun olabilir (ExceptionHandler'ın mantığına bağlıdır).


## `EnvPropertyValueException`

**Namespace:** `SwallowPHP\Framework\Exceptions`

Bu exception, `.env` dosyasındaki bir değerle ilgili genel bir sorun olduğunda veya bir yapılandırma değeri bekleneni karşılamadığında fırlatılabilir. Örneğin, gerekli bir `.env` değişkeni eksikse veya geçersiz bir değere sahipse kullanılabilir.

-   **Varsayılan HTTP Durum Kodu:** 500 (Internal Server Error) - Çünkü bu genellikle uygulamanın doğru çalışmasını engelleyen bir yapılandırma sorununu gösterir.
-   **Varsayılan Mesaj:** 'Env Property Is Not Allowed' (Bu mesaj daha açıklayıcı olabilir, örn. 'Invalid or missing environment variable configuration.')

**Ne Zaman Fırlatılır?**

Framework'ün başlangıç aşamasında veya belirli servisler başlatılırken, `.env` dosyasından okunan değerler doğrulanırken veya işlenirken bir sorun tespit edildiğinde fırlatılabilir.

**Örnek Kullanım (Varsayımsal):**

```php
// Örnek bir servis başlatma kodu içinde
$apiKey = env('EXTERNAL_API_KEY');

if (empty($apiKey)) {
    throw new EnvPropertyValueException('Required environment variable EXTERNAL_API_KEY is missing.');
}

// ... servisi başlat
```

**ExceptionHandler Tarafından İşlenmesi:**

`ExceptionHandler`, bu exception'ı yakaladığında, 500 durum kodunu ve ilgili mesajı içeren bir yanıt oluşturur. Debug modunda, exception mesajı gösterilebilir; production modunda ise genellikle genel bir 'Internal Server Error' mesajı gösterilir.
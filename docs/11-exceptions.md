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
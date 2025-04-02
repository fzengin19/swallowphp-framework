# Routing

SwallowPHP, gelen HTTP isteklerini uygun Controller action'larına veya Closure'lara yönlendirmek için basit ama güçlü bir routing sistemi sunar. Routing sistemi temel olarak `Router` ve `Route` sınıflarından oluşur.

## `Router` Sınıfı

**Namespace:** `SwallowPHP\Framework\Routing`

`Router` sınıfı, uygulamanızdaki tüm rotaların kaydedildiği ve yönetildiği yerdir. Gelen isteği analiz ederek uygun rotayı bulur ve o rotanın çalıştırılmasını (`dispatch`) sağlar.

### Rota Tanımlama Metotları

Rotalar genellikle `routes/web.php` veya `routes/api.php` gibi dosyalarda `SwallowPHP\Framework\Routing\Router` sınıfının statik metotları kullanılarak tanımlanır.

```php
// routes/web.php

use SwallowPHP\Framework\Routing\Router;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\UserController;

// Basit GET rotası (Closure kullanarak)
Router::get('/', function () {
    return Response::html('<h1>Ana Sayfa</h1>');
});

// Controller action'ına yönlendirme
Router::get('/home', [HomeController::class, 'index']); // Önerilen: Dizi syntax'ı
Router::get('/about', 'AboutController@show');       // Alternatif: String syntax'ı

// POST rotası
Router::post('/users', [UserController::class, 'store']);

// Diğer HTTP metotları
Router::put('/users/{id}', [UserController::class, 'update']);
Router::patch('/users/{id}/status', [UserController::class, 'updateStatus']);
Router::delete('/users/{id}', [UserController::class, 'destroy']);


```

-   **Desteklenen Metotlar:** `get`, `post`, `put`, `patch`, `delete`.
-   **Action:** Rota eşleştiğinde çalıştırılacak olan kod. Bu bir `Closure` olabilir veya `[Controller::class, 'methodName']` şeklinde bir dizi ya da `'ControllerName@methodName'` şeklinde bir string olabilir. Dizi syntax'ı, IDE'lerde Controller ve metot isimlerine tıklayarak kolayca gitmeyi sağladığı için önerilir.

## Rota Parametreleri

URI içinde süslü parantezler (`{}`) kullanarak dinamik parametreler tanımlayabilirsiniz. Bu parametreler, eşleşen Controller metoduna veya Closure'a otomatik olarak enjekte edilir.

```php
// Rota tanımı
Router::get('/users/{userId}/posts/{postId}', function ($userId, $postId) {
    return "Kullanıcı ID: {$userId}, Post ID: {$postId}";
});

// Controller ile
Router::get('/products/{category}/{productId}', [ProductController::class, 'show']);

// ProductController.php
class ProductController
{
    public function show(Request $request, $category, $productId) // Request nesnesi de enjekte edilebilir
    {
        // $category ve $productId değerlerini kullan
        return Response::html("Kategori: {$category}, Ürün ID: {$productId}");
    }
}
```

Parametre isimleri (`userId`, `postId`, `category`, `productId`) önemlidir ve Controller/Closure metodundaki değişken isimleriyle eşleşmelidir (veya sırası önemlidir).

## `Route` Sınıfı

Her rota tanımı, arka planda bir `SwallowPHP\Framework\Routing\Route` nesnesi oluşturur. Bu nesne, rotanın tüm bilgilerini tutar ve rota tanımlamasına ek özellikler eklemek için akıcı (fluent) metotlar sunar.

### Rota İsimlendirme (`name`)

Rotalara benzersiz isimler vermek, URL oluştururken veya yönlendirme yaparken kolaylık sağlar.

```php
Router::get('/users/{id}/profile', [UserController::class, 'profile'])
      ->name('user.profile'); // Rotaya 'user.profile' ismini ver

// Başka bir yerde URL oluşturma (route() helper'ı ile)
$url = route('user.profile', ['id' => 15]); // /users/15/profile
```

### Middleware Atama (`middleware`)

Belirli bir rotaya veya rota grubuna middleware atamak için kullanılır.

```php
use App\Http\Middleware\AuthMiddleware;

Router::get('/dashboard', 'DashboardController@index')
      ->middleware(AuthMiddleware::class); // Tek middleware

Router::post('/posts', 'PostController@store')
      ->middleware([AuthMiddleware::class, AnotherMiddleware::class]); // Birden fazla
```
(Daha fazla bilgi için [Middleware](./06-middleware.md) dökümantasyonuna bakın.)

### Rate Limiting (`limit` veya `rateLimit`)

Bir rotaya belirli bir zaman aralığında yapılabilecek maksimum istek sayısını sınırlamak için kullanılır.

```php
// /api/data rotasına dakikada 100 istek limiti
Router::get('/api/data', 'ApiController@data')
      ->limit(100); // Varsayılan TTL (config('cache.ttl')) kullanılır

// /login rotasına 5 dakikada 5 istek limiti
Router::post('/login', 'AuthController@login')
      ->limit(5, 300); // Limit: 5, TTL: 300 saniye
```
(Daha fazla bilgi için [Middleware](./06-middleware.md) dökümantasyonundaki RateLimiter bölümüne bakın.)

### Diğer `Route` Metotları (Genellikle Dahili Kullanım)

-   `getMethod()`: Rotanın HTTP metodunu döndürür.
-   `getUri()`: Rotanın URI'ını döndürür.
-   `getName()`: Rotanın ismini döndürür.
-   `getRateLimit()`: Rota için tanımlanmış rate limit sayısını döndürür.
-   `getTimeToLive()`: Rate limit için tanımlanmış TTL'i döndürür.
-   `execute(Request $request)`: Router tarafından çağrılır. Atanmış middleware'leri çalıştırır ve ardından rotanın action'ını (Closure veya Controller metodu) çalıştırır. Controller metotları için bağımlılıkları (Request nesnesi, rota parametreleri, DI konteynerindeki servisler) otomatik olarak çözmeye çalışır.

## Rota Gruplama (`Router::group`)

`Router::group(array $attributes, Closure $callback): void`

Birden fazla rotaya ortak özellikler (ön ek, middleware, namespace vb. - *şu anki implementasyonda sadece middleware destekleniyor olabilir*) uygulamak için kullanılır.

-   **`$attributes` (array):** Gruba uygulanacak özellikler. Şu anda desteklenenler:
    -   `'middleware'` (array): Gruba dahil tüm rotalara uygulanacak middleware sınıflarının dizisi.
    -   `'prefix'` (string - *henüz implemente edilmedi*): Grubun URI'larına eklenecek ön ek.
    -   `'namespace'` (string - *henüz implemente edilmedi*): Grubun Controller'ları için namespace ön eki.
    -   `'name'` (string - *henüz implemente edilmedi*): Grubun rota isimlerine eklenecek ön ek.
-   **`$callback` (Closure):** Grup içindeki rotaların tanımlandığı Closure.

**Örnek:**

```php
use App\Http\Middleware\AuthMiddleware;
use App\Http\Middleware\AdminMiddleware;

// Tüm admin rotaları için middleware ve (gelecekte) prefix/namespace uygulama
Router::group(['middleware' => [AuthMiddleware::class, AdminMiddleware::class]], function () {

    Router::get('/admin/dashboard', 'Admin\DashboardController@index')->name('admin.dashboard');
    Router::get('/admin/users', 'Admin\UserController@index')->name('admin.users.index');
    Router::post('/admin/users', 'Admin\UserController@store')->name('admin.users.store');
    // ... diğer admin rotaları

});

// API rotaları için (gelecekte prefix ve name eklenebilir)
// Router::group(['prefix' => 'api/v1', 'name' => 'api.v1.', 'middleware' => [ApiAuthMiddleware::class]], function() {
//     Router::get('/posts', 'Api\V1\PostController@index')->name('posts.index');
// });
```

Grup içindeki rotalara tanımlanan özellikler (örn. middleware), grubun özellikleriyle birleştirilir.

## Rota Önbellekleme (Caching)

(Bu özellik henüz implemente edilmemiştir)

## Rota Model Binding

(Bu özellik henüz implemente edilmemiştir)
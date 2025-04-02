# SwallowPHP Framework Geliştirme Rehberi (Tam Kod Analizine Göre)

Bu rehber, SwallowPHP framework'ünün **mevcut kod tabanını tam olarak analiz ederek** uygulama geliştirmeyi açıklar. Framework, minimalist ve performans odaklıdır. Varsayımlardan kaçınılmış, yalnızca kodda bulunan özellikler belgelenmiştir.

## 1. Kurulum ve Proje Yapısı

Framework'ü kullanmak için projenizin ana dizininde `composer.json` dosyasını ve aşağıdaki temel dizin yapısını oluşturmanız önerilir:

```
/proje-dizini
├── app/
│   ├── Controllers/
│   │   └── HomeController.php
│   ├── Models/
│   │   └── User.php
│   └── Middleware/
│       └── ExampleMiddleware.php
├── config/         # Uygulama yapılandırma dosyaları (framework'ün src/Config üzerine yazılır)
│   ├── app.php
│   ├── auth.php
│   ├── cache.php
│   ├── database.php
│   └── session.php
├── public/         # Web sunucusunun kök dizini
│   ├── index.php   # Uygulamanın giriş noktası
│   └── .htaccess   # (Apache için) URL yönlendirme
├── resources/
│   └── views/      # View (Görünüm) dosyaları (Basit PHP)
│       ├── layouts/    # Layout dosyaları (isteğe bağlı)
│       │   └── app.php
│       └── users/
│           └── show.php
│       └── home.php
├── routes/
│   └── web.php     # Web rotaları (Manuel olarak dahil edilmeli)
├── storage/        # Yazılabilir dizinler (cache, logs vb. için)
│   ├── cache/
│   └── database/
├── vendor/         # Composer bağımlılıkları
├── .env            # Ortam değişkenleri
└── composer.json
```

**`public/index.php` (Giriş Noktası):**

```php
<?php

// Composer autoload dosyasını dahil et
// Proje kök dizinine göre yolu ayarlayın
require __DIR__.'/../vendor/autoload.php';

// Rota dosyalarını dahil et (Framework bunu otomatik yapmaz)
require __DIR__.'/../routes/web.php';
// require __DIR__.'/../routes/api.php'; // Varsa

// Framework App sınıfını kullan
use SwallowPHP\Framework\Foundation\App;

// Uygulamayı başlat ve isteği işle
// App::run() tüm yaşam döngüsünü yönetir (request, middleware, routing, response)
App::run();

```

**`.env` Dosyası:**

Ortama özgü yapılandırmaları içerir (`src/Foundation/Env.php` yükler).

```dotenv
APP_NAME=SwallowPHP
APP_ENV=local # veya production
APP_DEBUG=true # local için true, production için false
APP_URL=http://localhost:8000
APP_KEY=base64:YourRandom32ByteAppKeyGeneratedHere # ÖNEMLİ: Güvenlik ve Çerez Şifrelemesi için OLUŞTURULMALI!
APP_PATH= # Uygulama alt dizindeyse (örn: /myapp)
APP_TIMEZONE=Europe/Istanbul
APP_LOCALE=tr
STORAGE_PATH=../storage # public/index.php'ye göre storage dizini yolu

DB_CONNECTION=mysql # Şu anda sadece mysql destekleniyor
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=swallowphp_db
DB_USERNAME=root
DB_PASSWORD=
DB_CHARSET=utf8mb4

AUTH_MODEL=App\Models\User # Kullanılacak Kullanıcı Modeli
AUTH_MAX_ATTEMPTS=5
AUTH_LOCKOUT_TIME=900 # Saniye (15 dakika)

CACHE_DRIVER=file # veya sqlite
CACHE_FILE_PATH=cache/data.json # storage_path'a göre
CACHE_FILE_MAX_SIZE_MB=50
CACHE_SQLITE_PATH=cache/database.sqlite # storage_path'a göre
# CACHE_PREFIX= # Otomatik uygulanmıyor, gerekirse manuel kullanın
# CACHE_TTL=3600 # Otomatik uygulanmıyor, set() içinde belirtin

# E-posta ayarları (mailto helper için)
SMTP_MAIL_HOST=smtp.example.com
SMTP_MAIL_PORT=587
SMTP_MAIL_USERNAME=user@example.com
SMTP_MAIL_PASSWORD=secret
SMTP_MAIL_FROM_ADDRESS=noreply@example.com
SMTP_MAIL_FROM_NAME="SwallowPHP App"

# Çerez Varsayılanları (config/session.php kullanır)
SESSION_PATH=/
SESSION_DOMAIN=
SESSION_SECURE_COOKIE= # null bırakılırsa APP_ENV'ye göre belirlenir
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=Lax # Lax, Strict, None
```

**`composer.json`:**

```json
{
    "name": "kullanici/projem",
    "description": "SwallowPHP ile bir proje.",
    "require": {
        "php": "^8.0", // Framework gereksinimine göre güncelleyin
        "swallowphp/framework": "dev-main" // Veya uygun sürüm/dal
    },
    "autoload": {
        "psr-4": {
            "App\\": "app/"
        }
    },
    "repositories": [
        {
            "type": "path",
            "url": "../swallowphp-framework" // Framework yereldeyse yolu ayarlayın
        }
    ],
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

Kurulumdan sonra `composer install` ve **güvenli bir `APP_KEY` oluşturmanız (`.env` dosyasına eklemeniz)** gerekir.

## 2. Yapılandırma (Configuration)

*   Yapılandırma dosyaları `config/` dizininde bulunur ve PHP dizileri döndürür. Framework'ün kendi `src/Config/` dizinindeki varsayılanları geçersiz kılar.
*   Değerlere `config('dosya_adi.anahtar', 'varsayilan')` helper'ı ile erişilir.
*   `.env` değerlerine `env('DEGISKEN', 'varsayilan')` helper'ı ile erişilir.
*   **Önemli Yapılandırmalar:**
    *   `config/app.php`: Temel ayarlar, `APP_KEY` (çok önemli!), `storage_path`, `view_path`, `controller_namespace`, `cipher`.
    *   `config/auth.php`: `AUTH_MODEL`, giriş kilitleme ayarları.
    *   `config/cache.php`: Varsayılan cache sürücüsü, sürücü ayarları (yol, boyut vb.). **Not:** `prefix` ve `ttl` ayarları otomatik **uygulanmaz**.
    *   `config/database.php`: Varsayılan bağlantı, bağlantı detayları. **Not:** Şu anda sadece `mysql` bağlantısı tam desteklenmektedir. `prefix` ayarı otomatik **uygulanmaz**.
    *   `config/session.php`: Çerezler için varsayılan güvenlik ve kapsam ayarları (`path`, `domain`, `secure`, `httpOnly`, `sameSite`).

## 3. Yönlendirme (Routing)

*   Rotalar `routes/web.php` gibi dosyalarda tanımlanır ve `public/index.php` içinden manuel olarak `require` edilir.
*   `SwallowPHP\Framework\Routing\Router` sınıfının statik metodları (`get`, `post`, `put`, `patch`, `delete`) kullanılır.
*   Eylem olarak Closure veya `'ControllerAdi@metodAdi'` string'i alır. Controller namespace'i `config('app.controller_namespace')` ile belirlenir.
*   **Rota Parametreleri:** `{param}` şeklinde tanımlanır ve controller metoduna otomatik olarak enjekte edilir.
*   **İsimlendirilmiş Rotalar:** `->name('rota.adi')` ile isim verilir. `route('rota.adi', ['param' => 'deger'])` helper'ı ile URL oluşturulur.
*   **Metod Sahteciliği:** Formlarda `method('PUT')` helper'ı kullanılır.
*   **Rota Middleware:** `->middleware(new MiddlewareAdi())` ile rotaya özel middleware eklenir.
*   **Hız Sınırlama (Rate Limiting):** `->limit(int $maksimumIstek, ?int $zamanAraligiSaniye = null)` ile rotaya istek limiti konulabilir. Bu özellik `Cache` kullanır.

**Örnek `routes/web.php`:**

```php
<?php
use SwallowPHP\Framework\Routing\Router;
use App\Controllers\HomeController;
use App\Controllers\UserController;
use App\Middleware\CheckAdmin; // Örnek middleware

Router::get('/', [HomeController::class, 'index'])->name('home');
Router::get('/kullanici/{id}', [UserController::class, 'show'])->name('user.show')->limit(60, 60); // 60 saniyede 60 istek
Router::post('/kullanici', [UserController::class, 'store'])->name('user.store');
Router::put('/kullanici/{id}', [UserController::class, 'update'])->name('user.update');
Router::delete('/kullanici/{id}', [UserController::class, 'destroy'])->name('user.destroy');

Router::get('/admin', [AdminController::class, 'index'])
      ->middleware(new CheckAdmin()) // Middleware ekleme
      ->name('admin.index');
```

## 4. Controller'lar

*   `app/Controllers` altında, `config('app.controller_namespace')` namespace'i ile tanımlanırlar.
*   Metodlar, `Request` nesnesini, rota parametrelerini ve diğer servisleri (DI ile) alabilir.
*   Metodlar bir `SwallowPHP\Framework\Http\Response` nesnesi döndürmelidir.

**Örnek `app/Controllers/UserController.php`:**

```php
<?php
namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Http\Response; // Response sınıfı hala kullanılabilir
use SwallowPHP\Framework\Database\Database;
use App\Models\User;

class UserController
{
    protected Database $db;

    public function __construct(Database $database) { $this->db = $database; }

    public function show(Request $request, $id): Response
    {
        $user = User::where('id', '=', $id)->first(); // Veya find($id) gibi bir metod varsa
        if (!$user) {
            // Hata yönetimi - abort() helper'ı yok, manuel Response veya Exception
            return Response::html('Kullanıcı bulunamadı', 404); // veya throw new NotFoundException();
        }

        // Yeni view() helper'ı kullanımı
        $userData = $user->toArray(); // View'a dizi olarak göndermek daha güvenli olabilir
        return view('users.show', ['userData' => $userData, 'pageTitle' => $user->name], 'layouts.app'); // 'layouts.app' layout'unu kullanır ve başlık gönderir
    }

    public function store(Request $request): Response
    {
        $name = $request->get('name');
        $email = $request->get('email');
        $password = $request->get('password');

        // Doğrulama manuel yapılmalı
        // ...

        try {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
            ]);
        } catch (\Exception $e) {
            // Hata yönetimi
            return Response::html('Kullanıcı oluşturulamadı.', 500);
        }

        return redirectToRoute('user.show', ['id' => $user->id]);
    }

    public function update(Request $request, $id): Response
    {
        $user = User::where('id', '=', $id)->first();
        if (!$user) return Response::html('Bulunamadı', 404);

        // Doğrulama manuel yapılmalı
        // ...

        $user->fill([ // fill() kullanmak fillable kontrolü sağlar
            'name' => $request->get('name', $user->name)
        ]);
        $user->save();

        return redirectToRoute('user.show', ['id' => $user->id]);
    }

     public function destroy($id): Response
     {
         $deletedRows = User::where('id', '=', $id)->delete();
         if ($deletedRows === 0) {
             return Response::html('Silinemedi veya bulunamadı', 404);
         }
         return redirectToRoute('home');
     }
}
```

## 5. Request (İstek Yönetimi)

*   `SwallowPHP\Framework\Http\Request` sınıfı. `request()` helper'ı ile veya DI ile erişilir.
*   **Girdi Alma:** `all()`, `query()`, `request()`, `get()`, `getQuery()`, `getRequestValue()`, `rawInput()`. Girdiler otomatik olarak `htmlspecialchars` ile temizlenir.
*   **Diğer Bilgiler:** `getMethod()`, `getPath()`, `getUri()`, `fullUrl()`, `header()`, `headers()`, `getScheme()`, `getHost()`, `getClientIp()` (ve `getIp()` helper'ı).
*   **Dosya Yükleme / Çerez Okuma:** Doğrudan `Request` sınıfında metod **yok**. `$_FILES` ve `$_COOKIE` global değişkenleri doğrudan kullanılmalıdır (ancak `Cookie::get` şifreli çerezleri okur).

## 6. Response (Yanıt Yönetimi)

*   `SwallowPHP\Framework\Http\Response` sınıfı.
*   **Oluşturma:** `new Response()`, `Response::html()`, `Response::json()`, `Response::redirect()`.
*   **Ayarlama:** `setContent()`, `setStatusCode()`, `header()`.
*   **Gönderme:** `$response->send()` (Controller'dan döndürülürse otomatik).
*   **Yardımcılar:** `sendJson()`, `redirect()` (exit yapmaz!), `redirectToRoute()` (exit yapar!). `response()` helper'ı **yok**.
*   **Güvenli/Şifreli Çerez Ayarlama:** `SwallowPHP\Framework\Http\Cookie::set()` metodu kullanılmalıdır. Bu metod çerezleri otomatik olarak şifreler ve HMAC ile korur (`APP_KEY` gereklidir). Varsayılan ayarlar `config/session.php`'den alınır.

## 7. View (Görünümler)

*   `config('app.view_path')` altında basit PHP dosyalarıdır.
*   Controller'lardan view dosyalarını render etmek ve veri göndermek için `view()` helper fonksiyonu kullanılır.
*   `view(string $viewAdi, array $veri = [], ?string $layoutAdi = null): Response`
    *   `$viewAdi`: Render edilecek view dosyasının adı (nokta notasyonu kullanılır, örn: `users.show` -> `users/show.php`).
    *   `$veri`: View ve layout'a gönderilecek verileri içeren dizi (anahtarlar değişken adı olur).
    *   `$layoutAdi`: View'ı sarmalayacak layout dosyasının adı (isteğe bağlı, nokta notasyonu kullanılır, örn: `layouts.app`). Layout içinde, render edilen ana view'ın içeriği `$slot` adlı özel bir değişkene atanır.
*   `view()` fonksiyonu otomatik olarak bir `Response` nesnesi döndürür.
*   View içinde `htmlspecialchars()` kullanarak XSS saldırılarına karşı koruma sağlayın.
*   Helper fonksiyonları (`route`, `config`, `csrf_field`, `method`, `formatDateForHumans` vb.) view içinde kullanılabilir.

**Örnek View (`resources/views/users/show.php`):**

```php
<?php // Bu dosya resources/views/users/show.php ?>
<h1><?php echo htmlspecialchars($userData['name'] ?? 'Bilinmiyor'); ?></h1>
<p>E-posta: <?php echo htmlspecialchars($userData['email'] ?? ''); ?></p>
<?php if (isset($userData['created_at'])): ?>
    <p>Kayıt Tarihi: <?php echo formatDateForHumans($userData['created_at']); ?></p>
<?php endif; ?>
<hr>
<p>Bu kısım show.php içeriğidir ve layout içinde $slot olarak görünecektir.</p>
```

**Örnek Layout (`resources/views/layouts/app.php`):**

```php
<?php // Bu dosya resources/views/layouts/app.php ?>
<!DOCTYPE html>
<html lang="<?php echo config('app.locale', 'tr'); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle ?? config('app.name', 'SwallowPHP')); // Controller'dan gelen başlık veya varsayılan ?></title>
    <style>
        body { font-family: sans-serif; padding: 1em; }
        header, footer { background-color: #f1f1f1; padding: 1em; margin-bottom: 1em; }
        main { border: 1px solid #ccc; padding: 1em; }
    </style>
</head>
<body>
    <header>
        <h1>Uygulama Layout Başlığı</h1>
        <nav> <a href="<?php echo route('home'); ?>">Anasayfa</a> | Diğer Linkler... </nav>
    </header>

    <main>
        <h2>Ana İçerik Alanı</h2>
        <?php
        // $slot değişkeni, view() helper'ı tarafından otomatik olarak oluşturulur
        // ve çağrılan view dosyasının (örn: users/show.php) render edilmiş
        // içeriğini tutar. Bu içeriği layout'un istediğiniz yerine basabilirsiniz.
        echo $slot;
        ?>
    </main>

    <footer>
        <p>&copy; <?php echo date('Y'); ?> <?php echo config('app.name'); ?></p>
    </footer>
</body>
</html>
```

**Controller'da Kullanım:**

```php
// UserController.php -> show() metodu içinde:
return view(
    'users.show', // Render edilecek view
    ['userData' => $user->toArray(), 'pageTitle' => $user->name], // View ve Layout'a gidecek veri
    'layouts.app' // Kullanılacak layout
);
```

## 8. Veritabanı ve Modeller

### 8.1. Sorgu Oluşturucu (Query Builder)

*   `SwallowPHP\Framework\Database\Database` sınıfı. `db()` helper'ı ile veya DI ile erişilir.
*   **Desteklenen Sürücü:** Şu anda **sadece MySQL** desteklenmektedir.
*   **Tablo Öneki:** `config/database.php`'deki `prefix` ayarı **otomatik uygulanmaz**. Gerekirse manuel olarak `db()->table('prefix_users')` şeklinde kullanılmalıdır.
*   Metodlar: `table`, `select`, `where`, `orWhere`, `whereIn`, `whereBetween`, `whereRaw`, `orderBy`, `limit`, `offset`, `get`, `first`, `insert`, `update`, `delete`, `count`, `paginate`, `cursorPaginate`.
*   **Durum:** Sorgu durumu (`where` vb.) sorgu sonrası sıfırlanmaz. Her yeni mantıksal sorgu için `db()` helper'ını tekrar çağırın veya `Model::query()` kullanın.

### 8.2. Modeller (Aktif Kayıt Benzeri)

*   `app/Models` altında bulunur, `SwallowPHP\Framework\Database\Model`'den türer.
*   **Tablo Öneki:** Model içindeki `$table` özelliğinde veya `getTable()` metodunda önek manuel olarak belirtilmelidir (örn: `protected static string $table = 'prefix_users';`).
*   **Kimlik Doğrulama:** Kullanıcı modeli `SwallowPHP\Framework\Auth\AuthenticatableModel`'den türemelidir (`AuthenticatableInterface` uygular ve `AuthenticatableTrait` kullanır).
*   **Temel Ayarlar:** `protected static string $table`, `protected array $fillable`, `protected array $guarded`, `protected array $hidden`, `protected array $casts`.
*   **Sorgulama:** Statik metodlar (`where`, `get`, `first`, `paginate` vb.) `Model::query()->...` çağrılarına kısayoldur.
*   **Oluşturma:** `User::create([...])` (fillable kontrolü yapar, `created_at`/`updated_at` ekler, kaydeder).
*   **Güncelleme/Kaydetme:** Model örneği üzerinden `$user->fill([...])` (fillable kontrolü için önerilir) ve `$user->save()` kullanılır.
*   **Silme:** Model örneği üzerinden `delete()` metodu **yok**. Query Builder ile silinmelidir: `User::where('id', '=', 1)->delete();`
*   **İlişkiler:** `hasMany()` ve `belongsTo()` metodları mevcuttur ancak doğrudan ilişkili model(ler)in **sonuçlarını** (dizi veya model) döndürürler, sorgu nesnesi değil.
*   **Olaylar:** `creating`, `created`, `updating`, `updated`, `saving`, `saved`. `User::on('event_name', function($model) { ... });` ile dinleyici eklenebilir.

## 9. Middleware

*   `app/Middleware` altında bulunur, `SwallowPHP\Framework\Http\Middleware\Middleware`'den türer, `handle()` metodunu override eder.
*   **Global Middleware:** `VerifyCsrfToken` (`App::run` içinde).
*   **Rota Middleware:** Rota tanımında `->middleware(new MiddlewareAdi())` ile eklenir.

## 10. Kimlik Doğrulama (Authentication)

*   `SwallowPHP\Framework\Auth\Auth` sınıfı kullanılır (`auth()` helper'ı **yok**).
*   Kullanıcı modeli `config('auth.model')` ile belirlenir ve `AuthenticatableModel`'den türemelidir.
*   **Çerez Tabanlıdır:** Oturum bilgileri şifrelenmiş (`APP_KEY` ile) çerezlerde saklanır (`user` ve `remember`).
*   **Metodlar:** `Auth::authenticate()`, `Auth::isAuthenticated()`, `Auth::user()`, `Auth::logout()`, `Auth::isAdmin()`. (`check()` ve `id()` metodları **yok**, `Auth::user()->getAuthIdentifier()` kullanılmalı).
*   **Brute-Force Koruması:** `config/auth.php`'deki ayarlara göre çalışır (`max_attempts`, `lockout_time`) ve `Cache` kullanır.

## 11. Önbellekleme (Caching)

*   `SwallowPHP\Framework\Contracts\CacheInterface` (PSR-16) kullanılır. `cache()` helper'ı veya DI ile erişilir.
*   Sürücüler (`file`, `sqlite`) `config/cache.php` ile ayarlanır.
*   **Metodlar:** PSR-16 metodları (`get`, `set`, `delete`, `clear`, `has` vb.).
*   `remember` metodu **yoktur**.
*   **Önek/Varsayılan TTL:** `config/cache.php`'deki `prefix` ve `ttl` ayarları **otomatik uygulanmaz**. `set` içinde TTL belirtilmeli, önek gerekiyorsa manuel eklenmelidir.

## 12. Yardımcı Fonksiyonlar (Helpers)

Framework aşağıdaki global yardımcı fonksiyonları sağlar (`src/Methods.php`):

`config`, `env`, `shortenText`, `method`, `route`, `slug`, `redirectToRoute`, `mailto`, `request`, `formatDateForHumans`, `hasRoute`, `redirect`, `db`, `sendJson`, `cache`, `getIp`, `csrf_field`, `getFile`, `webpImage`, `send`, `removeDuplicates`, `printVariable`, `view`.

**Önemli Eksik Yardımcılar:** `auth()`, `abort()`, `bcrypt()`, `response()`.

## 13. Hata Yönetimi (Exception Handling)

*   Hatalar `App::run()` tarafından yakalanır ve `SwallowPHP\Framework\Foundation\ExceptionHandler::handle()` ile işlenir.
*   `config('app.debug')` moduna ve isteğin `Accept` başlığına göre (JSON veya HTML) hata çıktısı değişir.
*   Spesifik istisnalar (`RouteNotFoundException`, `RateLimitExceededException`, `CsrfTokenMismatchException` vb.) özel HTTP durum kodlarıyla eşlenir.

Bu kapsamlı analiz ve güncelleme sonrası rehberin, framework'ün mevcut yeteneklerini doğru bir şekilde yansıttığından emin olabilirsiniz.
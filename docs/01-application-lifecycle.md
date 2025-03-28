# Uygulama Yaşam Döngüsü

SwallowPHP uygulamasının temelini `SwallowPHP\Framework\Foundation\App` sınıfı oluşturur. Bu sınıf, uygulamanın başlatılmasından, bağımlılıkların yönetilmesinden (DI Container) ve gelen HTTP isteklerinin işlenip yanıtlanmasından sorumludur.

## Başlangıç Noktası: `public/index.php`

Her SwallowPHP uygulaması, genellikle `public/index.php` dosyasından başlar. Bu dosya oldukça basittir:

1.  Composer'ın autoload dosyasını dahil eder.
2.  Framework'ün helper fonksiyonlarını (`src/Methods.php`) dahil eder.
3.  `App` sınıfının singleton örneğini alır (`App::getInstance()`).
4.  `App::run()` metodunu çağırarak uygulamayı çalıştırır.

```php
<?php

// public/index.php

// Composer Autoloader
require __DIR__.'/../vendor/autoload.php';

// Framework Helpers
require __DIR__.'/../src/Methods.php'; // Helper fonksiyonları yükle

// Uygulamayı Başlat
use SwallowPHP\Framework\Foundation\App;

$app = App::getInstance(); // Singleton App örneğini al
$app->run(); // Uygulama yaşam döngüsünü başlat

```

## `App::run()` Metodu

`App::run()` metodu, uygulamanın ana yaşam döngüsünü yönetir:

1.  **Singleton ve Konteyner Başlatma:** `App::getInstance()` çağrısıyla `App` sınıfının tekil örneği oluşturulur veya alınır. Bu işlem sırasında `App::container()` metodu da çağrılarak Dependency Injection (DI) konteyneri (`League\Container`) başlatılır ve temel servisler kaydedilir.
2.  **Ortam Değişkenleri Yükleme:** `Env::load()` çağrılarak `.env` dosyasındaki değişkenler yüklenir.
3.  **Temel PHP Ayarları:** `config/app.php` dosyasındaki ayarlara göre `set_time_limit`, `error_reporting` gibi PHP ayarları yapılır. İsteğe bağlı olarak HTTPS yönlendirmesi (`ssl_redirect`) aktif edilebilir.
4.  **İstek (Request) Oluşturma:** `Request::createFromGlobals()` metodu kullanılarak mevcut HTTP isteğini temsil eden `Request` nesnesi oluşturulur ve DI konteynerine kaydedilir (`Request::class`).
5.  **Oturum (Session) Başlatma:** PHP'nin yerleşik oturum yönetimi `session_start()` ile başlatılır (eğer zaten başlatılmamışsa).
6.  **Çıktı Tamponlama (Output Buffering) ve Gzip:** `ob_start()` ile çıktı tamponlama başlatılır. `config/app.php`'deki `gzip_compression` ayarı `true` ise ve tarayıcı destekliyorsa, Gzip sıkıştırması etkinleştirilir.
7.  **Global Middleware:** Şu anda sadece `VerifyCsrfToken` middleware'i global olarak uygulanır. Gelen istek önce bu middleware'den geçer.
8.  **Yönlendirme (Routing):** İstek, `Router::dispatch()` metoduna gönderilir. Router, isteğin URI ve metoduna uygun rotayı bulur ve ilgili Controller action'ını çalıştırır.
9.  **Yanıt (Response) Oluşturma:** Controller action'ından veya middleware'den dönen değer bir `Response` nesnesi değilse, framework otomatik olarak içeriğe göre bir `Response::json()` veya `Response::html()` nesnesi oluşturmaya çalışır.
10. **Yanıt Gönderme:** Elde edilen `Response` nesnesinin `send()` metodu çağrılarak başlıklar (headers) ve içerik tarayıcıya gönderilir.
11. **Çıktı Tamponunu Gönderme:** `ob_end_flush()` ile tamponlanan tüm çıktılar gönderilir.
12. **Exception Handling:** Yaşam döngüsünün herhangi bir aşamasında bir `Throwable` (Exception veya Error) fırlatılırsa, `ExceptionHandler::handle()` metodu devreye girer, hatayı yakalar ve uygun bir hata yanıtı oluşturur.

## Dependency Injection (DI) Konteyneri

SwallowPHP, bağımlılıkları yönetmek için `league/container` paketini kullanır. Konteynere `App::container()` statik metoduyla erişilebilir.

`App::container()` ilk çağrıldığında, aşağıdaki temel servisler konteynere kaydedilir (genellikle singleton olarak):

-   **`SwallowPHP\Framework\Foundation\Config`:** Yapılandırma dosyalarını okumak ve yönetmek için. `config()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Contracts\CacheInterface`:** Varsayılan cache sürücüsünü (`FileCache` veya `SqliteCache`) sağlar. `cache()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Http\Request`:** Mevcut HTTP isteğini temsil eder. `request()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Database\Database`:** Query Builder örneğini sağlar. `db()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Routing\Router`:** Rota tanımlama ve eşleştirme işlemlerini yönetir. `route()`, `redirectToRoute()`, `hasRoute()` helper'ları bu servisi kullanır.

Kendi servislerinizi veya sınıflarınızı da konteynere kaydedebilir ve uygulamanızın farklı yerlerinde (örn. Controller'lar içinde) otomatik olarak enjekte edilmesini sağlayabilirsiniz. (Not: Konteynere servis ekleme mekanizması henüz tam olarak dökümante edilmemiştir, genellikle bir Service Provider yapısı kullanılır).

## Diğer Önemli Metotlar

-   **`App::getInstance(): App`:** `App` sınıfının singleton örneğini döndürür.
-   **`App::container(): Container`:** DI Konteyner örneğini döndürür.
-   **`App::run(): void`:** Uygulama yaşam döngüsünü başlatır.
-   **`App::getRouter(): Router`:** Kayıtlı `Router` örneğini döndürür.
-   **`App::handleRequest(Request $request): mixed`:** Verilen `Request` nesnesini `Router`'a gönderir.
-   **`App::getViewDirectory(): ?string`:** View dosyalarının bulunduğu dizinin yolunu döndürür (şu anda `config('app.view_path')` ayarından alınır).
# Yapılandırma

SwallowPHP, uygulama ayarlarını yönetmek için basit ve etkili bir yapılandırma sistemi kullanır. Ayarlar, `config/` dizinindeki PHP dosyalarında saklanır ve `.env` dosyası aracılığıyla ortama özgü değerlerle kolayca değiştirilebilir.

## `.env` Dosyası

Projenizin kök dizininde bulunan `.env` dosyası, ortama göre değişen veya hassas bilgileri (veritabanı şifreleri, API anahtarları vb.) saklamak için kullanılır. Bu dosya genellikle sürüm kontrol sistemine (örn. Git) dahil edilmez. Bunun yerine, `.env.example` gibi bir örnek dosya oluşturulur.

`.env` dosyası basit bir anahtar=değer formatı kullanır:

```dotenv
# .env dosyası örneği

APP_NAME="SwallowPHP Framework"
APP_ENV=local # local, development, production vb.
APP_KEY=base64:YourApplicationKeyMustBe32BytesLong= # php artisan key:generate ile oluşturulur
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_TIMEZONE=Europe/Istanbul
APP_LOCALE=tr

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=swallowphp
DB_USERNAME=root
DB_PASSWORD=

CACHE_DRIVER=file
SESSION_DRIVER=file
SESSION_LIFETIME=120 # Dakika cinsinden

# Mail Ayarları (Örnek)
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
MAIL_FROM_ADDRESS="hello@example.com"
MAIL_FROM_NAME="${APP_NAME}"
```

-   Yorum satırları `#` ile başlar.
-   Değerler genellikle tırnak içine alınmaz, ancak boşluk içeriyorsa çift tırnak (`"`) kullanılabilir.
-   `Env::load()` metodu, uygulama başladığında bu dosyadaki değerleri okur ve `getenv()`, `$_ENV`, `$_SERVER` aracılığıyla erişilebilir hale getirir.

### `Env` Sınıfı ve `env()` Helper'ı

`SwallowPHP\Framework\Foundation\Env` sınıfı, `.env` dosyasını yüklemek ve değişkenleri okumak için kullanılır.

-   **`Env::load(?string $environmentFile = null)`:** Belirtilen veya proje kökündeki `.env` dosyasını yükler. Genellikle `App::run()` içinde otomatik olarak çağrılır.
-   **`Env::get(string $key, mixed $default = null)`:** Bir ortam değişkeninin değerini alır.
-   **`env(string $key, mixed $default = null)`:** `Env::get()` için global helper fonksiyonudur.

**Önemli Not:** Uygulama kodunuzda (Controller, Model vb.) genellikle doğrudan `env()` fonksiyonunu kullanmaktan kaçının. Bunun yerine, yapılandırma dosyalarında `env()` kullanarak değerleri alın ve uygulama kodunda `config()` helper'ı ile bu yapılandırma değerlerine erişin. Bu, yapılandırmanın önbelleğe alınmasını (caching) mümkün kılar ve test edilebilirliği artırır. `env()` fonksiyonu **sadece** `config/` dizinindeki dosyalarda kullanılmalıdır.

## Yapılandırma Dosyaları (`config/*.php`)

Uygulamanızın tüm yapılandırma ayarları `config/` dizinindeki PHP dosyalarında bulunur. Her dosya bir dizi (array) döndürmelidir.

Örnek (`config/app.php`):

```php
<?php

return [
    'name' => env('APP_NAME', 'SwallowPHP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => env('APP_LOCALE', 'en'),
    'key' => env('APP_KEY'),
    // ... diğer ayarlar
];
```

Bu dosyalardaki değerlere `config()` helper fonksiyonu ile erişilir.

### `Config` Sınıfı ve `config()` Helper'ı

`SwallowPHP\Framework\Foundation\Config` sınıfı, `config/` dizinindeki tüm yapılandırma dosyalarını yükler ve değerlere erişim sağlar.

-   **`new Config(string $configPath)`:** Constructor, yapılandırma dosyalarının bulunduğu dizinin yolunu alır.
-   **`get(string $key, mixed $default = null)`:** Belirtilen anahtara sahip yapılandırma değerini alır. Nokta notasyonu (`.`) kullanarak iç içe geçmiş değerlere erişilebilir (örn. `database.connections.mysql.host`).
-   **`set(string $key, mixed $value)`:** Çalışma zamanında bir yapılandırma değerini ayarlar veya değiştirir (bu genellikle geçicidir ve kalıcı olmaz).
-   **`config(array|string|null $key = null, mixed $default = null)`:** `Config` sınıfını kullanan global helper fonksiyonudur.
    -   `config('app.name')`: Değeri alır.
    -   `config()`: `Config` nesnesinin kendisini döndürür.
    -   `config(['app.name' => 'Yeni İsim'])`: Değeri ayarlar.

**Örnek Kullanım:**

```php
// Uygulama adını al
$appName = config('app.name');

// Veritabanı host'unu al (varsayılan '127.0.0.1')
$dbHost = config('database.connections.mysql.host', '127.0.0.1');

// Debug modu aktif mi?
if (config('app.debug')) {
    // Debug işlemleri yap
}
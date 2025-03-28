# Cache

SwallowPHP, sık erişilen verileri depolayarak uygulamanızın performansını artırmak için bir cache sistemi sunar. Sistem, farklı depolama mekanizmalarını (sürücüler) destekler ve `SwallowPHP\Framework\Cache\CacheManager` sınıfı aracılığıyla yönetilir.

## Yapılandırma

Cache ayarları `config/cache.php` dosyasında yapılır.

```php
// config/cache.php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | Bu seçenek, uygulamanız tarafından varsayılan olarak kullanılacak olan
    | cache sürücüsünü kontrol eder. Farklı ortamlar için farklı sürücüler
    | belirleyebilirsiniz.
    |
    | Desteklenen Sürücüler: "file", "sqlite"
    |
    */
    'default' => env('CACHE_DRIVER', 'file'), // Varsayılan sürücü

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Burada uygulamanız için cache sürücülerini tanımlayabilirsiniz.
    | Birden fazla sürücü tanımlanabilir.
    |
    */
    'stores' => [
        'file' => [
            'driver' => 'file',
            // Dosya tabanlı cache için göreceli yol (storage_path altında)
            'path' => 'cache/data.json',
            // Cache dosyasının maksimum boyutu (byte cinsinden, örn. 50MB)
            'max_size' => 52428800,
        ],

        'sqlite' => [
            'driver' => 'sqlite',
            // SQLite veritabanı dosyası için göreceli yol (storage_path altında)
            'path' => 'cache/database.sqlite',
            // SQLite tablosu adı
            'table' => 'cache',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | Cache'de saklanan tüm anahtarlar için bir önek belirleyebilirsiniz.
    | Bu, aynı sunucuda birden fazla uygulama çalıştırırken çakışmaları önler.
    |
    */
    'prefix' => env('CACHE_PREFIX', 'swallowphp_cache_'), // Önek (henüz CacheManager'da kullanılmıyor)

    /*
    |--------------------------------------------------------------------------
    | Default Cache TTL (Time To Live)
    |--------------------------------------------------------------------------
    |
    | Cache'e yazılan verilerin varsayılan olarak ne kadar süreyle (saniye cinsinden)
    | geçerli olacağını belirler. Sürücüler bu değeri kullanabilir.
    |
    */
    'ttl' => env('CACHE_TTL', 3600), // Varsayılan TTL (saniye)
];
```

-   **`default`**: `cache()` helper'ı veya `CacheManager::driver()` parametresiz çağrıldığında kullanılacak varsayılan sürücüyü belirtir (`file` veya `sqlite`).
-   **`stores`**: Her sürücü için özel ayarları içerir.
    -   `file`: Cache verilerinin saklanacağı JSON dosyasının `storage` dizini altındaki yolunu (`path`) ve dosyanın maksimum boyutunu (`max_size`) belirtir.
    -   `sqlite`: Cache verilerinin saklanacağı SQLite veritabanı dosyasının `storage` dizini altındaki yolunu (`path`) ve kullanılacak tablo adını (`table`) belirtir.
-   **`prefix`**: Cache anahtarları için bir önek (şu anda manager tarafından otomatik uygulanmıyor, manuel olarak kullanılabilir).
-   **`ttl`**: Cache'e yazılan veriler için varsayılan geçerlilik süresi (saniye cinsinden).

## CacheManager Kullanımı

`CacheManager`, doğrudan veya `cache()` helper fonksiyonu aracılığıyla kullanılır.

### `CacheManager::driver(?string $driver = null): CacheInterface`

Belirtilen sürücü için bir `CacheInterface` implementasyonu döndürür. Eğer `$driver` null ise, `config/cache.php` dosyasındaki varsayılan sürücüyü kullanır.

```php
use SwallowPHP\Framework\Cache\CacheManager;

// Varsayılan sürücüyü al (config/cache.php'deki 'default' ayarına göre)
$cache = CacheManager::driver();

// File sürücüsünü al
$fileCache = CacheManager::driver('file');

// SQLite sürücüsünü al
$sqliteCache = CacheManager::driver('sqlite');

// Cache işlemlerini yap
$fileCache->set('user:1', ['name' => 'John Doe'], 60); // 60 saniye TTL
$userData = $sqliteCache->get('user:1');
```

### `cache()` Helper Fonksiyonu

`cache()` helper fonksiyonu, varsayılan cache sürücüsüne erişmenin en kolay yoludur. Arka planda `CacheManager::driver()` metodunu çağırır.

```php
// Varsayılan sürücüyü kullanarak cache işlemleri
cache()->set('settings', ['theme' => 'dark'], 3600); // 1 saat TTL
$settings = cache()->get('settings');

if (cache()->has('settings')) {
    // ...
}

cache()->delete('settings');
```

## Cache Sürücüleri (`CacheInterface`)

Tüm cache sürücüleri (`FileCache`, `SqliteCache`) `SwallowPHP\Framework\Contracts\CacheInterface` arayüzünü uygular. Bu arayüz aşağıdaki metotları tanımlar:

-   `get(string $key, mixed $default = null): mixed`: Belirtilen anahtara sahip değeri cache'den alır. Bulunamazsa `$default` değerini döndürür.
-   `set(string $key, mixed $value, ?int $ttl = null): bool`: Bir değeri belirtilen anahtarla cache'e yazar. `$ttl` (Time To Live) saniye cinsinden belirtilirse, değer o süre sonunda geçersiz olur. `null` ise varsayılan TTL (`config('cache.ttl')`) kullanılır. Başarılı olursa `true`, olmazsa `false` döndürür.
-   `delete(string $key): bool`: Belirtilen anahtara sahip değeri cache'den siler. Başarılı olursa `true`, olmazsa `false` döndürür.
-   `has(string $key): bool`: Belirtilen anahtarın cache'de olup olmadığını kontrol eder.
-   `clear(): bool`: Cache'deki tüm verileri temizler (destekleyen sürücüler için). Başarılı olursa `true`, olmazsa `false` döndürür.
-   `increment(string $key, int $value = 1): int|false`: Cache'deki bir integer değeri belirtilen miktar kadar artırır. Anahtar yoksa oluşturulur (genellikle 0'dan başlar). Yeni değeri veya hata durumunda `false` döndürür.
-   `decrement(string $key, int $value = 1): int|false`: Cache'deki bir integer değeri belirtilen miktar kadar azaltır. Yeni değeri veya hata durumunda `false` döndürür.

**Not:** `increment` ve `decrement` metotlarının atomik (yarış koşullarına karşı güvenli) olup olmadığı sürücüye bağlıdır. `FileCache` atomik değildir, `SqliteCache` daha güvenilir olabilir.


### File Sürücüsü (`FileCache`)

Bu sürücü, tüm cache verilerini tek bir JSON dosyasında saklar. Basit uygulamalar ve geliştirme ortamları için uygundur.

-   **Yapılandırma (`config/cache.php`):**
    ```php
    'stores' => [
        'file' => [
            'driver' => 'file',
            // Dosya yolu (storage dizini altında)
            'path' => 'cache/data.json',
            // Maksimum dosya boyutu (byte)
            'max_size' => 52428800, // 50MB
        ],
        // ...
    ],
    ```
-   **Özellikler:**
    -   Tüm veriyi tek bir dosyada tutar.
    -   Dosya okuma/yazma işlemleri yapar, bu nedenle yüksek trafikli uygulamalarda performans darboğazı olabilir.
    -   Dosya kilitleme (locking) kullanarak eş zamanlı yazma problemlerini azaltmaya çalışır.
    -   Yapılandırılan `max_size` aşıldığında, en eski cache girdilerini otomatik olarak siler (pruning).
    -   `increment` ve `decrement` işlemleri atomik değildir (yarış koşullarına açıktır).
-   **Gereksinimler:** Cache dosyasının bulunduğu dizinin (`storage/cache`) yazılabilir olması gerekir.


### SQLite Sürücüsü (`SqliteCache`)

Bu sürücü, cache verilerini bir SQLite veritabanı dosyasında saklar. `FileCache`'e göre genellikle daha performanslıdır ve daha iyi eş zamanlılık yönetimi sunar (WAL modu sayesinde).

-   **Yapılandırma (`config/cache.php`):**
    ```php
    'stores' => [
        // ...
        'sqlite' => [
            'driver' => 'sqlite',
            // Veritabanı dosyası yolu (storage dizini altında)
            'path' => 'cache/database.sqlite',
            // Kullanılacak tablo adı
            'table' => 'cache',
        ],
    ],
    ```
-   **Özellikler:**
    -   Verileri SQLite veritabanında (`key`, `value`, `expiration` kolonları) saklar.
    -   `value` sütununda veriler JSON formatında saklanır.
    -   `FileCache`'den daha iyi yazma performansı ve eş zamanlılık sunabilir (özellikle WAL modu aktifse).
    -   Süresi dolan girdiler `get` veya `getMultiple` işlemleri sırasında otomatik olarak silinir.
    -   `increment` ve `decrement` işlemleri veritabanı seviyesinde daha güvenilir olabilir (ancak tam atomiklik garanti edilmez, uygulama seviyesinde kilitleme gerekebilir).
-   **Gereksinimler:**
    -   PHP `pdo_sqlite` eklentisinin etkin olması gerekir.
    -   Veritabanı dosyasının bulunduğu dizinin (`storage/cache`) yazılabilir olması gerekir.
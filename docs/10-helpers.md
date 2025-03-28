# Helper Fonksiyonları

Bu dosya, SwallowPHP framework'ü içinde tanımlanmış global yardımcı fonksiyonları açıklar.

## Yapılandırma

### `config($key = null, $default = null)`

Yapılandırma değerlerini almak veya ayarlamak için kullanılır. `src/Config` dizinindeki dosyalara erişir.

-   **Parametreler:**
    *   `$key` (string|array|null): Alınacak yapılandırma anahtarı (nokta notasyonu destekler, örn. `'app.name'`, `'database.connections.mysql.host'`). Eğer bir dizi verilirse, anahtar/değer çiftleri olarak yapılandırma ayarlar. Eğer `null` verilirse, `Config` nesnesinin kendisini döndürür.
    *   `$default` (mixed|null): Anahtar bulunamazsa döndürülecek varsayılan değer.
-   **Dönüş Değeri:** İstenen yapılandırma değeri, `$key` null ise `Config` nesnesi veya `$key` bir dizi ise `null`.

**Örnekler:**

```php
// app.name değerini al
$appName = config('app.name', 'SwallowPHP');

// Varsayılan veritabanı bağlantısını al
$defaultConnection = config('database.default');

// MySQL host adresini al, bulunamazsa '127.0.0.1' kullan
$mysqlHost = config('database.connections.mysql.host', '127.0.0.1');

// Config nesnesinin tamamını al
$configInstance = config();

// Yeni bir ayar ekle veya mevcut olanı değiştir
config(['app.new_setting' => 'new_value']);
```

### `env($key, $default = null)`

Bir ortam değişkeninin (`.env` dosyasından veya sistem ortamından) değerini alır. Yapılandırma dosyaları (`config/*.php`) içinde, hassas veya ortama özgü değerleri okumak için kullanılır. Uygulama kodunda genellikle doğrudan `env()` yerine `config()` kullanılması tercih edilir.

-   **Parametreler:**
    *   `$key` (string): Ortam değişkeninin adı.
    *   `$default` (mixed|null): Değişken bulunamazsa döndürülecek varsayılan değer.
-   **Dönüş Değeri:** Ortam değişkeninin değeri veya varsayılan değer.

**Örnek (`config/database.php` içinde):**

```php
'mysql' => [
    'driver' => 'mysql',
    'host' => env('DB_HOST', '127.0.0.1'), // .env dosyasından DB_HOST'u oku
    'password' => env('DB_PASSWORD'), // .env dosyasından DB_PASSWORD'u oku
    // ...
],
```

## İstek &amp; Rota

### `request()`

Mevcut HTTP isteğini temsil eden `SwallowPHP\Framework\Http\Request` nesnesinin örneğini DI konteynerinden alır.

-   **Dönüş Değeri:** `SwallowPHP\Framework\Http\Request`

**Örnek:**

```php
// Mevcut isteği al
$request = request();

// İstekten bir değer al (önce body, sonra query)
$name = $request->get('name', 'Guest');

// Sadece query parametresinden al
$page = $request->getQuery('page', 1);

// Request header'ını al
$contentType = $request->header('Content-Type');

// İstek metodunu al
$method = $request->getMethod();
```

### `route($name, $params = [])`

Verilen isme sahip bir rota için URL oluşturur. Rota parametrelerini URL'e yerleştirir.

-   **Parametreler:**
    *   `$name` (string): Oluşturulacak rotanın adı (Rota tanımlanırken `->name()` ile belirtilir).
    *   `$params` (array): Rota parametreleri için anahtar/değer dizisi.
-   **Dönüş Değeri:** (string) Oluşturulan tam URL.

**Örnek:**

```php
// Rota tanımı (örn. routes/web.php içinde):
// Router::get('/users/{id}/profile', 'UserController@profile')->name('user.profile');

// URL oluşturma:
$profileUrl = route('user.profile', ['id' => 15]);
// $profileUrl -> http://yourdomain.com/users/15/profile (varsayılan olarak)
```

### `redirectToRoute($urlName, $params = [])`

Kullanıcıyı belirtilen isme sahip rotanın URL'ine yönlendirir.

-   **Parametreler:**
    *   `$urlName` (string): Yönlendirilecek rotanın adı.
    *   `$params` (array): Rota parametreleri.
-   **Dönüş Değeri:** `void` (Script'i sonlandırır).

**Örnek:**

```php
// Kullanıcıyı profil sayfasına yönlendir
redirectToRoute('user.profile', ['id' => 15]);
```

### `hasRoute($name)`

Verilen isimde bir rotanın tanımlanıp tanımlanmadığını kontrol eder.

-   **Parametreler:**
    *   `$name` (string): Kontrol edilecek rotanın adı.
-   **Dönüş Değeri:** (bool) Rota tanımlıysa `true`, değilse `false`.

**Örnek:**

```php
if (hasRoute('admin.dashboard')) {
    // Admin paneli rotası var
}
```

### `redirect($uri, $code)`

Kullanıcıyı belirtilen URI'a yönlendirir.

-   **Parametreler:**
    *   `$uri` (string): Yönlendirilecek URI.
    *   `$code` (int): HTTP yönlendirme durum kodu (örn. 301, 302).
-   **Dönüş Değeri:** `void`

**Örnek:**

```php
// Ana sayfaya yönlendir
redirect('/', 302);
```

## Veritabanı &amp; Cache

### `db()`

Varsayılan veritabanı bağlantısı için `SwallowPHP\Framework\Database\Database` (Query Builder) örneğini DI konteynerinden alır.

-   **Dönüş Değeri:** `SwallowPHP\Framework\Database\Database`

**Örnek:**

```php
// Tüm kullanıcıları al
$users = db()->table('users')->get();

// ID'si 5 olan kullanıcıyı al
$user = db()->table('users')->where('id', '=', 5)->first();
```

### `cache(?string $driver = null)`

Varsayılan cache sürücüsü için `SwallowPHP\Framework\Contracts\CacheInterface` implementasyonunu (örn. `FileCache`, `SqliteCache`) DI konteynerinden alır.

-   **Parametreler:**
    *   `$driver` (string|null): İsteğe bağlı olarak kullanılacak sürücü adı (şu an için genellikle `null` bırakılır).
-   **Dönüş Değeri:** `SwallowPHP\Framework\Contracts\CacheInterface`

**Örnek:**

```php
// Cache'e bir değer yaz (varsayılan TTL ile)
cache()->set('my_key', 'my_value');

// Cache'e 60 saniye süreli bir değer yaz
cache()->set('another_key', ['data' => 123], 60);

// Cache'den bir değer oku
$value = cache()->get('my_key', 'default_value');

// Cache'den bir değeri sil
cache()->delete('my_key');

// Cache'de anahtar var mı kontrol et
if (cache()->has('my_key')) {
    // ...
}
```

## Formlar &amp; Güvenlik

### `method($method)`

HTML formlarında `PUT`, `PATCH`, `DELETE` gibi metotları taklit etmek (method spoofing) için gizli bir `_method` input alanı oluşturur. Formun metodu `POST` olmalıdır.

-   **Parametreler:**
    *   `$method` (string): Taklit edilecek metot (örn. `'PUT'`, `'DELETE'`).
-   **Dönüş Değeri:** `void` (Doğrudan `echo` yapar).

**Örnek (HTML Form içinde):**

```html
<form action="/posts/1" method="POST">
    <?php method('PUT'); ?>
    <?php csrf_field(); ?>
    <!-- Diğer form alanları -->
    <button type="submit">Update Post</button>
</form>
```

### `csrf_field()`

CSRF koruması için gerekli olan gizli `_token` input alanını oluşturur. Bu, `POST`, `PUT`, `PATCH`, `DELETE` metotlarını kullanan tüm formlara eklenmelidir.

-   **Dönüş Değeri:** `void` (Doğrudan `echo` yapar).

**Örnek (HTML Form içinde):**

```html
<form action="/profile" method="POST">
    <?php csrf_field(); ?>
    <!-- Diğer form alanları -->
    <button type="submit">Save Profile</button>
</form>
```

## Diğer Yardımcılar

### `shortenText($text, $length)`

Verilen metni belirtilen uzunluğa kısaltır ve sonuna `...` ekler. HTML etiketlerini kaldırır.

### `slug($value)`

Verilen metni URL uyumlu bir "slug" formatına dönüştürür (küçük harf, boşluk yerine tire, özel karakterleri kaldırma, Türkçe karakterleri dönüştürme).

### `formatDateForHumans($datetimeString)`

Verilen tarih/saat bilgisini (`YYYY-MM-DD HH:MM:SS` formatında) "X saniye/dakika/saat/gün önce" veya belirli bir günden eskiyse "DD Month YYYY" formatında döndürür. (Not: Ay isimleri sunucu locale'ine göre İngilizce olabilir).

### `getIp()`

İsteği yapan kullanıcının IP adresini (proxy başlıklarını da dikkate alarak) döndürür.

### `getFile($name)`

`files/` dizinindeki bir dosyanın tam URL'ini oluşturur (`APP_URL` yapılandırmasını kullanarak).

### `removeDuplicates($array, $excludeValues)`

Bir dizideki tekrar eden değerleri kaldırır, ancak `$excludeValues` dizisindeki değerlerin tekrar etmesine izin verir (bu fonksiyonun mantığı biraz özel görünüyor, dikkatli kullanılmalı).

### `printVariable(string $variableName)`

Verilen isimdeki değişkenin değerini `echo` ile basar (eğer tanımlıysa). Değişken değişken kullandığı için genellikle önerilmez.

### `send($data)` / `sendJson($data)`

Debug amaçlıdır. Veriyi basar (`print_r` veya JSON formatında) ve script'i sonlandırır (artık `die` yok).
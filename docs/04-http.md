# HTTP Katmanı: İstek, Yanıt ve Çerezler

SwallowPHP, HTTP isteklerini ve yanıtlarını yönetmek için temel sınıflar sunar.

## İstek (`Request`)

(Bu bölüm daha sonra `Request.php` belgelendiğinde doldurulacak)

## Yanıt (`Response`)

(Bu bölüm daha sonra `Response.php` belgelendiğinde doldurulacak)

## Çerezler (`Cookie`)

**Namespace:** `SwallowPHP\Framework\Http`

`Cookie` sınıfı, tarayıcıda güvenli bir şekilde veri saklamak için HTTP çerezlerini yönetmeye yönelik statik metotlar sağlar. Framework tarafından ayarlanan tüm çerezler otomatik olarak şifrelenir ve HMAC ile imzalanır, bu da verilerin kurcalanmasını (tampering) ve okunmasını zorlaştırır.

**Önemli:** Çerez şifrelemesi ve imzalaması için `config/app.php` dosyasında `APP_KEY` değerinin ayarlanmış ve en az 32 byte uzunluğunda olması gerekir.

### Çerez Ayarlama (`Cookie::set`)

`Cookie::set(string $name, mixed $value, int $days = 1, ?string $path = null, ?string $domain = null, ?bool $secure = null, ?bool $httpOnly = null, ?string $sameSite = null): bool`

Yeni bir çerez ayarlar veya mevcut olanı günceller.

-   **Parametreler:**
    *   `$name` (string): Çerezin adı.
    *   `$value` (mixed): Saklanacak değer (otomatik olarak JSON formatına dönüştürülür).
    *   `$days` (int): Çerezin geçerlilik süresi (gün cinsinden). Varsayılan: 1 gün.
    *   `$path` (string|null): Çerezin geçerli olacağı sunucu yolu. `null` ise `config('session.path', '/')` kullanılır.
    *   `$domain` (string|null): Çerezin geçerli olacağı alan adı. `null` ise `config('session.domain', null)` kullanılır.
    *   `$secure` (bool|null): `true` ise çerez sadece HTTPS üzerinden gönderilir. `null` ise `config('session.secure', config('app.env') === 'production')` kullanılır.
    *   `$httpOnly` (bool|null): `true` ise çereze sadece HTTP protokolü üzerinden erişilebilir (JavaScript erişemez). `null` ise `config('session.http_only', true)` kullanılır.
    *   `$sameSite` (string|null): Çerezin gönderileceği site bağlamını kısıtlar ('Lax', 'Strict', 'None'). `null` ise `config('session.same_site', 'Lax')` kullanılır. `None` değeri `secure` bayrağının `true` olmasını gerektirir.
-   **Dönüş Değeri:** Çerez başarıyla ayarlandıysa `true`, aksi takdirde `false`.
-   **Güvenlik:**
    -   Değer, `APP_KEY` kullanılarak AES-256-CBC ile şifrelenir ve HMAC-SHA256 ile imzalanır.
    -   `secure` `true` ise, çerez adına otomatik olarak `__Secure-` ön eki eklenir.

**Örnek:**

```php
use SwallowPHP\Framework\Http\Cookie;

// 7 gün geçerli, sadece HTTPS ve HTTP üzerinden erişilebilen bir çerez ayarla
Cookie::set('user_preference', ['theme' => 'dark', 'lang' => 'tr'], 7);

// Daha kısa süreli bir çerez
Cookie::set('popup_shown', true, 0); // Gün 0 olarak ayarlanırsa genellikle oturum süresince geçerli olur (tarayıcıya bağlı)

// Belirli bir path için çerez
Cookie::set('admin_token', 'xyz', 1, '/admin');
```

### Çerez Alma (`Cookie::get`)

`Cookie::get(string $name, mixed $default = null): mixed`

Belirtilen isimdeki çerezin değerini alır. Değer otomatik olarak doğrulanır (HMAC) ve şifresi çözülür.

-   **Parametreler:**
    *   `$name` (string): Alınacak çerezin adı (`__Secure-` ön eki olmadan). Sınıf hem ön ekli hem ön eksiz versiyonu kontrol eder.
    *   `$default` (mixed|null): Çerez bulunamazsa veya geçersizse (HMAC uyuşmazlığı, şifre çözme hatası) döndürülecek varsayılan değer.
-   **Dönüş Değeri:** Çerezin orijinal değeri (JSON'dan decode edilmiş hali) veya `$default`.

**Örnek:**

```php
use SwallowPHP\Framework\Http\Cookie;

// Kullanıcı tercihlerini al
$preferences = Cookie::get('user_preference', ['theme' => 'light', 'lang' => 'en']);
$theme = $preferences['theme'];

// Popup gösterildi mi?
$popupShown = Cookie::get('popup_shown', false);
```

### Çerez Silme (`Cookie::delete`)

`Cookie::delete(string $name, ?string $path = null, ?string $domain = null): bool`

Belirtilen isimdeki çerezi siler (tarayıcıya geçmiş tarihli bir çerez göndererek).

-   **Parametreler:**
    *   `$name` (string): Silinecek çerezin adı (`__Secure-` ön eki olmadan). Sınıf hem ön ekli hem ön eksiz versiyonu silmeye çalışır.
    *   `$path` (string|null): Çerezin ayarlandığı yol. `null` ise `config('session.path', '/')` kullanılır.
    *   `$domain` (string|null): Çerezin ayarlandığı alan adı. `null` ise `config('session.domain', null)` kullanılır.
-   **Dönüş Değeri:** Silme işlemi denendiyse `true` (çerezin başta var olup olmamasından bağımsız olarak).

**Örnek:**

```php
use SwallowPHP\Framework\Http\Cookie;

// Kullanıcı tercihi çerezini sil
Cookie::delete('user_preference');

// Belirli path'deki çerezi sil
Cookie::delete('admin_token', '/admin');
```

### Çerez Varlığını Kontrol Etme (`Cookie::has`)

`Cookie::has(string $name): bool`

Belirtilen isimde bir çerezin (ön ekli veya ön eksiz) mevcut olup olmadığını kontrol eder. Değerin geçerliliğini (HMAC, şifreleme) kontrol etmez, sadece varlığına bakar.

-   **Parametreler:**
    *   `$name` (string): Kontrol edilecek çerezin adı (`__Secure-` ön eki olmadan).
-   **Dönüş Değeri:** Çerez mevcutsa `true`, değilse `false`.

**Örnek:**

```php
use SwallowPHP\Framework\Http\Cookie;

if (Cookie::has('user_preference')) {
    // Çerez var
}
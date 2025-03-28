# HTTP Katmanı: İstek, Yanıt ve Çerezler

SwallowPHP, HTTP isteklerini ve yanıtlarını yönetmek için temel sınıflar sunar.

## İstek (`Request`)

**Namespace:** `SwallowPHP\Framework\Http`

`Request` sınıfı, gelen HTTP isteğini temsil eder ve istek hakkındaki bilgilere (URI, metot, başlıklar, sorgu parametreleri, istek gövdesi vb.) erişmek için bir arayüz sağlar.

Uygulama yaşam döngüsü sırasında, framework otomatik olarak `Request::createFromGlobals()` metodunu kullanarak global değişkenlerden (`$_SERVER`, `$_POST`, `php://input` vb.) bir `Request` nesnesi oluşturur ve bu nesneyi DI konteynerine kaydeder. Bu nesneye genellikle `request()` helper fonksiyonu veya Controller metotlarına tip ipucu (type-hinting) ile enjekte edilerek erişilir.

### Temel Bilgilere Erişim

```php
// Controller metodu içinde Request nesnesini al
public function show(Request $request, $id)
{
    // İstek URI'ını al (örn. /users/15?page=2)
    $uri = $request->getUri();

    // Sadece path kısmını al (örn. /users/15)
    $path = $request->getPath();

    // İstek metodunu al (GET, POST, PUT vb. - _method override dahil)
    $method = $request->getMethod();

    // İstek şemasını al (http veya https)
    $scheme = $request->getScheme();

    // Host adını al (örn. example.com)
    $host = $request->getHost();

    // Tam URL'i al
    $fullUrl = $request->fullUrl();

    // ...
}
```

### İstek Girdilerine Erişim

`Request` nesnesi, farklı kaynaklardan gelen girdilere erişmek için birleşik bir yol sunar.

**Tüm Girdileri Alma (`all`):**

Query string (`$_GET`) ve istek gövdesinden (`$_POST` veya JSON body) gelen tüm girdileri birleştirilmiş bir dizi olarak döndürür. İstek gövdesindeki değerler, aynı anahtara sahip query string değerlerinin üzerine yazar.

```php
$allInput = $request->all();
```

**Tek Bir Girdi Alma (`get`):**

Belirli bir anahtara sahip girdiyi alır. Önce istek gövdesine, sonra query string'e bakar. Bulunamazsa varsayılan değeri döndürür.

```php
// 'name' girdisini al, yoksa 'Guest' kullan
$name = $request->get('name', 'Guest');
```

**Sadece Query String'den Alma (`getQuery`):**

Sadece URL'deki query string parametrelerinden (`$_GET`) bir değeri alır.

```php
// 'page' query parametresini al, yoksa 1 kullan
$page = $request->getQuery('page', 1);
```

**Sadece İstek Gövdesinden Alma (`getRequestValue`):**

Sadece istek gövdesinden (örn. `$_POST` veya JSON body) bir değeri alır.

```php
// Formdan gelen 'email' değerini al
$email = $request->getRequestValue('email');
```

**Tüm Query Parametrelerini Alma (`query`):**

Query string parametrelerinin tamamını bir dizi olarak döndürür.

```php
$queryParameters = $request->query();
```

**Tüm İstek Gövdesi Parametrelerini Alma (`request`):**

İstek gövdesinden parse edilen parametrelerin tamamını bir dizi olarak döndürür.

```php
$requestBodyParameters = $request->request();
```

**Ham İstek Gövdesi (`rawInput`):**

İsteğin ham (raw) gövde içeriğini string olarak döndürür. Özellikle JSON veya XML gibi yapıları manuel olarak işlemek için kullanışlıdır.

```php
$rawBody = $request->rawInput();
```

### Başlıklara (Headers) Erişim

**Tek Bir Başlık Alma (`header`):**

Belirtilen başlığın değerini alır (başlık adı case-insensitive'dir).

```php
// Content-Type başlığını al
$contentType = $request->header('Content-Type');

// Özel bir başlığı al, yoksa null döner
$apiKey = $request->header('X-Api-Key');

// Authorization başlığını al
$authorization = $request->header('Authorization');
```

**Tüm Başlıkları Alma (`headers`):**

Tüm istek başlıklarını içeren bir dizi döndürür (anahtarlar küçük harflidir).

```php
$allHeaders = $request->headers();
```

### Diğer Metotlar

-   **`getMethod(): string`:** İstek metodunu döndürür (Method Spoofing destekler).
-   **`getUri(): string`:** İstek URI'ını (path + query string) döndürür.
-   **`getPath(): string`:** İstek URI'ının sadece path kısmını döndürür.
-   **`getScheme(): string`:** İstek şemasını ('http' veya 'https') döndürür.
-   **`getHost(): string`:** İstek host adını döndürür.
-   **`fullUrl(): string`:** Tam istek URL'ini döndürür.
-   **`getClientIp(): ?string`:** İstemcinin IP adresini (proxy'leri dikkate alarak) döndürmeye çalışır. (Statik metot, `request()->getClientIp()` olarak da kullanılabilir).
-   **`server(string $key, mixed $default = null): mixed`:** `$_SERVER` dizisinden bir değer alır.

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
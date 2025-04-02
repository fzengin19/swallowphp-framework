# Başlarken & Uygulama Yaşam Döngüsü

Bu bölüm, SwallowPHP framework iskeletini kullanarak yeni bir MVC (Model-View-Controller) projesi başlatmanın adımlarını ve bir isteğin nasıl işlendiğini (uygulama yaşam döngüsü) açıklar.

## Yeni Proje Kurulumu

SwallowPHP ile yeni bir projeye başlamak için aşağıdaki adımları izleyin:

**1. Framework Kodunu Edinme:**

*   Framework iskeletini projeniz için yeni bir dizine kopyalayın veya klonlayın.
    ```bash
    # Örnek: Git ile klonlama
    git clone <repository_url> <yeni_proje_dizini>
    cd <yeni_proje_dizini>
    ```

**2. Bağımlılıkları Yükleme:**

*   Composer kullanarak gerekli PHP kütüphanelerini yükleyin.
    ```bash
    composer install
    ```

**3. Ortam Yapılandırması (`.env`):**

*   Proje kök dizininde, `.env.example` dosyasını `.env` olarak kopyalayın.
*   `.env` dosyasını düzenleyerek uygulamanıza özel ayarları yapın:
    *   `APP_NAME`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `APP_TIMEZONE`, `APP_LOCALE` gibi temel uygulama ayarları.
    *   `APP_KEY`: **Kritik!** Güvenli bir uygulama anahtarı oluşturun (`php -r 'echo \"base64:\".base64_encode(random_bytes(32));'` komutuyla).
    *   `DB_*`: Veritabanı bağlantı bilgileriniz.
    *   `CACHE_DRIVER`: Cache sürücüsü (`file` veya `sqlite`).
    *   Diğer ayarları (örn. `MAIL_*`) ihtiyacınıza göre doldurun.

**4. Web Sunucusu Yapılandırması:**

*   Web sunucunuzun (Nginx, Apache) **belge kökünü (document root)** projenizin `public/` dizinine yönlendirin. Bu, uygulamanızın giriş noktasıdır ve diğer dosyaların doğrudan web erişimine kapalı olmasını sağlar.
*   **URL Yeniden Yazma (URL Rewriting):** Tüm HTTP isteklerinin (mevcut fiziksel dosyalar hariç) `public/index.php` dosyasına yönlendirilmesini sağlayın. Bu, \"güzel URL'ler\" (pretty URLs) oluşturmanızı sağlar. Örnek yapılandırmalar:
    *   **Apache (`public/.htaccess`):**
        ```apache
        <IfModule mod_rewrite.c>
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteCond %{REQUEST_FILENAME} !-d
            RewriteRule ^ index.php [L]
        </IfModule>
        ```
    *   **Nginx:**
        ```nginx
        server {
            listen 80;
            server_name projem.test; # Alan adınızı buraya yazın
            root /path/to/your/project/public; # Projenizin public dizininin tam yolu
            index index.php;

            location / {
                try_files $uri $uri/ /index.php?$query_string;
            }

            location ~ \\.php$ {
                # ... PHP-FPM ayarlarınız ...
                include fastcgi_params;
                fastcgi_pass unix:/run/php/php8.x-fpm.sock;
                fastcgi_index index.php;
                fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            }

            location ~ /\\.ht {
                deny all;
            }
        }
        ```

**5. Dizin İzinleri:**

*   Web sunucusunun `storage/` dizinine ve alt dizinlerine (örn. `storage/cache/`) yazma izni verin.
    ```bash
    sudo chown -R $USER:www-data storage
    sudo chmod -R 775 storage
    ```
    (Kullanıcı/grup adını kendi sisteminize göre ayarlayın).

**6. Uygulamayı Çalıştırma:**

*   Yerel geliştirme için:
    ```bash
    php -S localhost:8000 -t public
    ```
*   Veya yapılandırdığınız web sunucusu üzerinden erişin.

**7. MVC Yapısını Kullanarak Geliştirme:**

*   **Rotalar (`routes/`):** Gelen URL'leri Controller action'larına veya Closure'lara yönlendiren kuralları tanımlayın. (Bkz: [Routing](./03-routing.md))
*   **Controller'lar (`src/Controllers` veya `App\\Controllers`):** İstekleri işleyen, Model'lerle etkileşime giren ve View'lara veri gönderen mantığı içerir.
*   **Modeller (`src/Models` veya `App\\Models`):** Veritabanı tablolarını temsil eder ve veritabanı işlemlerini gerçekleştirir. (Bkz: [Veritabanı: Model](./07-database.md#temel-orm-model-sinifi))
*   **View'lar (`resources/views/`):** Kullanıcı arayüzünü (HTML) oluşturur. (Not: Henüz özel bir view sistemi belgelenmedi).
*   **Middleware'ler (`src/Http/Middleware` veya `App\\Http\\Middleware`):** İstek/yanıt döngüsüne müdahale eden katmanlar (örn. kimlik doğrulama, CSRF). (Bkz: [Middleware](./06-middleware.md))
*   **Yapılandırma (`config/`):** Uygulama genelindeki ayarları içerir. (Bkz: [Yapılandırma](./02-configuration.md))

---

## Uygulama Yaşam Döngüsü (Bir İsteğin İzlediği Yol)

Bir kullanıcı SwallowPHP uygulamanıza bir istek gönderdiğinde aşağıdaki temel adımlar gerçekleşir:

1.  **Giriş Noktası (`public/index.php`):** Web sunucusu, tüm istekleri `public/index.php` dosyasına yönlendirir. Bu dosya:
    *   Composer autoloader'ını yükler.
    *   `src/Methods.php` içindeki helper fonksiyonları yükler.
    *   `App::getInstance()` ile `SwallowPHP\\Framework\\Foundation\\App` sınıfının singleton örneğini alır veya oluşturur.
    *   `$app->run()` metodunu çağırır.

2.  **Uygulama Başlatma (`App::run()`):**
    *   **Konteyner ve Servisler:** DI Konteyneri (`League\\Container`) başlatılır. `Config`, `CacheInterface`, `Request`, `Database`, `Router` gibi temel servisler konteynere kaydedilir.
    *   **Ortam Yükleme:** `.env` dosyası `Env::load()` ile yüklenir.
    *   **Temel Ayarlar:** `config/app.php`'den alınan ayarlarla PHP yapılandırması (zaman sınırı, hata raporlama, saat dilimi vb.) yapılır.
    *   **Request Nesnesi:** Gelen HTTP isteği `Request::createFromGlobals()` ile bir `Request` nesnesine dönüştürülür ve konteynere eklenir.
    *   **Oturum Başlatma:** `session_start()` çağrılır.
    *   **Çıktı Tamponlama:** `ob_start()` çağrılır.

3.  **Middleware Pipeline (Global):**
    *   İstek, tanımlanmış global middleware'lerden geçer. Şu anda bu sadece `VerifyCsrfToken`'dir. Bu middleware, isteğin CSRF saldırılarına karşı güvenli olup olmadığını kontrol eder (okuma istekleri ve `$except` listesi hariç). Başarısız olursa `CsrfTokenMismatchException` fırlatılır.

4.  **Yönlendirme (`Router::dispatch()`):**
    *   `VerifyCsrfToken`'dan geçen istek `Router::dispatch()` metoduna gönderilir.
    *   Router, isteğin HTTP metoduna ve URI'ına göre tanımlanmış rotaları (`routes/` dosyalarındaki) arar.
    *   **Rota Bulunursa:**
        *   Eşleşen `Route` nesnesi bulunur.
        *   Rota için tanımlanmışsa **Rate Limiting** kontrolü yapılır (`RateLimiter::execute()`). Limit aşılırsa `RateLimitExceededException` fırlatılır.
        *   URI'dan yakalanan parametreler (örn. `{id}`) `Request` nesnesine eklenir.
        *   Rotaya atanmış **Route Middleware'leri** çalıştırılır (`Route::execute()` içinde). Middleware'ler isteği işleyebilir veya bir sonraki adıma geçirebilir.
        *   Son olarak, rotanın **Action**'ı (Controller metodu veya Closure) çalıştırılır. Controller metotları için bağımlılıklar (örn. `Request`, rota parametreleri, konteynerdeki servisler) otomatik olarak enjekte edilir.
    *   **Metot Eşleşmezse:** URI için rota var ama metot yanlışsa `MethodNotAllowedException` fırlatılır.
    *   **Rota Bulunamazsa:** Eşleşen hiçbir rota yoksa `RouteNotFoundException` fırlatılır.

5.  **Yanıt Oluşturma:**
    *   Controller action'ı veya Closure bir `SwallowPHP\\Framework\\Http\\Response` nesnesi döndürmelidir.
    *   Eğer farklı bir tür (örn. string, array) döndürülürse, `App::run()` bunu otomatik olarak uygun bir `Response` nesnesine (`Response::html` veya `Response::json`) dönüştürmeye çalışır.

6.  **Middleware Pipeline (Yanıt):**
    *   (Şu anda belirgin bir \"yanıt sonrası\" global middleware katmanı olmasa da, `handle` metodunda `$next($request)`'ten *sonra* kod yazan middleware'ler yanıtı değiştirebilir.)

7.  **Yanıt Gönderme (`Response::send()`):**
    *   Oluşturulan `Response` nesnesinin `send()` metodu çağrılır. Bu metot:
        *   HTTP durum kodunu ayarlar (`http_response_code()`).
        *   Tüm HTTP başlıklarını gönderir (`header()`).
        *   Yanıtın içeriğini (HTML, JSON vb.) `echo` ile basar.

8.  **Çıktı Tamponunu Boşaltma:**
    *   `ob_end_flush()` çağrılarak tamponlanan tüm çıktılar istemciye gönderilir.

9.  **Hata Durumu (`ExceptionHandler::handle()`):**
    *   Eğer yaşam döngüsünün herhangi bir adımında yakalanmayan bir `Throwable` fırlatılırsa (`RouteNotFoundException`, `MethodNotAllowedException`, veritabanı hatası, genel PHP hatası vb.), `App::run()` içindeki `catch` bloğu bunu yakalar ve `ExceptionHandler::handle()`'a gönderir.
    *   `ExceptionHandler`, hatanın türüne ve `app.debug` ayarlarına göre uygun bir hata yanıtı (HTML veya JSON) oluşturur ve gönderir, ardından `exit;` ile script'i sonlandırır.

## Dependency Injection (DI) Konteyneri

(Bu bölüm öncekiyle aynı kalabilir, temel servisleri listeler.)

SwallowPHP, bağımlılıkları yönetmek için `league/container` paketini kullanır. Konteynere `App::container()` statik metoduyla erişilebilir.

`App::container()` ilk çağrıldığında, aşağıdaki temel servisler konteynere kaydedilir (genellikle singleton olarak):

-   **`SwallowPHP\Framework\Foundation\Config`:** Yapılandırma dosyalarını okumak ve yönetmek için. `config()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Contracts\CacheInterface`:** Varsayılan cache sürücüsünü (`FileCache` veya `SqliteCache`) sağlar. `cache()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Http\Request`:** Mevcut HTTP isteğini temsil eder. `request()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Database\Database`:** Query Builder örneğini sağlar. `db()` helper'ı bu servisi kullanır.
-   **`SwallowPHP\Framework\Routing\Router`:** Rota tanımlama ve eşleştirme işlemlerini yönetir. `route()`, `redirectToRoute()`, `hasRoute()` helper'ları bu servisi kullanır.

Kendi servislerinizi veya sınıflarınızı da konteynere kaydedebilir ve uygulamanızın farklı yerlerinde (örn. Controller'lar içinde) otomatik olarak enjekte edilmesini sağlayabilirsiniz. (Not: Konteynere servis ekleme mekanizması henüz tam olarak dökümante edilmemiştir, genellikle bir Service Provider yapısı kullanılır).
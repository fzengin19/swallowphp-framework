# Authentication

SwallowPHP framework'ü, çerez tabanlı basit bir kimlik doğrulama sistemi sunar. Bu sistem `SwallowPHP\Framework\Auth\Auth` sınıfı tarafından yönetilir.

## Temel Kullanım

### `Auth::authenticate(string $email, string $password, bool $remember = false): bool`

Verilen e-posta ve şifre ile kullanıcıyı doğrulamaya çalışır. Başarılı olursa, kullanıcı bilgilerini (ve isteğe bağlı olarak "remember me" bilgisini) güvenli, şifreli çerezlere kaydeder. Başarısız giriş denemelerini takip eder ve belirli bir sınırdan sonra hesabı geçici olarak kilitler (brute-force koruması).

-   **Parametreler:**
    *   `$email`: Kullanıcının e-posta adresi.
    *   `$password`: Kullanıcının şifresi (hash'lenmemiş).
    *   `$remember`: `true` ise, kullanıcı oturumu tarayıcı kapatıldıktan sonra da hatırlanır (genellikle 30 gün).
-   **Dönüş Değeri:** Kimlik doğrulama başarılıysa `true`, değilse `false`.
-   **Exception'lar:**
    *   `AuthenticationLockoutException`: Çok fazla başarısız giriş denemesi nedeniyle hesap kilitlendiğinde fırlatılır (HTTP 429).
    *   `RuntimeException`: Veritabanı hatası veya çerez ayarlama hatası gibi dahili bir sorun oluştuğunda fırlatılır (HTTP 500).

**Örnek:**

```php
// Login Controller içinde
use SwallowPHP\Framework\Auth\Auth;
use SwallowPHP\Framework\Http\Request;
use SwallowPHP\Framework\Exceptions\AuthenticationLockoutException;

// ...

public function login(Request $request)
{
    $email = $request->get('email');
    $password = $request->get('password');
    $remember = (bool) $request->get('remember');

    try {
        if (Auth::authenticate($email, $password, $remember)) {
            // Başarılı giriş, kullanıcıyı yönlendir
            return Response::redirect('/dashboard');
        } else {
            // Başarısız giriş (geçersiz bilgiler)
            return Response::redirect('/login')->withErrors(['email' => 'Invalid credentials.']);
        }
    } catch (AuthenticationLockoutException $e) {
        // Hesap kilitli
        return Response::redirect('/login')->withErrors(['email' => $e->getMessage()]);
    } catch (\RuntimeException $e) {
        // Diğer hatalar (DB, Cookie vb.)
        error_log($e->getMessage()); // Hata logla
        return Response::redirect('/login')->withErrors(['email' => 'An internal error occurred.']);
    }
}
```

### `Auth::logout(): void`

Mevcut kullanıcı oturumunu sonlandırır. İlgili çerezleri siler.

**Örnek:**

```php
// Logout Controller içinde
use SwallowPHP\Framework\Auth\Auth;

// ...

public function logout()
{
    Auth::logout();
    return Response::redirect('/');
}
```

### `Auth::isAuthenticated(): bool`

Mevcut istekte geçerli bir kullanıcı oturumu (çerez aracılığıyla) olup olmadığını kontrol eder. Çerezdeki bilgileri veritabanıyla karşılaştırarak oturumun geçerliliğini teyit eder.

-   **Dönüş Değeri:** Kullanıcı doğrulanmışsa `true`, değilse `false`.

**Örnek (Middleware içinde):**

```php
// AuthMiddleware içinde
use SwallowPHP\Framework\Auth\Auth;

// ...

public function handle(Request $request, Closure $next)
{
    if (!Auth::isAuthenticated()) {
        return Response::redirect('/login');
    }
    return $next($request);
}
```

### `Auth::user(): ?AuthenticatableModel`

Eğer kullanıcı doğrulanmışsa, kullanıcıyı temsil eden model nesnesini (`AuthenticatableModel`'i extend eden) döndürür. Doğrulanmamışsa `null` döndürür.

-   **Dönüş Değeri:** `AuthenticatableModel` nesnesi veya `null`.

**Örnek (View veya Controller içinde):**

```php
$user = Auth::user();

if ($user) {
    echo "Hoşgeldin, " . htmlspecialchars($user->email); // email özelliğine erişim (varsayım)
    echo "Kullanıcı ID: " . $user->getAuthIdentifier();
} else {
    echo "Giriş yapılmamış.";
}
```

### `Auth::isAdmin(): bool`

Mevcut kullanıcının "admin" rolüne sahip olup olmadığını kontrol eder. `Auth::user()` metodunu kullanır ve kullanıcının `role` özelliğine bakar.

-   **Dönüş Değeri:** Kullanıcı admin ise `true`, değilse `false`.

**Örnek (Middleware veya Controller içinde):**

```php
// AdminMiddleware içinde
use SwallowPHP\Framework\Auth\Auth;

// ...

public function handle(Request $request, Closure $next)
{
    if (!Auth::isAdmin()) {
        // Yetkisiz erişim
        throw new AuthorizationException('Admin access required.'); // Veya 403 sayfası göster
    }
    return $next($request);
}
```

## Authenticatable Model

Framework'ün `Auth` sistemi, kullanıcıları temsil eden modelin `SwallowPHP\Framework\Auth\AuthenticatableModel` abstract sınıfını genişletmesini bekler. Bu base class, kimlik doğrulama için gerekli temel metotları (`getAuthIdentifierName`, `getAuthIdentifier`, `getAuthPassword`) sağlar.

Uygulamanızdaki `User` modelini (genellikle `App\Models\User`) bu sınıfı genişleterek oluşturmanız gerekir:

```php
<?php

namespace App\Models;

use SwallowPHP\Framework\Auth\AuthenticatableModel;

class User extends AuthenticatableModel
{
    /**
     * The table associated with the model.
     */
    protected static string $table = 'users';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = [
        'name', // Eğer varsa
        'email',
        'password',
        'role',
    ];

    /**
     * The attributes that should be hidden for serialization.
     */
    protected array $hidden = [
        'password',
    ];

    /**
     * The attributes that should be cast.
     */
    protected array $casts = [
        'email_verified_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    // Eğer veritabanı kolonlarınız farklıysa (örn. 'id' yerine 'user_id', 'password' yerine 'secret'),
    // AuthenticatableModel'deki metotları override edebilirsiniz:
    // public function getAuthIdentifierName(): string { return 'user_id'; }
    // public function getAuthPassword(): string { return $this->secret; }
}
```

Ayrıca, `config/auth.php` dosyasında bu modelin tam sınıf adını belirttiğinizden emin olun:

```php
// config/auth.php
return [
    'model' => \App\Models\User::class,
    // ... diğer ayarlar
];
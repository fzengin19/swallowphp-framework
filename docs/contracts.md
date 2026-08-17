# Contracts

The `SwallowPHP\Framework\Contracts` namespace holds the framework's two extension-point interfaces. Both are deliberately small: they exist to let you swap a framework default for your own implementation without touching framework code.

The two contracts are:

- **`CacheInterface`** — the cache abstraction. Every cache driver (file, sqlite, array, etc.) implements this interface, and the `CacheInterface` alias is how `cache()` / `App::container()` returns them. It extends PSR-16 so you can hand any PSR-16 implementation straight to the framework.
- **`AuthenticatableInterface`** — the contract every "user-like" object must satisfy to be used with the framework's authentication layer. Whether your user lives in the database, a remote service, or an SSO token, it can be plugged into the framework just by implementing this interface.

This document is the implementation guide. For the operational docs (cache usage, authentication flow), see [`docs/cache.md`](cache.md) and [`docs/authentication.md`](authentication.md).

## Table of Contents

- [CacheInterface](#cacheinterface)
  - [Inheritance](#cacheinterface-inheritance)
  - [Methods](#cacheinterface-methods)
  - [Implementing CacheInterface](#implementing-cacheinterface)
- [AuthenticatableInterface](#authenticatableinterface)
  - [Methods](#authenticatableinterface-methods)
  - [Implementing AuthenticatableInterface](#implementing-authenticatableinterface)

---

## CacheInterface

**Namespace:** `SwallowPHP\Framework\Contracts\CacheInterface`

The cache abstraction. The framework registers this as a shared container service, so anywhere in your code you can resolve a cache driver via `App::container()->get(CacheInterface::class)` or the `cache()` helper.

### CacheInterface Inheritance

```php
namespace SwallowPHP\Framework\Contracts;

use Psr\SimpleCache\CacheInterface as Psr16CacheInterface;

interface CacheInterface extends Psr16CacheInterface
{
}
```

`CacheInterface` extends [`Psr\SimpleCache\CacheInterface`](https://www.php-fig.org/psr/psr-16/) (PSR-16) and adds no methods of its own. This means **any PSR-16 implementation is automatically a valid SwallowPHP cache driver**. You can drop in a PSR-16-compatible Symfony Cache adapter, a PSR-16 Redis adapter, or anything else without writing a wrapper.

### CacheInterface Methods

Because `CacheInterface` adds nothing to PSR-16, the methods are exactly the PSR-16 methods. Documented here for convenience:

| Method | Return | Description |
|--------|--------|-------------|
| `get(string $key, mixed $default = null): mixed` | `mixed` | Fetch a value; return `$default` if the key is missing. |
| `set(string $key, mixed $value, null\|int|\DateInterval $ttl = null): bool` | `bool` | Persist a value. `$ttl` is `null` for the default TTL, an int for seconds, or a `DateInterval`. |
| `delete(string $key): bool` | `bool` | Remove a single key. Returns `true` whether or not the key existed. |
| `clear(): bool` | `bool` | Wipe the entire cache. |
| `getMultiple(iterable $keys, mixed $default = null): iterable` | `iterable` | Fetch many keys at once. Missing keys get `$default`. |
| `setMultiple(iterable $values, null\|int|\DateInterval $ttl = null): bool` | `bool` | Persist many key/value pairs. |
| `deleteMultiple(iterable $keys): bool` | `bool` | Remove many keys. |
| `has(string $key): bool` | `bool` | Test for the existence of a key. |

The interface is plain — no `remember()`, no `increment()`, no tags. Higher-level helpers like `Cache::remember()` are built on top of these primitives in the cache layer.

### Implementing CacheInterface

The rule is simple: implement PSR-16. The example below wraps an in-memory `ArrayAdapter` (a tiny demo) and shows how the framework consumes it.

```php
<?php

namespace App\Cache;

use Psr\SimpleCache\CacheInterface;
use SwallowPHP\Framework\Contracts\CacheInterface as SwallowCacheInterface;

class ArrayCacheDriver implements SwallowCacheInterface
{
    /** @var array<string, mixed> */
    private array $store = [];

    /** @var array<string, int> Unix timestamps after which the key is expired. */
    private array $expiries = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (isset($this->expiries[$key]) && $this->expiries[$key] < time()) {
            unset($this->store[$key], $this->expiries[$key]);
            return $default;
        }

        return $this->store[$key] ?? $default;
    }

    public function set(string $key, mixed $value, null|int|\DateInterval $ttl = null): bool
    {
        $this->store[$key] = $value;

        if ($ttl === null) {
            unset($this->expiries[$key]);
        } elseif (is_int($ttl)) {
            $this->expiries[$key] = time() + $ttl;
        } elseif ($ttl instanceof \DateInterval) {
            $this->expiries[$key] = (new \DateTimeImmutable())->add($ttl)->getTimestamp();
        }

        return true;
    }

    public function delete(string $key): bool
    {
        unset($this->store[$key], $this->expiries[$key]);
        return true;
    }

    public function clear(): bool
    {
        $this->store = [];
        $this->expiries = [];
        return true;
    }

    public function getMultiple(iterable $keys, mixed $default = null): iterable
    {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $this->get($key, $default);
        }
        return $out;
    }

    public function setMultiple(iterable $values, null|int|\DateInterval $ttl = null): bool
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }
        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        foreach ($keys as $key) {
            $this->delete($key);
        }
        return true;
    }

    public function has(string $key): bool
    {
        return $this->get($key, '__SWALLOW_MISSING__') !== '__SWALLOW_MISSING__';
    }
}
```

Register it in a service provider so the container hands it out as the default `CacheInterface`:

```php
<?php

namespace App\Providers;

use App\Cache\ArrayCacheDriver;
use League\Container\ServiceProvider\AbstractServiceProvider;
use SwallowPHP\Framework\Contracts\CacheInterface;

class AppServiceProvider extends AbstractServiceProvider
{
    protected array $provides = [CacheInterface::class];

    public function register(): void
    {
        $this->container->addShared(CacheInterface::class, function () {
            return new ArrayCacheDriver();
        });
    }
}
```

Then in `public/index.php`:

```php
App::registerServiceProvider(new AppServiceProvider());
App::run();
```

Because `CacheInterface` extends PSR-16, the simplest "implement it" path is often to construct a PSR-16 implementation directly and return it from the service factory — no SwallowPHP-specific code required:

```php
$this->container->addShared(CacheInterface::class, function () {
    return new \Symfony\Component\Cache\Psr16\ArrayAdapter(); // any PSR-16
});
```

For the built-in `FileCacheDriver` and `SqliteCacheDriver`, see [`docs/cache.md`](cache.md).

---

## AuthenticatableInterface

**Namespace:** `SwallowPHP\Framework\Contracts\Auth\AuthenticatableInterface`

The contract every "user-like" object must satisfy to participate in the framework's authentication layer. The default `User` model implements it; if you want to use a different class as the authenticated principal (e.g. an `ApiToken` resolved from a remote API, an `SsoProfile` from an OIDC provider, or a custom `Employee` model with extra fields), you implement this interface.

The interface is small and intentionally not coupled to a database, a table name, or any column conventions. It only describes the six things the framework needs to know about *any* principal.

### AuthenticatableInterface Methods

| Method | Return | Description |
|--------|--------|-------------|
| `getAuthIdentifierName(): string` | `string` | The column / attribute name that uniquely identifies the user. Usually `'id'`. Used when the framework serializes the user into the session. |
| `getAuthIdentifier(): mixed` | `mixed` | The unique identifier itself (e.g. `1`, `'a1b2...'`, `42`). Stored in the session to rehydrate the user on the next request. |
| `getAuthPassword(): string` | `string` | The hashed password. The framework compares this against the user-supplied plaintext via `password_verify()` during login. |
| `getRememberToken(): ?string` | `string\|null` | The current "remember me" token, or `null`. Used to issue a long-lived cookie. |
| `setRememberToken(?string $value): void` | `void` | Persist a new remember-me token (or `null` to clear it). |
| `getRememberTokenName(): string` | `string` | The column / attribute name holding the remember token. Usually `'remember_token'`. |

### Implementing AuthenticatableInterface

The example below shows a custom user class that authenticates against a remote system. The `id`, `email`, etc. are fetched from an API; the framework only needs the six methods above, so the implementation is minimal.

```php
<?php

namespace App\Models;

use SwallowPHP\Framework\Contracts\Auth\AuthenticatableInterface;

class RemoteUser implements AuthenticatableInterface
{
    public function __construct(
        private ?int $id,
        private string $email,
        private string $passwordHash,
        private ?string $rememberToken = null,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }

    public function getRememberToken(): ?string
    {
        return $this->rememberToken;
    }

    public function setRememberToken(?string $value): void
    {
        $this->rememberToken = $value;
        // Persist via your remote API here, e.g.
        // $this->apiClient->updateUser($this->id, ['remember_token' => $value]);
    }

    public function getRememberTokenName(): string
    {
        return 'remember_token';
    }

    // --- Custom helpers specific to this user type ---

    public function email(): string
    {
        return $this->email;
    }
}
```

Wire the framework to use it. The default user resolver in the auth layer asks the container for a `User` model by class name; you can override this in a service provider by binding the `User::class` (or whatever your app's principal class is) to your `RemoteUser` factory:

```php
<?php

namespace App\Providers;

use App\Models\RemoteUser;
use League\Container\ServiceProvider\AbstractServiceProvider;

class AuthServiceProvider extends AbstractServiceProvider
{
    public function register(): void
    {
        // Example: substitute the default User model with a RemoteUser
        // resolved from an external service.
        $this->container->add(User::class, function () {
            $client = $this->container->get(\App\Services\IdentityClient::class);
            $data = $client->currentUser();

            return new RemoteUser(
                id: $data['id'],
                email: $data['email'],
                passwordHash: $data['password_hash'],
                rememberToken: $data['remember_token'] ?? null,
            );
        });
    }
}
```

Once the contract is satisfied, every framework feature that takes a "user" works unchanged: `Auth::user()`, `Auth::attempt()`, `Auth::login($user)`, `Auth::loginUsingId(123)`, the `auth.user` middleware, the `auth()` helper inside controllers, etc. The framework never inspects fields beyond the six listed above.

For the broader authentication flow (the `auth` config file, `Auth::attempt()`, password rehashing, the lockout layer), see [`docs/authentication.md`](authentication.md).

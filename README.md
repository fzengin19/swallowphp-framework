# SwallowPHP Framework

A lightweight, modern PHP framework designed for simplicity and rapid development. SwallowPHP provides essential features for building web applications without the complexity of larger frameworks.

[![License: MIT](https://img.shields.io/badge/License-MIT-green.svg)](https://opensource.org/licenses/MIT)
[![PHP Version](https://img.shields.io/badge/PHP-8.0%2B-blue.svg)](https://php.net)
[![Version](https://img.shields.io/badge/Version-1.0.0-orange.svg)](https://github.com/swallowphp/framework)

## Features

- **Dependency Injection** - Built on [League Container](https://container.thephpleague.com/) with auto-wiring support
- **Fluent Query Builder** - Intuitive database operations with PDO
- **Eloquent-style ORM** - Active Record pattern with relationships and events
- **Routing** - Simple, expressive route definitions with middleware support
- **Authentication** - Built-in authentication with remember me and brute-force protection
- **Session Management** - File-based sessions with flash messages
- **Caching** - PSR-16 compatible caching (file, SQLite drivers)
- **Logging** - PSR-3 compatible file-based logging
- **CSRF Protection** - Automatic cross-site request forgery protection
- **Rate Limiting** - Per-route request rate limiting

## Requirements

- PHP 8.0 or higher
- PDO extension (for database)
- JSON extension
- mbstring extension

## Installation

Install via Composer:

```bash
composer require swallowphp/framework
```

## Quick Start

### 1. Create Entry Point

Create a `public/index.php` file:

```php
<?php

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

// Load routes
require BASE_PATH . '/routes/web.php';

// Run the application
\SwallowPHP\Framework\Foundation\App::run();
```

### 2. Create Environment File

Create a `.env` file in your project root:

```env
APP_NAME=MyApp
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
APP_KEY=your-32-character-secret-key-here

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=myapp
DB_USERNAME=root
DB_PASSWORD=
```

### 3. Create Configuration

Create `config/app.php`:

```php
<?php

return [
    'name' => env('APP_NAME', 'SwallowPHP'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'locale' => 'en',
    'key' => env('APP_KEY'),
    'storage_path' => BASE_PATH . '/storage',
    'view_path' => BASE_PATH . '/resources/views',
    'controller_namespace' => '\\App\\Controllers',
];
```

### 4. Define Routes

Create `routes/web.php`:

```php
<?php

use SwallowPHP\Framework\Routing\Router;
use SwallowPHP\Framework\Http\Request;

// Basic route
Router::get('/', function () {
    return view('welcome');
})->name('home');

// Route with parameter
Router::get('/users/{id}', function (Request $request) {
    return 'User ID: ' . $request->get('id');
})->name('users.show');

// Controller route
Router::get('/posts', [App\Controllers\PostController::class, 'index'])
    ->name('posts.index');

Router::post('/posts', [App\Controllers\PostController::class, 'store'])
    ->name('posts.store');
```

### 5. Create a Controller

Create `app/Controllers/PostController.php`:

```php
<?php

namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;

class PostController
{
    public function index()
    {
        $posts = \App\Models\Post::orderBy('created_at', 'DESC')->get();
        return view('posts.index', ['posts' => $posts]);
    }

    public function store(Request $request)
    {
        \App\Models\Post::create([
            'title' => $request->get('title'),
            'content' => $request->get('content'),
        ]);
        
        return redirectToRoute('posts.index');
    }
}
```

### 6. Create a Model

Create `app/Models/Post.php`:

```php
<?php

namespace App\Models;

use SwallowPHP\Framework\Database\Model;

class Post extends Model
{
    protected static string $table = 'posts';
    
    protected array $fillable = ['title', 'content'];
}
```

### 7. Create a View

Create `resources/views/welcome.php`:

```php
<!DOCTYPE html>
<html>
<head>
    <title>Welcome</title>
</head>
<body>
    <h1>Welcome to SwallowPHP!</h1>
</body>
</html>
```

### 8. Run the Application

```bash
php -S localhost:8000 -t public
```

## Directory Structure

```
your-project/
├── app/
│   ├── Controllers/
│   └── Models/
├── config/
│   ├── app.php
│   ├── database.php
│   ├── auth.php
│   ├── session.php
│   └── cache.php
├── public/
│   └── index.php
├── resources/
│   └── views/
├── routes/
│   └── web.php
├── storage/
│   ├── cache/
│   ├── logs/
│   └── sessions/
├── .env
└── composer.json
```

## Documentation

Detailed documentation is available in the `docs/` directory:

- [Configuration](docs/configuration.md) - Environment and configuration setup
- [Routing](docs/routing.md) - Defining routes and middleware
- [Database](docs/database.md) - Query builder and ORM
- [Authentication](docs/authentication.md) - User authentication
- [Helpers](docs/helpers.md) - Global helper functions
- [HTTP](docs/http.md) - Request, Response, and Cookies
- [Views](docs/views.md) - View rendering and layouts
- [Sessions](docs/session.md) - Session management
- [Cache](docs/cache.md) - Caching system
- [Logging](docs/logging.md) - Application logging
- [Middleware](docs/middleware.md) - Custom middleware

## Dependencies

SwallowPHP uses the following third-party packages:

| Package | Version | Purpose |
|---------|---------|---------|
| [league/container](https://container.thephpleague.com/) | ^4.2 | Dependency Injection Container |
| [phpmailer/phpmailer](https://github.com/PHPMailer/PHPMailer) | ^6.9.1 | Email sending |
| [psr/simple-cache](https://www.php-fig.org/psr/psr-16/) | ^3.0 | Cache interface |
| [php-debugbar/php-debugbar](https://github.com/maximebf/php-debugbar) | ^1.23 | Debug toolbar (dev) |

## License

SwallowPHP is open-source software licensed under the [MIT license](LICENSE).

## Author

**Fatih Zengin** - [fatihzengin654@outlook.com](mailto:fatihzengin654@outlook.com)

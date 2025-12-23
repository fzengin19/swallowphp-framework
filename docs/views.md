# Views

SwallowPHP provides a simple, PHP-based templating system for rendering HTML pages.

## Table of Contents

- [Basic Usage](#basic-usage)
- [Passing Data](#passing-data)
- [Layouts](#layouts)
- [View Resolution](#view-resolution)
- [HTML Minification](#html-minification)

---

## Basic Usage

### Rendering Views

Use the `view()` helper function to render a view file:

```php
// Render a view
return view('welcome');

// With status code
return view('errors.404', [], null, 404);
```

### View File Location

Views are stored in `resources/views/` with `.php` extension:

```
resources/views/
├── welcome.php
├── errors/
│   └── 404.php
├── users/
│   ├── index.php
│   └── show.php
└── layouts/
    └── main.php
```

### Dot Notation

Use dots to navigate directory structure:

| View Name | File Path |
|-----------|-----------|
| `'welcome'` | `resources/views/welcome.php` |
| `'users.index'` | `resources/views/users/index.php` |
| `'admin.users.edit'` | `resources/views/admin/users/edit.php` |
| `'layouts.main'` | `resources/views/layouts/main.php` |

---

## Passing Data

### Basic Data Passing

Pass an associative array as the second argument:

```php
return view('users.show', [
    'user' => $user,
    'posts' => $posts,
]);
```

### Accessing Data in Views

Variables are extracted and available directly:

```php
<!-- resources/views/users/show.php -->
<h1><?= htmlspecialchars($user->name) ?></h1>

<ul>
    <?php foreach ($posts as $post): ?>
        <li><?= htmlspecialchars($post->title) ?></li>
    <?php endforeach ?>
</ul>
```

### Always Escape Output

Use `htmlspecialchars()` to prevent XSS:

```php
<!-- Safe -->
<p><?= htmlspecialchars($userInput) ?></p>

<!-- Dangerous - never do this with user input -->
<p><?= $userInput ?></p>
```

---

## Layouts

### Creating a Layout

Create a layout file with a `$slot` placeholder:

```php
<!-- resources/views/layouts/main.php -->
<!DOCTYPE html>
<html lang="<?= $locale ?? 'en' ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= csrf_token() ?>">
    <title><?= htmlspecialchars($title ?? 'My App') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
    <header>
        <nav>
            <a href="<?= route('home') ?>">Home</a>
            <a href="<?= route('about') ?>">About</a>
        </nav>
    </header>
    
    <main>
        <?= $slot ?>
    </main>
    
    <footer>
        <p>&copy; <?= date('Y') ?> My App</p>
    </footer>
    
    <script src="/js/app.js"></script>
</body>
</html>
```

### Using a Layout

Pass the layout name as the third argument:

```php
return view('pages.about', [
    'title' => 'About Us',
], 'layouts.main');
```

The view content replaces `$slot` in the layout:

```php
<!-- resources/views/pages/about.php -->
<h1>About Us</h1>
<p>Welcome to our company.</p>
```

### Data Sharing

Data passed to `view()` is available in both the view AND the layout:

```php
return view('users.profile', [
    'user' => $user,
    'title' => $user->name . ' - Profile',
], 'layouts.main');
```

Both `users/profile.php` and `layouts/main.php` can access `$user` and `$title`.

---

## View Resolution

### Resolution Order

The framework looks for views in this order:

1. **Application views:** `config('app.view_path')` (e.g., `resources/views/`)
2. **Framework views:** Framework's built-in views (fallback)

### Configuration

Set the view path in `config/app.php`:

```php
'view_path' => BASE_PATH . '/resources/views',
```

### View Not Found

If a view is not found, `ViewNotFoundException` is thrown (HTTP 500).

---

## HTML Minification

### Enabling Minification

Enable automatic HTML minification in `config/app.php`:

```php
'minify_html' => true,  // Enable in production
```

### What Gets Minified

- **HTML:** Removes comments, collapses whitespace between tags
- **Inline CSS (`<style>`):** Removes comments, collapses whitespace
- **Inline JS (`<script>`):** Removes full-line comments

### Preserved Content

These are NOT minified:

- `<pre>` tag contents
- `<textarea>` tag contents

---

## Common Patterns

### Partials

Create reusable partial views:

```php
<!-- resources/views/partials/alert.php -->
<?php if (isset($message)): ?>
    <div class="alert alert-<?= $type ?? 'info' ?>">
        <?= htmlspecialchars($message) ?>
    </div>
<?php endif ?>
```

Include partials using PHP's `include`:

```php
<!-- resources/views/users/create.php -->
<?php 
$message = session('success');
$type = 'success';
include __DIR__ . '/../partials/alert.php'; 
?>

<form>...</form>
```

### Navigation Active State

Use `isRoute()` helper for active navigation:

```php
<nav>
    <a href="<?= route('home') ?>" 
       class="<?= isRoute('home') ? 'active' : '' ?>">
        Home
    </a>
    <a href="<?= route('about') ?>"
       class="<?= isRoute('about') ? 'active' : '' ?>">
        About
    </a>
</nav>
```

### Flash Messages

Display session flash messages:

```php
<?php if (session('success')): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars(session('success')) ?>
    </div>
<?php endif ?>

<?php if (session('error')): ?>
    <div class="alert alert-danger">
        <?= htmlspecialchars(session('error')) ?>
    </div>
<?php endif ?>
```

### Forms with CSRF

Always include CSRF token in forms:

```php
<form method="POST" action="<?= route('posts.store') ?>">
    <?php csrf_field() ?>
    
    <input type="text" name="title" required>
    <textarea name="content"></textarea>
    <button type="submit">Create Post</button>
</form>
```

### Method Spoofing

For PUT, PATCH, DELETE requests:

```php
<form method="POST" action="<?= route('posts.update', ['id' => $post->id]) ?>">
    <?php csrf_field() ?>
    <?php method('PUT') ?>
    
    <input type="text" name="title" value="<?= htmlspecialchars($post->title) ?>">
    <button type="submit">Update</button>
</form>
```

---

## Complete Example

### Controller

```php
<?php

namespace App\Controllers;

use SwallowPHP\Framework\Http\Request;

class PostController
{
    public function index()
    {
        $posts = Post::orderBy('created_at', 'DESC')->get();
        
        return view('posts.index', [
            'title' => 'All Posts',
            'posts' => $posts,
        ], 'layouts.main');
    }
    
    public function show(Request $request)
    {
        $post = Post::find($request->get('id'));
        
        if (!$post) {
            return view('errors.404', ['title' => 'Not Found'], null, 404);
        }
        
        return view('posts.show', [
            'title' => $post->title,
            'post' => $post,
        ], 'layouts.main');
    }
}
```

### Index View

```php
<!-- resources/views/posts/index.php -->
<h1>All Posts</h1>

<?php if (session('success')): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars(session('success')) ?>
    </div>
<?php endif ?>

<?php if (empty($posts)): ?>
    <p>No posts found.</p>
<?php else: ?>
    <div class="posts">
        <?php foreach ($posts as $post): ?>
            <article class="post">
                <h2>
                    <a href="<?= route('posts.show', ['id' => $post->id]) ?>">
                        <?= htmlspecialchars($post->title) ?>
                    </a>
                </h2>
                <p><?= htmlspecialchars(shortenText($post->content, 150)) ?></p>
                <time><?= formatDateForHumans($post->created_at) ?></time>
            </article>
        <?php endforeach ?>
    </div>
<?php endif ?>
```

### Show View

```php
<!-- resources/views/posts/show.php -->
<article>
    <h1><?= htmlspecialchars($post->title) ?></h1>
    <time><?= formatDateForHumans($post->created_at) ?></time>
    
    <div class="content">
        <?= nl2br(htmlspecialchars($post->content)) ?>
    </div>
    
    <a href="<?= route('posts.index') ?>">&larr; Back to all posts</a>
</article>
```

---

## API Reference

### view() Function

```php
view(
    string $view,           // View name (dot notation)
    array $data = [],       // Data to pass
    ?string $layout = null, // Optional layout
    int $status = 200       // HTTP status code
): Response
```

**Parameters:**

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `$view` | string | - | View name using dot notation |
| `$data` | array | `[]` | Associative array of data |
| `$layout` | string\|null | `null` | Optional layout name |
| `$status` | int | `200` | HTTP response status code |

**Returns:** `Response` object

**Throws:** `ViewNotFoundException` if view or layout not found

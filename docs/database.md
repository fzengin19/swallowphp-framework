# Database

SwallowPHP provides a fluent Query Builder and an Eloquent-style ORM for database operations.

## Table of Contents

- [Configuration](#configuration)
- [Query Builder](#query-builder)
  - [Basic Queries](#basic-queries)
  - [Select Statements](#select-statements)
  - [Where Clauses](#where-clauses)
  - [Ordering, Limiting, Offset](#ordering-limiting-offset)
  - [Inserts](#inserts)
  - [Updates](#updates)
  - [Deletes](#deletes)
  - [Counting](#counting)
- [Model ORM](#model-orm)
  - [Defining Models](#defining-models)
  - [Retrieving Models](#retrieving-models)
  - [Creating Models](#creating-models)
  - [Updating Models](#updating-models)
  - [Deleting Models](#deleting-models)
  - [Attribute Casting](#attribute-casting)
- [Relationships](#relationships)
  - [Has Many](#has-many)
  - [Belongs To](#belongs-to)
- [Model Events](#model-events)
- [Pagination](#pagination)
  - [Basic Pagination](#basic-pagination)
  - [Cursor Pagination](#cursor-pagination)
  - [Paginator Methods](#paginator-methods)
  - [Rendering Links](#rendering-links)
  - [Custom Pagination Views](#custom-pagination-views)
  - [JSON Response](#json-response)

---

## Configuration

Configure your database in `config/database.php`:

```php
<?php

return [
    'default' => env('DB_CONNECTION', 'mysql'),
    
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'myapp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
        
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => 'database/database.sqlite', // Relative to storage_path
            'prefix' => '',
        ],
    ],
    
    // Query logging
    'log_queries' => false,
    'slow_threshold_ms' => 500,
    'log_bindings' => true,
];
```

### Supported Drivers

- **MySQL** (`mysql`)
- **SQLite** (`sqlite`)
- **PostgreSQL** (`pgsql`)

---

## Query Builder

### Basic Queries

Use the `db()` helper to access the query builder:

```php
// Get all rows from a table
$users = db()->table('users')->get();

// Get first result
$user = db()->table('users')->where('id', 1)->first();
```

### Select Statements

```php
// Select specific columns
$users = db()->table('users')
    ->select(['id', 'name', 'email'])
    ->get();

// Default selects all columns (*)
$users = db()->table('users')->get();
```

### Where Clauses

#### Basic Where

```php
// Two arguments (column, value) - uses = operator
$users = db()->table('users')->where('status', 'active')->get();

// Three arguments (column, operator, value)
$users = db()->table('users')->where('age', '>=', 18)->get();

// Multiple where conditions (AND)
$users = db()->table('users')
    ->where('status', 'active')
    ->where('role', 'admin')
    ->get();
```

#### Or Where

```php
$users = db()->table('users')
    ->where('role', 'admin')
    ->orWhere('role', 'moderator')
    ->get();

// With operator
$users = db()->table('users')
    ->where('status', 'active')
    ->orWhere('created_at', '>', '2024-01-01')
    ->get();
```

#### Where In

```php
$users = db()->table('users')
    ->whereIn('id', [1, 2, 3, 4, 5])
    ->get();

// Empty array returns no results (adds 1=0 condition)
$users = db()->table('users')->whereIn('id', [])->get(); // Returns []
```

#### Where Between

```php
$users = db()->table('users')
    ->whereBetween('age', 18, 65)
    ->get();

// Dates
$orders = db()->table('orders')
    ->whereBetween('created_at', '2024-01-01', '2024-12-31')
    ->get();
```

#### Where Raw

```php
// Raw SQL condition with bindings
$users = db()->table('users')
    ->whereRaw('YEAR(created_at) = ?', [2024])
    ->get();

// Multiple bindings
$orders = db()->table('orders')
    ->whereRaw('total > ? AND status = ?', [100, 'completed'])
    ->get();
```

#### Nested Where (Closure)

```php
// Group conditions with parentheses
$users = db()->table('users')
    ->where('status', 'active')
    ->where(function ($query) {
        $query->where('role', 'admin')
              ->orWhere('role', 'moderator');
    })
    ->get();

// SQL: WHERE status = 'active' AND (role = 'admin' OR role = 'moderator')
```

### Ordering, Limiting, Offset

```php
// Order by
$users = db()->table('users')
    ->orderBy('created_at', 'DESC')
    ->get();

// Multiple order by
$users = db()->table('users')
    ->orderBy('role', 'ASC')
    ->orderBy('name', 'ASC')
    ->get();

// Limit
$users = db()->table('users')->limit(10)->get();

// Offset (for pagination)
$users = db()->table('users')
    ->limit(10)
    ->offset(20)
    ->get();
```

### Inserts

```php
// Insert and get the insert ID
$id = db()->table('users')->insert([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT),
]);

if ($id !== false) {
    echo "User created with ID: {$id}";
}
```

### Updates

```php
// Update with where condition
$affectedRows = db()->table('users')
    ->where('id', 1)
    ->update([
        'name' => 'Jane Doe',
        'updated_at' => date('Y-m-d H:i:s'),
    ]);

echo "{$affectedRows} rows updated";
```

### Deletes

```php
// Delete with where condition
$affectedRows = db()->table('users')
    ->where('status', 'inactive')
    ->delete();

echo "{$affectedRows} rows deleted";
```

### Counting

```php
// Count all
$total = db()->table('users')->count();

// Count with conditions
$activeUsers = db()->table('users')
    ->where('status', 'active')
    ->count();
```

---

## Model ORM

### Defining Models

Create models by extending the `Model` class:

```php
<?php

namespace App\Models;

use SwallowPHP\Framework\Database\Model;

class User extends Model
{
    // Table name (auto-detected as 'users' if not specified)
    protected static string $table = 'users';
    
    // Mass assignable attributes
    protected array $fillable = ['name', 'email', 'password'];
    
    // Protected from mass assignment
    protected array $guarded = ['id'];
    
    // Hidden from toArray() and JSON
    protected array $hidden = ['password', 'remember_token'];
    
    // Attribute casting
    protected array $casts = [
        'id' => 'integer',
        'is_admin' => 'boolean',
        'settings' => 'array',
    ];
    
    // Date fields (auto-cast to DateTime)
    protected array $dates = ['created_at', 'updated_at', 'email_verified_at'];
}
```

#### Property Reference

| Property | Type | Description |
|----------|------|-------------|
| `$table` | string | Database table name |
| `$fillable` | array | Attributes that can be mass assigned |
| `$guarded` | array | Attributes protected from mass assignment |
| `$hidden` | array | Attributes hidden in `toArray()` |
| `$casts` | array | Attribute type casting rules |
| `$dates` | array | Date fields (default: `created_at`, `updated_at`) |

### Retrieving Models

```php
// Get all
$users = User::get();

// Get with conditions
$admins = User::where('role', 'admin')->get();

// Get first
$user = User::where('email', 'john@example.com')->first();

// Find by ID
$user = User::find(1);

// With ordering and limit
$latestUsers = User::orderBy('created_at', 'DESC')
    ->limit(5)
    ->get();
```

### Creating Models

#### Using `create()` Static Method

```php
// Create and save in one step
$user = User::create([
    'name' => 'John Doe',
    'email' => 'john@example.com',
    'password' => password_hash('secret', PASSWORD_DEFAULT),
]);

// Returns the created model instance or false
if ($user) {
    echo "Created user with ID: {$user->id}";
}
```

#### Using `save()` Instance Method

```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'john@example.com';
$user->password = password_hash('secret', PASSWORD_DEFAULT);
$insertId = $user->save();

if ($insertId !== false) {
    echo "User ID: {$user->id}";
}
```

**Note:** `created_at` and `updated_at` are automatically set.

### Updating Models

```php
// Find and update
$user = User::find(1);
$user->name = 'Jane Doe';
$user->save();

// Only dirty (changed) attributes are updated
$user = User::find(1);
$user->name = 'Jane Doe';  // Changed
$user->email = $user->email;  // Not changed, won't be in UPDATE query
$user->save();
```

**Note:** `updated_at` is automatically set on save.

### Deleting Models

```php
$user = User::find(1);
$deleted = $user->delete();

if ($deleted !== false) {
    echo "User deleted";
}
```

### Attribute Casting

Define casts in your model:

```php
protected array $casts = [
    'id' => 'integer',
    'price' => 'float',
    'is_active' => 'boolean',
    'options' => 'array',      // JSON string → PHP array
    'metadata' => 'object',    // JSON string → stdClass
    'published_at' => 'datetime',
];
```

#### Supported Cast Types

| Cast Type | PHP Type | Notes |
|-----------|----------|-------|
| `int`, `integer` | int | Numeric values |
| `real`, `float`, `double` | float | Numeric values |
| `string` | string | Any value |
| `bool`, `boolean` | bool | Handles "true"/"false" strings |
| `array` | array | JSON decode |
| `object` | stdClass | JSON decode |
| `date`, `datetime` | DateTime | String → DateTime object |

### Other Model Methods

```php
// Refresh from database
$user->refresh();

// Convert to array (respects $hidden)
$data = $user->toArray();

// Get changed attributes
$dirty = $user->getDirty();
```

---

## Relationships

### Has Many

Define a one-to-many relationship:

```php
// In Post model
class Post extends Model
{
    protected static string $table = 'posts';
    
    public function comments()
    {
        // Post has many Comments via 'post_id' foreign key
        return $this->hasMany(Comment::class, 'post_id', 'id');
    }
}
```

**Parameters:**
1. `$relatedModel` - Related model class
2. `$foreignKey` - Foreign key on the related table
3. `$localKey` - Local primary key (default: `'id'`)

**Usage:**

```php
$post = Post::find(1);

// Lazy load comments (executed when accessed)
foreach ($post->comments as $comment) {
    echo $comment->body;
}

// Chain additional queries
$recentComments = $post->comments()
    ->orderBy('created_at', 'DESC')
    ->limit(5)
    ->get();
```

### Belongs To

Define an inverse one-to-one or many-to-one relationship:

```php
// In Comment model
class Comment extends Model
{
    protected static string $table = 'comments';
    
    public function post()
    {
        // Comment belongs to a Post via 'post_id' foreign key
        return $this->belongsTo(Post::class, 'post_id', 'id');
    }
    
    public function author()
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }
}
```

**Parameters:**
1. `$relatedModel` - Related model class
2. `$foreignKey` - Foreign key on the current model's table
3. `$ownerKey` - Primary key on the related table (default: `'id'`)

**Usage:**

```php
$comment = Comment::find(1);

// Get the parent post
$post = $comment->post;

// Get the author
$author = $comment->author;
echo $author->name;
```

---

## Model Events

Register callbacks for model lifecycle events:

```php
// In your bootstrap or service provider
User::on('creating', function ($data) {
    // Called before insert
    // $data is the array being inserted
});

User::on('created', function ($model) {
    // Called after insert
    // $model is the created model instance
});

User::on('updating', function ($model) {
    // Called before update
});

User::on('updated', function ($model) {
    // Called after update
});

User::on('deleting', function ($model) {
    // Called before delete
});

User::on('deleted', function ($model) {
    // Called after delete
});

User::on('saving', function ($model) {
    // Called before insert OR update
});

User::on('saved', function ($model) {
    // Called after insert OR update
});
```

**Example: Auto-generate slug**

```php
Post::on('creating', function ($data) {
    if (empty($data['slug'])) {
        $data['slug'] = slug($data['title']);
    }
});
```

---

## Pagination

### Basic Pagination

```php
// Using Model
$users = User::paginate(15); // 15 items per page

// Current page is auto-detected from request query parameter 'page'
// Or specify page explicitly
$users = User::paginate(15, 2); // Page 2

// Using Query Builder
$posts = db()->table('posts')
    ->where('status', 'published')
    ->orderBy('created_at', 'DESC')
    ->paginate(10);
```

### Cursor Pagination

For large datasets, cursor pagination is more efficient:

```php
$users = User::cursorPaginate(20);

// With explicit cursor
$cursor = request()->query('cursor');
$users = User::cursorPaginate(20, $cursor);
```

### Paginator Methods

The `Paginator` object provides access to pagination data:

```php
$users = User::paginate(15);

// Get items for current page
$items = $users->items();

// Pagination info
$users->total();       // Total items across all pages
$users->perPage();     // Items per page (15)
$users->currentPage(); // Current page number
$users->lastPage();    // Last page number

// Navigation
$users->hasMorePages(); // true if more pages exist
$users->onFirstPage();  // true if on page 1
$users->isEmpty();      // true if no items
$users->isNotEmpty();   // true if has items

// URLs
$users->firstPageUrl();
$users->lastPageUrl();
$users->previousPageUrl(); // null on first page
$users->nextPageUrl();     // null on last page
$users->path();            // Base path without query string
```

### Iterating Over Results

`Paginator` implements `IteratorAggregate`, `ArrayAccess`, and `Countable`:

```php
// Loop directly
foreach ($users as $user) {
    echo $user->name;
}

// Array access
$firstUser = $users[0];

// Count items on current page
$count = count($users);
```

### Appending Query Parameters

Preserve existing query parameters in pagination links:

```php
$users = User::where('status', 'active')->paginate(15);

// Add parameters to all pagination URLs
$users->appends([
    'sort' => 'name',
    'direction' => 'asc',
]);

// Existing query params are automatically preserved
// Only 'page' parameter is excluded
```

### Rendering Links

```php
// In your view
<?= $users->links() ?>
```

This outputs Bootstrap-compatible HTML:

```html
<ul class="pagination">
    <li class="page-item disabled"><span class="page-link">&laquo; Previous</span></li>
    <li class="page-item active"><a class="page-link" href="?page=1">1</a></li>
    <li class="page-item"><a class="page-link" href="?page=2">2</a></li>
    <li class="page-item"><a class="page-link" href="?page=3">3</a></li>
    <li class="page-item"><a class="page-link" href="?page=2">Next &raquo;</a></li>
</ul>
```

### Custom Pagination Views

Configure a custom view in `config/app.php`:

```php
'pagination_view' => 'components.pagination',
```

Create the view file (`resources/views/components/pagination.php`):

```php
<?php if ($hasPages): ?>
<nav aria-label="Page navigation">
    <ul class="pagination justify-content-center">
        <?php foreach ($links as $link): ?>
            <li class="page-item <?= $link['active'] ? 'active' : '' ?> <?= ($link['disabled'] ?? false) ? 'disabled' : '' ?>">
                <?php if ($link['url'] && !($link['disabled'] ?? false)): ?>
                    <a class="page-link" href="<?= htmlspecialchars($link['url']) ?>">
                        <?= $link['label'] ?>
                    </a>
                <?php else: ?>
                    <span class="page-link"><?= $link['label'] ?></span>
                <?php endif ?>
            </li>
        <?php endforeach ?>
    </ul>
</nav>
<?php endif ?>
```

**Available variables in custom view:**

| Variable | Type | Description |
|----------|------|-------------|
| `$paginator` | Paginator | The paginator instance |
| `$links` | array | Structured array of link data |
| `$hasPages` | bool | True if total pages > 1 |
| `$onFirstPage` | bool | True if on page 1 |
| `$hasMorePages` | bool | True if not on last page |
| `$currentPage` | int | Current page number |
| `$lastPage` | int | Last page number |
| `$total` | int | Total items |
| `$perPage` | int | Items per page |
| `$previousPageUrl` | string\|null | Previous page URL |
| `$nextPageUrl` | string\|null | Next page URL |
| `$firstPageUrl` | string\|null | First page URL |
| `$lastPageUrl` | string\|null | Last page URL |

### JSON Response

`Paginator` implements `JsonSerializable` for API responses:

```php
// In controller
return Response::json($users);
```

Output:

```json
{
    "current_page": 1,
    "data": [...],
    "first_page_url": "http://localhost/users?page=1",
    "from": 1,
    "last_page": 5,
    "last_page_url": "http://localhost/users?page=5",
    "links": [...],
    "next_page_url": "http://localhost/users?page=2",
    "path": "http://localhost/users",
    "per_page": 15,
    "prev_page_url": null,
    "to": 15,
    "total": 75
}
```

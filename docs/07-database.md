# Veritabanı: Query Builder

SwallowPHP, veritabanı ile etkileşim kurmak için PDO üzerine kurulu, basit ve akıcı bir arayüz sunan bir Query Builder (`SwallowPHP\Framework\Database\Database`) içerir. Bu, SQL sorgularını programatik olarak oluşturmanıza ve çalıştırmanıza olanak tanır.

## Yapılandırma

Veritabanı bağlantı ayarları `config/database.php` dosyasında yapılır.

```php
// config/database.php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Varsayılan olarak kullanılacak veritabanı bağlantısının adı.
    | Aşağıdaki 'connections' dizisindeki anahtarlardan biri olmalıdır.
    |
    */
    'default' => env('DB_CONNECTION', 'mysql'), // Varsayılan bağlantı adı

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Desteklenen her veritabanı bağlantısı için ayarlar burada tanımlanır.
    | Şu anda öncelikli olarak MySQL desteklenmektedir.
    |
    */
    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'swallowphp'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'options' => [
                // İsteğe bağlı PDO bağlantı seçenekleri
                // PDO::ATTR_PERSISTENT => true,
            ],
        ],

        // Diğer bağlantı türleri (örn. sqlite, pgsql) eklenebilir
        // 'sqlite' => [
        //     'driver' => 'sqlite',
        //     'database' => env('DB_DATABASE', database_path('database.sqlite')),
        //     'prefix' => '',
        // ],
    ],
];
```

-   **`default`**: `db()` helper'ı veya `Database` sınıfı doğrudan örneklenirken kullanılacak varsayılan bağlantı adını belirtir.
-   **`connections`**: Her bağlantı için sürücü (`driver`), host, port, veritabanı adı, kullanıcı adı, şifre gibi ayarları içerir. Ayarlar öncelikle `.env` dosyasından okunur, bulunamazsa varsayılan değerler kullanılır.

## Query Builder Kullanımı

Query Builder örneğine genellikle `db()` helper fonksiyonu ile erişilir.

```php
// db() helper'ı ile Database örneğini al
$query = db();
```

### Temel Sorgular

**Tablo Seçme:**

```php
// 'users' tablosu üzerinde işlem yap
db()->table('users');
```

**Tüm Kayıtları Alma (`get`):**

```php
// 'users' tablosundaki tüm kayıtları al
$users = db()->table('users')->get();

foreach ($users as $user) {
    echo $user['email']; // Sütunlara dizi anahtarı ile erişim
}
```

**Belirli Sütunları Seçme (`select`):**

```php
// Sadece 'email' ve 'name' sütunlarını al
$users = db()->table('users')->select(['email', 'name'])->get();
```

**Tek Bir Kayıt Alma (`first`):**

```php
// ID'si 5 olan ilk kullanıcıyı al
$user = db()->table('users')->where('id', '=', 5)->first();

if ($user) {
    echo $user['email'];
}
```

### `WHERE` Koşulları

**Basit `WHERE`:**

```php
// Aktif olan kullanıcıları al
$activeUsers = db()->table('users')->where('status', '=', 'active')->get();

// ID'si 10'dan büyük olan kullanıcıları al
$users = db()->table('users')->where('id', '>', 10)->get();
```

**`OR WHERE`:**

```php
// Aktif olan VEYA oyları 100'den fazla olan kullanıcılar
$users = db()->table('users')
             ->where('status', '=', 'active')
             ->orWhere('votes', '>', 100)
             ->get();
```

**`WHERE IN`:**

```php
// ID'leri 1, 5 veya 10 olan kullanıcılar
$users = db()->table('users')->whereIn('id', [1, 5, 10])->get();
```

**`WHERE BETWEEN`:**

```php
// Oyları 50 ile 100 arasında olan kullanıcılar
$users = db()->table('users')->whereBetween('votes', 50, 100)->get();
```

**`WHERE RAW` (Ham SQL Koşulu):**

Dikkatli kullanılmalıdır, SQL injection riskine açıktır. Binding kullanın.

```php
// Ham SQL koşulu ile (binding kullanarak)
$users = db()->table('users')
             ->whereRaw('status = ? AND age > ?', ['active', 25])
             ->get();
```

### Sıralama, Limit ve Offset

**Sıralama (`orderBy`):**

```php
// Kullanıcıları isme göre artan sırada sırala
$users = db()->table('users')->orderBy('name', 'ASC')->get();

// Kullanıcıları oluşturulma tarihine göre azalan sırada sırala
$users = db()->table('users')->orderBy('created_at', 'DESC')->get();
```

**Limit ve Offset (`limit`, `offset`):**

```php
// İlk 10 kullanıcıyı al
$users = db()->table('users')->limit(10)->get();

// 11. kullanıcıdan başlayarak 5 kullanıcıyı al (sayfa 2, 5 öğe/sayfa)
$users = db()->table('users')->offset(10)->limit(5)->get();
```

### Ekleme (`insert`)

Yeni bir kayıt ekler ve eklenen kaydın ID'sini döndürür.

```php
$userId = db()->table('users')->insert([
    'email' => 'test@example.com',
    'name' => 'Test User',
    'password' => password_hash('secret', PASSWORD_DEFAULT), // Şifreyi hash'lemeyi unutmayın!
    'status' => 'active'
]);

echo "Yeni kullanıcı ID: " . $userId;
```

### Güncelleme (`update`)

Belirtilen koşullara uyan kayıtları günceller ve etkilenen satır sayısını döndürür.

```php
// ID'si 5 olan kullanıcının durumunu 'inactive' yap
$affectedRows = db()->table('users')
                    ->where('id', '=', 5)
                    ->update(['status' => 'inactive']);

echo "Etkilenen satır sayısı: " . $affectedRows;
```

### Silme (`delete`)

Belirtilen koşullara uyan kayıtları siler ve etkilenen satır sayısını döndürür.

```php
// Durumu 'inactive' olan kullanıcıları sil
$affectedRows = db()->table('users')
                    ->where('status', '=', 'inactive')
                    ->delete();

echo "Silinen satır sayısı: " . $affectedRows;
```

### Sayma (`count`)

Belirtilen koşullara uyan kayıt sayısını döndürür.

```php
// Aktif kullanıcı sayısı
$activeUserCount = db()->table('users')
                       ->where('status', '=', 'active')
                       ->count();

echo "Aktif kullanıcılar: " . $activeUserCount;
```

### Sayfalama (`paginate`, `cursorPaginate`)

**Basit Sayfalama (`paginate`):**

Belirli bir sayfadaki kayıtları ve sayfalama bilgilerini (toplam kayıt, sayfa sayısı, önceki/sonraki sayfa URL'leri) döndürür.

```php
// Sayfa başına 15 kullanıcı, 2. sayfayı göster
$paginatedUsers = db()->table('users')
                      ->orderBy('created_at', 'DESC')
                      ->paginate(15, request()->getQuery('page', 1)); // İstekten sayfa numarasını al

// Verileri kullan
foreach ($paginatedUsers['data'] as $user) {
    echo $user['email'] . '<br>';
}

// Sayfalama linklerini göster (örnek)
echo "Toplam: " . $paginatedUsers['total'] . "<br>";
echo "Sayfa: " . $paginatedUsers['current_page'] . " / " . $paginatedUsers['last_page'] . "<br>";
if ($paginatedUsers['prev_page_url']) {
    echo '<a href="' . $paginatedUsers['prev_page_url'] . '">Önceki</a> ';
}
if ($paginatedUsers['next_page_url']) {
    echo '<a href="' . $paginatedUsers['next_page_url'] . '">Sonraki</a>';
}
// Veya daha gelişmiş linkler için $paginatedUsers['pagination_links'] kullanılabilir
```

**Cursor Sayfalama (`cursorPaginate`):**

`OFFSET` kullanmadan, bir önceki sayfadaki son elemanın ID'sine göre sayfalama yapar. Büyük veri setlerinde daha performanslı olabilir.

```php
// Sayfa başına 15 kullanıcı
$cursor = request()->getQuery('cursor'); // İstekten cursor'ı al
$paginatedUsers = db()->table('users')
                      ->orderBy('id', 'ASC') // Cursor için sıralama gerekli
                      ->cursorPaginate(15, $cursor);

// Verileri kullan
foreach ($paginatedUsers['data'] as $user) {
    echo $user['email'] . '<br>';
}

// Sonraki sayfa linki
if ($paginatedUsers['next_page_url']) {
    echo '<a href="' . $paginatedUsers['next_page_url'] . '">Daha Fazla Yükle</a>';
}
```

**Not:** Query Builder durumu (`where`, `orderBy` vb.) `get`, `first`, `insert`, `update`, `delete`, `count` gibi bir sonlandırma metodu çağrıldıktan sonra otomatik olarak sıfırlanmaz. Aynı builder örneği üzerinde farklı sorgular çalıştırmadan önce `reset()` metodunu çağırmanız veya yeni bir `db()` örneği almanız önerilir.
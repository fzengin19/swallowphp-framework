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


## Temel ORM: Model Sınıfı

Query Builder'a ek olarak, SwallowPHP veritabanı tablolarınızla etkileşim kurmak için basit bir ORM (Object-Relational Mapping) sağlayan `SwallowPHP\Framework\Database\Model` abstract sınıfını sunar. Her veritabanı tablosu, bu `Model` sınıfını genişleten bir "Model" sınıfına karşılık gelir. Modeller, ilgili tablo için sorgu yapmanıza ve tablo içindeki kayıtları temsil eden nesnelerle çalışmanıza olanak tanır.

### Model Tanımlama

Bir model oluşturmak için `SwallowPHP\Framework\Database\Model` sınıfını genişletin. En azından, modelin hangi veritabanı tablosuna karşılık geldiğini belirtmek için `protected static string $table` özelliğini tanımlamanız gerekir.

```php
<?php

namespace App\Models; // Modellerinizi genellikle App\Models altında tutun

use SwallowPHP\Framework\Database\Model;

class Post extends Model
{
    /**
     * Modele karşılık gelen veritabanı tablosu.
     */
    protected static string $table = 'posts';

    /**
     * Toplu atama (mass assignment) ile doldurulabilen alanlar.
     * create() veya fill() metotlarında kullanılır.
     */
    protected array $fillable = [
        'user_id',
        'title',
        'slug',
        'content',
        'published_at',
    ];

    /**
     * JSON'a veya diziye dönüştürülürken gizlenecek alanlar.
     */
    protected array $hidden = [
        'password', // Eğer post modelinde olsaydı
    ];

    /**
     * Otomatik olarak tip dönüşümü yapılacak alanlar.
     * Desteklenen tipler: int, integer, real, float, double, string, bool, boolean, array, object, date, datetime
     */
    protected array $casts = [
        'user_id' => 'int',
        'published_at' => 'datetime', // Otomatik olarak DateTime nesnesine dönüştürülür
    ];

    /**
     * Otomatik olarak DateTime nesnesine dönüştürülecek tarih alanları.
     * $casts içinde 'datetime' veya 'date' belirtilmişse buraya eklemeye gerek yoktur.
     * created_at ve updated_at varsayılan olarak buradadır.
     */
    // protected array $dates = ['published_at'];

    // İlişkiler (aşağıda açıklanacak)
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
```

### Kayıtları Alma

Model sınıfı üzerinden Query Builder metotlarını statik olarak çağırabilirsiniz. Bu metotlar, sorguyu modelin tablosu üzerinde çalıştırır ve sonuçları model nesneleri olarak döndürür.

**Tüm Kayıtları Alma:**

```php
use App\Models\Post;

$posts = Post::get(); // Post model nesnelerinden oluşan bir dizi döndürür

foreach ($posts as $post) {
    echo $post->title; // Nesne özelliği olarak erişim
}
```

**Koşullu Sorgular:**

Query Builder'daki `where`, `orderBy`, `limit` gibi metotlar statik olarak kullanılabilir.

```php
// Belirli bir kullanıcıya ait postları al
$userPosts = Post::where('user_id', '=', 5)->orderBy('created_at', 'DESC')->get();

// İlk yayınlanmış postu al
$firstPublishedPost = Post::where('published_at', '<=', date('Y-m-d H:i:s'))
                          ->orderBy('published_at', 'ASC')
                          ->first();

if ($firstPublishedPost) {
    echo $firstPublishedPost->title;
    // Tarih alanına DateTime nesnesi olarak erişim (casting sayesinde)
    echo $firstPublishedPost->published_at->format('d.m.Y');
}
```

**ID ile Kayıt Bulma (`find` - Henüz implemente edilmedi, `where` kullanılabilir):**

```php
// ID'si 10 olan postu bul
$post = Post::where('id', '=', 10)->first();
```

### Kayıt Oluşturma (`create`)

`create` metodu, `$fillable` olarak tanımlanmış alanları kullanarak yeni bir kayıt oluşturur ve kaydedilmiş model nesnesini döndürür. `created_at` ve `updated_at` otomatik olarak ayarlanır.

```php
$newPost = Post::create([
    'user_id' => 1,
    'title' => 'Yeni Blog Yazısı',
    'slug' => 'yeni-blog-yazisi',
    'content' => 'Bu yazının içeriği...',
    'published_at' => date('Y-m-d H:i:s'),
]);

echo "Oluşturulan Post ID: " . $newPost->id;
```

### Kayıt Güncelleme (`save`)

Mevcut bir model nesnesinin özelliklerini değiştirip `save()` metodunu çağırarak kaydı güncelleyebilirsiniz. `updated_at` otomatik olarak güncellenir.

```php
$post = Post::where('id', '=', 10)->first();

if ($post) {
    $post->title = 'Güncellenmiş Başlık';
    $post->content = 'Güncellenmiş içerik.';
    $affectedRows = $post->save(); // Değişiklikleri kaydet

    echo "Etkilenen satır: " . $affectedRows;
}
```

**Toplu Güncelleme (`update` - Model üzerinden):**

Belirli koşullara uyan birden fazla kaydı tek seferde güncellemek için Query Builder'ı kullanın:

```php
$affectedRows = Post::where('user_id', '=', 5)
                    ->update(['status' => 'archived']); // Modelin query builder'ını kullan
```

### Kayıt Silme (`delete` - Model üzerinden)

Mevcut bir model nesnesini silmek için `delete()` metodunu çağırabilirsiniz (Bu metot henüz `Model.php`'de implemente edilmemiş, Query Builder kullanılmalı).

```php
// $post = Post::where('id', '=', 10)->first();
// if ($post) {
//     $post->delete(); // Henüz yok
// }

// Query Builder ile silme:
$affectedRows = Post::where('id', '=', 10)->delete(); // Modelin query builder'ını kullan
```

### Toplu Atama (`Mass Assignment`)

`create` veya `fill` metotlarını kullanırken, sadece `$fillable` dizisinde belirtilen alanlar toplu olarak atanabilir. Bu, istenmeyen alanların (örn. `is_admin`) form girdileriyle değiştirilmesini önleyen bir güvenlik önlemidir.

Alternatif olarak, `$fillable` yerine `$guarded` özelliğini kullanabilirsiniz. `$guarded` dizisi, toplu atamaya *izin verilmeyen* alanları belirtir. `$guarded = ['*']` tüm alanları korur, `$guarded = []` ise tüm alanlara izin verir (dikkatli kullanılmalıdır). `$fillable` tanımlıysa `$guarded` göz ardı edilir.

### Özellik Dönüşümleri (`Casting`)

`$casts` dizisi, model özelliklerinin belirli tiplere otomatik olarak dönüştürülmesini sağlar. Örneğin, veritabanındaki `published_at` (DATETIME) alanı, modele erişildiğinde otomatik olarak bir `DateTime` nesnesine dönüştürülür. Dizi veya JSON olarak saklanan veriler için `array` veya `object` cast'leri kullanılabilir.

### Özellikleri Gizleme (`Hidden`)

`$hidden` dizisi, model bir diziye veya JSON'a dönüştürüldüğünde (`toArray()` veya `json_encode()` ile) sonuçtan çıkarılacak alanları belirtir. Genellikle şifre gibi hassas alanları gizlemek için kullanılır.

### İlişkiler

Modeller arasında ilişkiler tanımlayabilirsiniz.

**One-to-Many (`hasMany`):**

Bir modelin birden fazla ilişkili modele sahip olduğu durumlar (örn. bir kullanıcının birden fazla postu olması).

```php
// User.php modelinde
public function posts()
{
    // Post modelindeki 'user_id' kolonu ile User modelindeki 'id' kolonunu eşleştirir
    return $this->hasMany(Post::class, 'user_id', 'id');
}

// Kullanım:
$user = User::where('id', '=', 1)->first();
$userPosts = $user->posts(); // Kullanıcının postlarını içeren bir dizi döndürür
```

**Belongs To (`belongsTo`):**

Bir modelin başka bir modele "ait olduğu" durumlar (örn. bir postun bir kullanıcıya ait olması).

```php
// Post.php modelinde
public function user()
{
    // Post modelindeki 'user_id' kolonu ile User modelindeki 'id' kolonunu eşleştirir
    return $this->belongsTo(User::class, 'user_id', 'id');
}

// Kullanım:
$post = Post::where('id', '=', 10)->first();
$author = $post->user(); // Postun yazarını (User nesnesi) döndürür
if ($author) {
    echo $author->name;
}
```

### Olaylar (`Events`)

Model yaşam döngüsü olaylarını (creating, created, updating, updated, saving, saved, deleting, deleted) dinleyebilirsiniz.

```php
use App\Models\Post;

// Bir Service Provider veya başlangıç dosyasında:
Post::on('creating', function($postData) {
    // Yeni post oluşturulmadan hemen önce çalışır
    // $postData henüz kaydedilmemiş veriyi içerir (dizi)
    if (empty($postData['slug'])) {
        // Slug boşsa otomatik oluştur (örnek)
        // Bu event içinde $postData'yı değiştirmek doğrudan etkilemez,
        // create metoduna gönderilen veriyi önceden ayarlamak daha iyi olabilir.
    }
});

Post::on('created', function($postModel) {
    // Yeni post veritabanına kaydedildikten sonra çalışır
    // $postModel kaydedilmiş Post nesnesidir
    // Log::info("Yeni post oluşturuldu: ID " . $postModel->id);
});

Post::on('updating', function($postModel) {
    // Mevcut post güncellenmeden hemen önce çalışır
    // $postModel güncellenecek Post nesnesidir
});

Post::on('updated', function($postModel) {
    // Mevcut post güncellendikten sonra çalışır
});

// Diğer olaylar: saving, saved, deleting, deleted
```

# Framework Refactoring Checklist

Bu liste, `result.md` analiz raporunda belirtilen ve kod değişikliği gerektiren iyileştirmeleri içerir.

- [✅] **src/Methods.php:** `printVariable` fonksiyonunu kaldır.
- [✅] **src/Methods.php:** `removeDuplicates` fonksiyonunu gözden geçir/kaldır (şimdilik kaldıralım).
- [✅] **src/Methods.php:** `webpImage` fonksiyonundaki `@` hata bastırmalarını kaldırıp loglama ekle.
- [✅] **src/Methods.php:** `cache()` helper'ına belirli sürücüleri getirme yeteneği ekle.
- [ ] **src/Database/Model.php:** Eksik statik `find($id)` metodunu implemente et.
- [ ] **src/Http/Request.php:** `createFromGlobals` metoduna temel `$_FILES` yönetimi ekle.
- [ ] **src/Http/Request.php:** `parseHeadersFromServer` metoduna Bearer token ayrıştırması ekle.
- [ ] **src/Foundation/ExceptionHandler.php:** `getStatusCode` metodunda `AuthorizationException` için durum kodunu 401 olarak ayarla.
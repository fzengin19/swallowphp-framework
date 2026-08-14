<?php

/*
|--------------------------------------------------------------------------
| Phase 4 Section 2 hardening tests (AC-55 .. AC-57).
|--------------------------------------------------------------------------
|
| These tests bind the Database/Model correctness bugfixes from
| SwallowPHP Phase 4 Section 2. Each AC's "Test" sketch in the SPEC is
| bound here as a behavioral check that exercises the real production
| path — not just source-presence grepping.
|
| Fixture layout:
|   - tests/Feature/Phase4Section2Test.php (this file — the only NEW
|     file under tests/Feature)
|   - tests/Support/Phase4Section2TestHelpers.php — extracts
|     overrideRequestSingleton() so this file can be selected standalone
|     (without it, Pest only loads DocsConsistencyTest.php when its
|     discovery walk hits that file; selecting only this file would
|     fail with `Call to undefined function
|     Tests\Feature\overrideRequestSingleton()`). The helper uses a
|     `function_exists` guard so the FULL suite (which discovers
|     DocsConsistencyTest.php first) keeps DocsConsistencyTest's copy
|     as the live declaration.
|   - DB fixtures per test under sys_get_temp_dir()/phase4-section2-* (see
|     PHASE4_S2_TMP_PREFIX below; .scratch/ is read-only in some sandboxes
|     so we follow Phase4Section1Test.php and put sqlite files in tmp).
|     Nothing is written into .scratch/ by this build — the previous
|     version created an unused .scratch/phase4-section2 directory that
|     was never cleaned up.
|   - Model fixtures declared at file scope (Phase4S2Post, Phase4S2Author)
|     so Pest's autoloader picks them up.
|   - phase4s2BuildRequest() helper declared in this file (NOT reusing
|     Phase3HardeningTest's phase3BuildRequest — that helper does NOT
|     populate HTTP_HOST, so Request::getHost() falls back to '' and
|     fullUrl() yields a malformed `http:///...` URL that's unusable for
|     an exact-string assertion in AC-57).
*/

namespace Tests\Feature;

// Ensure the request-singleton override helper is available when this
// test file is loaded standalone. We load ONLY the helper extracted to
// tests/Support/Phase4Section2TestHelpers.php — NOT the full
// DocsConsistencyTest.php.
//
// Loading the full DocsConsistencyTest.php would be hook pollution of the
// same shape Section 1's `Phase3TestHelpers.php` was extracted to fix:
// pulling in a sibling test file as a side effect of running only one
// test directory (extra ~100 tests in the run, and unrelated failures
// bleeding into the Section 2 verdict). Without this load, selecting only
// `tests/Feature/Phase4Section2Test.php` produced 11 failures with
// `Call to undefined function Tests\Feature\overrideRequestSingleton()`,
// because DocsConsistencyTest.php is the file that declares it and Pest
// only loads it when test discovery walks it.
//
// The helper file uses a `function_exists` guard so the declaration is
// compatible with the identical body in DocsConsistencyTest.php: when the
// FULL suite runs (Pest discovers DocsConsistencyTest.php first),
// the helper file is a no-op load and the function is the one declared
// by DocsConsistencyTest. When this file is loaded alone, the helper
// file declares the function itself.
require_once __DIR__ . '/../Support/Phase4Section2TestHelpers.php';

use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Database\Relation;
use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Request;

// ---------------------------------------------------------------------------
// Model fixtures. Plain Model subclasses (NOT AuthenticatableModel — none
// of these ACs touch auth). $table is required for Model::query() to resolve
// the builder; $fillable is set so the guarded check in fill()/__set() does
// not silently drop our test inputs.
// ---------------------------------------------------------------------------

class Phase4S2Post extends Model
{
    protected static string $table = 'phase4s2_posts';
    protected array $fillable = ['title', 'author_id'];

    public function author(): Relation
    {
        return $this->belongsTo(Phase4S2Author::class, 'author_id');
    }
}

class Phase4S2Author extends Model
{
    protected static string $table = 'phase4s2_authors';
    protected array $fillable = ['name'];
}

// ---------------------------------------------------------------------------
// Scratch helpers — sqlite files live under sys_get_temp_dir() so the test
// stays writable in sandboxes where .scratch/ is read-only. No fixture
// file is written into the repo's .scratch/ tree by this build — the
// per-test beforeEach creates a fresh sqlite file via PDO and points
// config('database.connections.sqlite.database') at it under tmp. The
// afterEach below unlinks anything matching the per-process prefix.
// ---------------------------------------------------------------------------

if (!defined('PHASE4_S2_TMP_PREFIX')) {
    // Per-process prefix so all sqlite fixtures from this run share a
    // discoverable root under sys_get_temp_dir(), making cleanup easier.
    define('PHASE4_S2_TMP_PREFIX', 'phase4-section2-' . uniqid('', true) . '-');
}

/**
 * Build a fresh sqlite file path under sys_get_temp_dir() with a
 * guaranteed-unique suffix. Caller is responsible for unlink() in
 * afterEach() if it wants cleanup; the file-level afterEach below handles
 * it for every test in this file.
 */
function phase4s2TmpFile(string $suffix): string
{
    return sys_get_temp_dir() . '/' . PHASE4_S2_TMP_PREFIX . $suffix;
}

/**
 * Construct a Request via reflection with a deterministic host + URI,
 * so AC-57's exact-string `previousPageUrl()` assertion can compare
 * against a known `http://example.test/phase4s2-posts` value without
 * depending on `$_SERVER` state. The 8-arg constructor shape matches
 * `phase3BuildRequest()` / `buildTestRequest()` (uri, method, query,
 * request/body, files, headers, server, rawInput).
 *
 * Reused (not redeclared) `overrideRequestSingleton()` from
 * `DocsConsistencyTest.php` to swap this instance into the App container.
 */
function phase4s2BuildRequest(string $uri, string $host = 'example.test'): Request
{
    $server = ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_HOST' => $host];
    $rc = new ReflectionClass(Request::class);
    $instance = $rc->newInstanceWithoutConstructor();
    $rc->getConstructor()->invokeArgs($instance, [$uri, 'GET', [], [], [], [], $server, '']);
    return $instance;
}

// ---------------------------------------------------------------------------
// File-level beforeEach: mirror Phase4Section1Test's shared setup, PLUS
// reset Database::$connections so each test's fresh sqlite file gets a
// fresh connection (NOT a cached one pointing at a now-unlinked inode).
// ---------------------------------------------------------------------------

beforeEach(function () {
    App::container();
    config(['app.storage_path' => sys_get_temp_dir()]);
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => 'phase4s2-default.sqlite',
        'prefix' => '',
    ]]);

    // Drop any prior cached PDO connection — the DSN+user-keyed static
    // cache would otherwise reuse a handle pointing at an unlinked inode.
    (new ReflectionProperty(Database::class, 'connections'))->setValue(null, []);

    // Reset Model's static event-callback registry so listeners from one
    // test cannot bleed into the next.
    (new ReflectionProperty(Model::class, 'eventCallbacks'))->setValue(null, []);

    // AC-57 cleanup: re-prime the Request singleton with a neutral request
    // so a previous test's custom host/uri doesn't leak. (Also called from
    // afterEach for AC-57's own teardown — but belt-and-suspenders here so
    // a test that crashed mid-run still leaves a sane default for the next.)
    overrideRequestSingleton(phase4s2BuildRequest('/', 'localhost'));
});

afterEach(function () {
    // Best-effort cleanup of sqlite fixtures created under sys_get_temp_dir().
    $prefix = sys_get_temp_dir() . '/' . PHASE4_S2_TMP_PREFIX;
    foreach (glob($prefix . '*') ?: [] as $f) {
        @unlink($f);
    }
    // WAL/SHM/journal sidecars may sit next to the main file.
    foreach (glob($prefix . '*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }

    // AC-57 cleanup: `overrideRequestSingleton()` mutates the container's
    // resolved Request definition for the rest of the process. Reset it to
    // a neutral localhost request so this test's `example.test` host doesn't
    // leak into later tests in this file or into DocsConsistencyTest.php's
    // own Request-dependent tests.
    overrideRequestSingleton(phase4s2BuildRequest('/', 'localhost'));
});

/* ===========================================================================
 * AC-55 — Database::delete() returns int|false (false on PDOException)
 * =========================================================================== */

describe('AC-55 — Database::delete() distinguishes "0 rows matched" from "PDOException"', function () {

    beforeEach(function () {
        // Fresh sqlite file per test → every test starts with an empty DB.
        $this->dbPath = phase4s2TmpFile('ac55-' . uniqid('', true) . '.sqlite');
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE phase4s2_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => basename($this->dbPath),
            'prefix' => '',
        ]]);
        // Re-point storage to sys_get_temp_dir() so Database's sqlite branch
        // resolves dbPath = storage_path + database.
        config(['app.storage_path' => sys_get_temp_dir()]);

        // Drop cached connection again so the per-test sqlite file gets a
        // fresh PDO connection (not the default's).
        (new ReflectionProperty(Database::class, 'connections'))->setValue(null, []);
    });

    it('AC-55.1: delete() of a matching row returns int > 0', function () {
        $post = Phase4S2Post::create(['title' => 'Hello']);
        expect($post)->not->toBeFalse();
        $id = $post->id;
        expect($id)->toBeGreaterThan(0);

        $deleted = Phase4S2Post::query()->where('id', '=', $id)->delete();
        expect($deleted)->toBeInt();
        expect($deleted)->toBeGreaterThan(0);
    });

    it('AC-55.2: delete() with a where() matching zero rows returns int 0 (NOT false)', function () {
        Phase4S2Post::create(['title' => 'Hello']);

        $deleted = Phase4S2Post::query()->where('id', '=', 99999)->delete();
        // 0 must stay an int and stay strictly equal to 0 — this is the
        // "no rows matched" leg of the int|false union that callers
        // (notably Model::delete()) use to tell a real failure from a
        // legitimate empty-result delete.
        expect($deleted)->toBe(0);
        expect($deleted)->not->toBeFalse();

        // And confirm the row that DID exist is still there.
        expect(Phase4S2Post::query()->count())->toBe(1);
    });

    it('AC-55.3: delete() on a non-existent column returns false (a real PDOException at execute())', function () {
        Phase4S2Post::create(['title' => 'Hello']);

        // `nonexistent_column` is NOT in the fixture table, so sqlite raises
        // `SQLSTATE[HY000]: no such column` at $statement->execute() time
        // (NOT at $this->wrapColumn() time — wrapColumn only validates
        // identifier SYNTAX, not that the column actually exists). The
        // delete() catch (PDOException) block must convert that into
        // `false`, not `0`.
        $deleted = Phase4S2Post::query()->where('nonexistent_column', '=', 1)->delete();
        expect($deleted)->toBeFalse();
        expect($deleted)->not->toBe(0);

        // And confirm the existing row was untouched (PDOException must NOT
        // have partially executed).
        expect(Phase4S2Post::query()->count())->toBe(1);
    });

    it('AC-55.4: int|false return type signature is correct (model::delete() path)', function () {
        // The int|false return type on Database::delete() is what lets
        // Model::delete()'s `if ($result !== false && $result > 0)` branch
        // reach the deleted-event fire path. Confirm the signature is
        // exactly int|false (not just int) via Reflection so a future
        // refactor that drops the false leg is caught here.
        $rm = new ReflectionMethod(Database::class, 'delete');
        $rt = $rm->getReturnType();
        expect($rt)->not->toBeNull();
        // PHP 8's ReflectionUnionType reports getName() === 'int|false'
        // (the literal union-type syntax). Accept either that or a
        // ReflectionNamedType naming just `int` — we want the union.
        $typeName = (string) $rt;
        expect($typeName)->toBe('int|false');
    });
});

/* ===========================================================================
 * AC-56 — Model::belongsTo() short-circuits a null FK with `whereRaw('1 = 0')`
 * =========================================================================== */

describe('AC-56 — Model::belongsTo() short-circuits a null FK (mirrors hasMany())', function () {

    beforeEach(function () {
        // Fresh sqlite file per test → every test starts with an empty DB.
        $this->dbPath = phase4s2TmpFile('ac56-' . uniqid('', true) . '.sqlite');
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE phase4s2_authors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT,
                created_at TEXT,
                updated_at TEXT
            )'
        );
        $pdo->exec(
            'CREATE TABLE phase4s2_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                author_id INTEGER,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => basename($this->dbPath),
            'prefix' => '',
        ]]);
        config(['app.storage_path' => sys_get_temp_dir()]);
        (new ReflectionProperty(Database::class, 'connections'))->setValue(null, []);
    });

    it('AC-56.1: functional non-regression — $postWithAuthor->author()->first() returns the matching author', function () {
        $author = Phase4S2Author::create(['name' => 'Ada']);
        expect($author)->not->toBeFalse();

        $postWithAuthor = Phase4S2Post::create(['title' => 'Post A', 'author_id' => $author->id]);
        expect($postWithAuthor)->not->toBeFalse();

        $fetched = $postWithAuthor->author()->first();
        expect($fetched)->toBeInstanceOf(Phase4S2Author::class);
        expect($fetched->id)->toBe($author->id);
    });

    it('AC-56.2: null FK — $postWithoutAuthor->author()->first() returns null (same functional result as pre-fix)', function () {
        $postWithoutAuthor = Phase4S2Post::create(['title' => 'Post B', 'author_id' => null]);
        expect($postWithoutAuthor)->not->toBeFalse();

        $fetched = $postWithoutAuthor->author()->first();
        expect($fetched)->toBeNull();
    });

    it('AC-56.3: wiring proof — null FK relation carries whereRaw("1 = 0") in $where, NOT a "Null"-typed where()', function () {
        $postWithoutAuthor = Phase4S2Post::create(['title' => 'Post B', 'author_id' => null]);
        expect($postWithoutAuthor)->not->toBeFalse();

        $relation = $postWithoutAuthor->author();
        // Reach the underlying Database builder via Relation::$query (the
        // property name confirmed by src/Database/Relation.php).
        $rp = new ReflectionProperty(Relation::class, 'query');
        $rp->setAccessible(true);
        $builder = $rp->getValue($relation);
        expect($builder)->toBeInstanceOf(Database::class);

        // Reflect into Database::$where — Database::whereRaw() stores INTO
        // this same $where array, tagged ['type' => 'Raw', 'sql' => ..., ...].
        // (There is a separate, currently-unused $whereRaw property — we
        // intentionally ignore it.)
        $wp = new ReflectionProperty(Database::class, 'where');
        $wp->setAccessible(true);
        $whereEntries = $wp->getValue($builder);

        expect($whereEntries)->toHaveCount(1);
        expect($whereEntries[0]['type'] ?? null)->toBe('Raw');
        expect($whereEntries[0]['sql'] ?? null)->toBe('1 = 0');
    });

    it('AC-56.4: sanity — non-null FK relation produces a Basic where() entry (not Raw)', function () {
        // Belt-and-suspenders: the fix should ONLY change the null-FK path.
        // Confirm the non-null path still builds the ordinary
        // where($ownerKey, '=', $foreignValue) Basic entry (the shape that
        // pre-fix `where($ownerKey, '=', null)` would have produced if
        // $foreignValue had been null — proving test 3 above really did
        // exercise the changed branch, not some pre-existing Raw path).
        $author = Phase4S2Author::create(['name' => 'Ada']);
        $postWithAuthor = Phase4S2Post::create(['title' => 'Post A', 'author_id' => $author->id]);

        $relation = $postWithAuthor->author();
        $rp = new ReflectionProperty(Relation::class, 'query');
        $rp->setAccessible(true);
        $builder = $rp->getValue($relation);

        $wp = new ReflectionProperty(Database::class, 'where');
        $wp->setAccessible(true);
        $whereEntries = $wp->getValue($builder);

        expect($whereEntries)->toHaveCount(1);
        expect($whereEntries[0]['type'] ?? null)->toBe('Basic');
        expect($whereEntries[0]['column'] ?? null)->toBe('id');
        expect($whereEntries[0]['value'] ?? null)->toBe($author->id);
    });
});

/* ===========================================================================
 * AC-57 — Database::cursorPaginate() prev_page_url is the base URL (no ?cursor=)
 * =========================================================================== */

describe('AC-57 — Database::cursorPaginate() prev_page_url is the base URL', function () {

    beforeEach(function () {
        $this->dbPath = phase4s2TmpFile('ac57-' . uniqid('', true) . '.sqlite');
        $pdo = new \PDO('sqlite:' . $this->dbPath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec(
            'CREATE TABLE phase4s2_posts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                title TEXT,
                author_id INTEGER,
                created_at TEXT,
                updated_at TEXT
            )'
        );

        config(['database.default' => 'sqlite']);
        config(['database.connections.sqlite' => [
            'driver' => 'sqlite',
            'database' => basename($this->dbPath),
            'prefix' => '',
        ]]);
        config(['app.storage_path' => sys_get_temp_dir()]);
        (new ReflectionProperty(Database::class, 'connections'))->setValue(null, []);
    });

    it('AC-57.1: previousPageUrl() on the first page is null', function () {
        // Deterministic Request so fullUrl() == 'http://example.test/phase4s2-posts'.
        overrideRequestSingleton(phase4s2BuildRequest('/phase4s2-posts'));

        Phase4S2Post::create(['title' => 'Post 1']);
        Phase4S2Post::create(['title' => 'Post 2']);

        $page1 = Phase4S2Post::query()->cursorPaginate(1);
        expect($page1->previousPageUrl())->toBeNull();
    });

    it('AC-57.2: previousPageUrl() on a cursor\'d page is exactly $baseUrl (no dangling ?cursor=)', function () {
        overrideRequestSingleton(phase4s2BuildRequest('/phase4s2-posts'));
        $expectedBaseUrl = 'http://example.test/phase4s2-posts';

        Phase4S2Post::create(['title' => 'Post 1']);
        Phase4S2Post::create(['title' => 'Post 2']);

        // Page 1: perPage=1 → page 1 contains Post 1 (id=1) and
        // hasMorePages=true (Post 2 was fetched as the +1 lookahead).
        $page1 = Phase4S2Post::query()->cursorPaginate(1);
        expect($page1->previousPageUrl())->toBeNull();

        // Extract the cursor from page 1's next_page_url.
        $nextUrl = $page1->nextPageUrl();
        expect($nextUrl)->not->toBeNull();
        parse_str(parse_url($nextUrl, PHP_URL_QUERY) ?? '', $parsedQuery);
        $cursor = $parsedQuery['cursor'];
        expect($cursor)->not->toBeEmpty();

        // Page 2 with that cursor → previousPageUrl() must be the un-cursored
        // base URL, exactly (NOT `http://example.test/phase4s2-posts?cursor=`
        // with a dangling empty value).
        $page2 = Phase4S2Post::query()->cursorPaginate(1, $cursor);
        expect($page2->previousPageUrl())->toBe($expectedBaseUrl);
    });

    it('AC-57.3: nextPageUrl() on a cursor\'d page is unaffected (still the broken-link-fix\'s positive twin)', function () {
        // Sanity: the AC-57 fix is scoped to prev_page_url only. Confirm
        // next_page_url still includes the actual next-cursor value (not
        // a dangling `?cursor=`), so a future regression that drops the
        // `urlencode($nextCursor)` suffix is caught here too.
        overrideRequestSingleton(phase4s2BuildRequest('/phase4s2-posts'));

        Phase4S2Post::create(['title' => 'Post 1']);
        Phase4S2Post::create(['title' => 'Post 2']);
        Phase4S2Post::create(['title' => 'Post 3']);

        $page1 = Phase4S2Post::query()->cursorPaginate(1);
        $nextUrl = $page1->nextPageUrl();
        expect($nextUrl)->not->toBeNull();
        // The next cursor value MUST be present (not a dangling `?cursor=`).
        parse_str(parse_url($nextUrl, PHP_URL_QUERY) ?? '', $parsedQuery);
        expect($parsedQuery['cursor'] ?? null)->not->toBeNull();
        expect($parsedQuery['cursor'] ?? '')->not->toBe('');
    });
});

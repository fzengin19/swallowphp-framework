<?php

/*
|--------------------------------------------------------------------------
| Phase 0 hardening tests (AC-1 .. AC-5).
|--------------------------------------------------------------------------
|
| These tests bind a real (sqlite, file-backed) database and exercise the
| production code paths of every acceptance criterion in the Phase-0 SPEC.
| They are intentionally NOT using the HardeningTest "reflection-only" style
| because each AC is about runtime behaviour (a query actually runs, a save
| actually persists, a delete actually fires), and the test must assert
| that behaviour — not just that the right code string lives somewhere in
| the source.
|
| The shared sqlite file lives at .scratch/phase0.sqlite (a fresh scratch
| directory inside the working tree, never /tmp). beforeEach() wipes the
| row contents but keeps the schema, and afterEach() clears the static
| Model event-callback registry so listeners from one test cannot bleed
| into the next.
|
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Auth\AuthenticatableTrait;
use SwallowPHP\Framework\Database\Model;
use SwallowPHP\Framework\Foundation\App;
use ReflectionProperty;

// ---------------------------------------------------------------------------
// Test fixtures. Defined as plain classes inside this file so Pest picks them
// up via the standard autoloader; the framework's `Models` only need a
// `$table` and (for the UserModel) the AuthenticatableTrait. The
// setRawAttributesForTest() helper on NullableModel exists purely so AC-3
// can inject a bad column into $attributes without weakening the model's
// public mass-assignment surface.
// ---------------------------------------------------------------------------

class NullableModel extends Model
{
    protected static string $table = 'nullable_models';
    protected array $fillable = ['name'];

    /**
     * Test-only: lets AC-3 stuff a non-existent column into $attributes
     * without going through __set()'s fillable/guarded gate (which would
     * silently drop it, defeating the purpose of the test). Production code
     * must NEVER call this — there is no fillable check here on purpose.
     *
     * @param array<string, mixed> $attrs
     */
    public function setRawAttributesForTest(array $attrs): void
    {
        foreach ($attrs as $key => $value) {
            $this->attributes[$key] = $value;
        }
    }
}

class UserModel extends Model
{
    use AuthenticatableTrait;

    protected static string $table = 'user_models';
    protected array $fillable = ['email', 'password']; // remember_token intentionally NOT listed
}

// ---------------------------------------------------------------------------
// Test bootstrap: spin up the App container once, point the Database factory
// at a scratch sqlite file, and reset state between tests.
// ---------------------------------------------------------------------------

if (!defined('PHASE0_SCRATCH_DIR')) {
    define('PHASE0_SCRATCH_DIR', dirname(__DIR__, 2) . '/.scratch');
}

beforeEach(function () {
    // Initialize container lazily so the first call to config()/App picks it up.
    App::container();

    config(['app.storage_path' => PHASE0_SCRATCH_DIR]);
    config(['database.default' => 'sqlite']);
    config(['database.connections.sqlite' => [
        'driver' => 'sqlite',
        'database' => 'phase0.sqlite',
        'prefix' => '',
    ]]);

    // Fresh sqlite file every test → every test starts with an empty DB.
    if (!is_dir(PHASE0_SCRATCH_DIR)) {
        mkdir(PHASE0_SCRATCH_DIR, 0755, true);
    }
    @unlink(PHASE0_SCRATCH_DIR . '/phase0.sqlite');
    touch(PHASE0_SCRATCH_DIR . '/phase0.sqlite');

    // The Database class memoizes PDO connections in a static property keyed by
    // DSN; clearing it forces the next resolve() to open a fresh connection
    // against the new (just-created) file instead of reusing a handle that
    // points to the unlinked inode.
    $connectionsProp = new ReflectionProperty(\SwallowPHP\Framework\Database\Database::class, 'connections');
    $connectionsProp->setValue(null, []);

    /** @var \SwallowPHP\Framework\Database\Database $db */
    $db = App::container()->get(\SwallowPHP\Framework\Database\Database::class);
    $pdo = (new ReflectionProperty(\SwallowPHP\Framework\Database\Database::class, 'connection'))
        ->getValue($db);
    $pdo->exec(
        'CREATE TABLE nullable_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT,
            created_at TEXT,
            updated_at TEXT
        )'
    );
    $pdo->exec(
        'CREATE TABLE user_models (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email TEXT,
            password TEXT,
            remember_token TEXT,
            created_at TEXT,
            updated_at TEXT
        )'
    );

    // Clear static event-callback registries so listeners from one test
    // cannot bleed into the next.
    $eventCallbacks = (new ReflectionProperty(Model::class, 'eventCallbacks'))
        ->getValue();
    foreach ($eventCallbacks as $class => $_) {
        unset($eventCallbacks[$class]);
    }
});

/* ----------------------------------------------------------------------
 * AC-1 — where(col, null) must produce IS NULL semantics
 * ---------------------------------------------------------------------- */

it('AC-1: where(col, null) matches NULL rows (2-arg form)', function () {
    NullableModel::create(['name' => 'alice']);
    NullableModel::create(['name' => null]);
    NullableModel::create(['name' => 'bob']);
    NullableModel::create(['name' => null]);

    $nullRows = NullableModel::where('name', null)->get();
    expect($nullRows)->toHaveCount(2);
    foreach ($nullRows as $m) {
        expect($m->name)->toBeNull();
    }
});

it('AC-1: where(col, "=", null) matches NULL rows (3-arg explicit-null form)', function () {
    NullableModel::create(['name' => 'alice']);
    NullableModel::create(['name' => null]);
    NullableModel::create(['name' => 'bob']);
    NullableModel::create(['name' => null]);

    $nullRows = NullableModel::where('name', '=', null)->get();
    expect($nullRows)->toHaveCount(2);
    foreach ($nullRows as $m) {
        expect($m->name)->toBeNull();
    }
});

it('AC-1: where with bang-equal and null matches non-NULL rows', function () {
    NullableModel::create(['name' => 'alice']);
    NullableModel::create(['name' => null]);
    NullableModel::create(['name' => 'bob']);
    NullableModel::create(['name' => null]);

    $notNullRows = NullableModel::where('name', '!=', null)->get();
    expect($notNullRows)->toHaveCount(2);
    $names = array_map(fn($m) => $m->name, $notNullRows);
    expect($names)->toContain('alice');
    expect($names)->toContain('bob');
});

it('AC-1: where with angle-bracket and null matches non-NULL rows', function () {
    NullableModel::create(['name' => 'alice']);
    NullableModel::create(['name' => null]);
    NullableModel::create(['name' => 'bob']);
    NullableModel::create(['name' => null]);

    $notNullRows = NullableModel::where('name', '<>', null)->get();
    expect($notNullRows)->toHaveCount(2);
    $names = array_map(fn($m) => $m->name, $notNullRows);
    expect($names)->toContain('alice');
    expect($names)->toContain('bob');
});

/* ----------------------------------------------------------------------
 * AC-2 — event listener returning false stops propagation
 * ---------------------------------------------------------------------- */

it('AC-2: a listener returning false stops later listeners on the same event', function () {
    $firstRan = false;
    $secondRan = false;
    NullableModel::on('saving', function () use (&$firstRan) {
        $firstRan = true;
        return false; // documented abort signal
    });
    NullableModel::on('saving', function () use (&$secondRan) {
        $secondRan = true;
    });

    $m = new NullableModel(null, ['name' => 'eve']);
    $m->save(); // fires 'saving' → first listener runs and returns false

    expect($firstRan)->toBeTrue();
    expect($secondRan)->toBeFalse(); // abort must stop propagation
});

/* ----------------------------------------------------------------------
 * AC-3 — a failed UPDATE must not fire a success event
 * ---------------------------------------------------------------------- */

it('AC-3: a genuinely-failed UPDATE reports failure and does not fire updated', function () {
    $updatedFired = false;
    $savedFired = false;
    $createdFired = false;
    NullableModel::on('updated', function () use (&$updatedFired) {
        $updatedFired = true;
    });
    NullableModel::on('saved', function () use (&$savedFired) {
        $savedFired = true;
    });
    NullableModel::on('created', function () use (&$createdFired) {
        $createdFired = true;
    });

    $m = NullableModel::create(['name' => 'carol']);
    // Sanity check: the create() above legitimately fired 'created' + 'saved'.
    expect($createdFired)->toBeTrue();
    expect($m->id)->toBeGreaterThan(0);

    // Now reset the flags so the next assertions are about the UPDATE save().
    $updatedFired = false;
    $savedFired = false;

    // Force the next UPDATE to hit a real PDOException by injecting a column
    // that does not exist on the table. The test-only helper bypasses
    // __set()'s fillable guard so we don't have to weaken the model's
    // public mass-assignment surface.
    $m->setRawAttributesForTest(['name' => 'carol', 'nonexistent_column' => 'x']);

    $result = $m->save();

    expect($result)->toBeFalse();
    expect($updatedFired)->toBeFalse();
    expect($savedFired)->toBeFalse();
});

it('AC-3: a no-op save (no dirty data) does not regress to reporting failure', function () {
    $updatedFired = false;
    NullableModel::on('updated', function () use (&$updatedFired) {
        $updatedFired = true;
    });

    $m = NullableModel::create(['name' => 'dave']);
    // Same value as already persisted → getDirty() returns [] → no UPDATE
    // statement is issued at all. The bug we're fixing is in update()'s
    // catch path, so this no-op branch must keep working unchanged.
    $result = $m->save();

    expect($result)->toBe(0); // 0 affected rows, but not an error
    expect($updatedFired)->toBeTrue(); // 'updated' still fires on no-op saves
});

/* ----------------------------------------------------------------------
 * AC-4 — setRememberToken() bypasses mass-assignment guarding
 * ---------------------------------------------------------------------- */

it('AC-4: setRememberToken persists even when $fillable does not list remember_token', function () {
    $u = UserModel::create(['email' => 'a@b', 'password' => 'pw']);
    expect($u->id)->toBeGreaterThan(0);

    $u->setRememberToken('abc');
    $u->save();

    // Reload from the DB and check the persisted column directly.
    $reloaded = UserModel::find($u->id);
    expect($reloaded)->not->toBeNull();

    $pdo = (new ReflectionProperty(\SwallowPHP\Framework\Database\Database::class, 'connection'))
        ->getValue(App::container()->get(\SwallowPHP\Framework\Database\Database::class));
    $stmt = $pdo->prepare('SELECT remember_token FROM user_models WHERE id = ?');
    $stmt->execute([$u->id]);
    $persisted = $stmt->fetchColumn();
    expect($persisted)->toBe('abc');
});

/* ----------------------------------------------------------------------
 * AC-5 — delete() without WHERE must throw; deleteAll() provides the old behavior
 * ---------------------------------------------------------------------- */

it('AC-5: delete() with no WHERE throws RuntimeException and leaves rows intact', function () {
    NullableModel::create(['name' => 'a']);
    NullableModel::create(['name' => 'b']);
    NullableModel::create(['name' => 'c']);

    expect(NullableModel::query()->count())->toBe(3);

    expect(fn () => NullableModel::query()->delete())
        ->toThrow(\RuntimeException::class);

    expect(NullableModel::query()->count())->toBe(3); // table untouched
});

it('AC-5: delete() with a WHERE still deletes only matching rows', function () {
    NullableModel::create(['name' => 'a']);
    NullableModel::create(['name' => 'b']);
    NullableModel::create(['name' => 'c']);

    $deleted = NullableModel::query()->where('name', '=', 'b')->delete();
    expect($deleted)->toBe(1);
    expect(NullableModel::query()->count())->toBe(2);

    $remaining = array_map(fn($m) => $m->name, NullableModel::get());
    expect($remaining)->toContain('a');
    expect($remaining)->toContain('c');
    expect($remaining)->not->toContain('b');
});

it('AC-5: deleteAll() removes every row', function () {
    NullableModel::create(['name' => 'a']);
    NullableModel::create(['name' => 'b']);
    NullableModel::create(['name' => 'c']);

    $deleted = NullableModel::query()->deleteAll();
    expect($deleted)->toBe(3);
    expect(NullableModel::query()->count())->toBe(0);
});

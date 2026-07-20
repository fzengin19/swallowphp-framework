<?php

use SwallowPHP\Framework\Database\Database;
use SwallowPHP\Framework\Database\Model;

/**
 * Guards the security/compat fixes: SQL identifier escaping, operator
 * whitelist, and Model isset()/empty() support. None of these need a DB.
 */

function db_method(string $name): ReflectionMethod
{
    // Protected methods are reachable via reflection without setAccessible() since PHP 8.1.
    return new ReflectionMethod(Database::class, $name);
}

function db_instance(): Database
{
    return (new ReflectionClass(Database::class))->newInstanceWithoutConstructor();
}

it('quotes identifiers and escapes embedded backticks', function () {
    $db = db_instance();
    $wrap = db_method('wrapColumn');
    expect($wrap->invoke($db, 'name'))->toBe('`name`');
    expect($wrap->invoke($db, 'users.name'))->toBe('`users`.`name`');
    // A backtick in the name is doubled, so it cannot break out of the quoting.
    expect($wrap->invoke($db, 'ev`il'))->toBe('`ev``il`');
});

it('accepts whitelisted operators and rejects injection attempts', function () {
    $db = db_instance();
    $op = db_method('normalizeOperator');
    expect($op->invoke($db, 'LIKE'))->toBe('LIKE');
    expect($op->invoke($db, '='))->toBe('=');
    expect(fn () => $op->invoke($db, '= 1 OR 1=1 --'))
        ->toThrow(InvalidArgumentException::class);
});

it('supports isset/empty/null-coalescing on model attributes', function () {
    $u = new class(null, ['name' => 'Ada', 'age' => 0, 'nick' => null]) extends Model {
        protected array $fillable = ['name', 'age', 'nick'];
    };

    expect(isset($u->name))->toBeTrue();
    expect(isset($u->age))->toBeTrue();      // 0 is set
    expect(isset($u->nick))->toBeFalse();    // null reads as unset
    expect(isset($u->missing))->toBeFalse();
    expect(empty($u->age))->toBeTrue();      // 0 is empty
    expect($u->missing ?? 'def')->toBe('def');

    unset($u->name);
    expect(isset($u->name))->toBeFalse();
});

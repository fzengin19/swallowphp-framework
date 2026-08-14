<?php

/*
|--------------------------------------------------------------------------
| Test support: phase3BuildRequest() helper extracted.
|--------------------------------------------------------------------------
|
| This file holds only the body-carrying request helper that was
| originally declared in `tests/Feature/Phase3HardeningTest.php`. Loading
| this helper directly (instead of `require_once`ing the whole
| Phase3HardeningTest.php file) avoids the AC-40–AC-46 test suite running
| as a side effect of running only Phase4Section1Test — the previous
| `require_once` pulled in BOTH the helper AND all its sibling tests
| (hook pollution + extra 21 spurious tests when only Phase 4 is
| selected).
|
| The function name and namespace are intentionally kept identical to the
| Phase3HardeningTest.php version (`Tests\Feature\phase3BuildRequest`) so
| consumer code that calls it directly (AC-49 in Phase4Section1Test,
| which lives in `Tests\Feature`) doesn't need to branch. The
| `function_exists` guard inside the if-block keeps the two declarations
| compatible: the FULL suite reaches Phase3HardeningTest.php first
| (alphabetical Pest discovery) and declares the function
| unconditionally; the helper file then no-ops on subsequent loads
| (function_exists check passes). When ONLY Phase4Section1Test is loaded
| (so Phase3HardeningTest.php is never reached), the helper file
| declares the function itself.
|
| Pure extraction — implementation body is identical to
| Phase3HardeningTest.php's copy.
|
| IMPORTANT: this file does NOT match the *Test.php suffix and is not
| under `tests/Feature/`, so Pest's test discovery does not pick it up
| as a test file (phpunit.xml's `<directory suffix="Test.php">./tests
| </directory>` rule). It is loaded only via explicit `require_once`
| from test files that need it.
|
| NAMESPACE NOTE: this file declares `namespace Tests\Feature;` (NOT
| `Tests\Support`) so the function lands in the same namespace as
| Phase3HardeningTest.php's declaration and Phase4Section1Test.php's
| calls. The file's directory under `tests/Support/` is purely a
| discovery-suppression trick — the namespace is the actual binding
| contract.
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Http\Request;
use ReflectionClass;

if (!function_exists('Tests\Feature\phase3BuildRequest')) {
    /**
     * Construct a Request via reflection. The constructor is protected,
     * so we use ReflectionClass::newInstanceWithoutConstructor() to build
     * an empty Request and then invoke the real constructor with our
     * args. Mirrors the original phase3BuildRequest() in
     * Phase3HardeningTest.php.
     */
    function phase3BuildRequest(string $uri, string $method, array $query = [], array $body = []): Request
    {
        $server = ['REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri];
        $rc = new ReflectionClass(Request::class);
        $instance = $rc->newInstanceWithoutConstructor();
        $ctor = $rc->getConstructor();
        $ctor->invokeArgs($instance, [
            $uri,
            $method,
            $query,
            $body,
            [], // files
            [], // headers
            $server,
            '', // rawInput
        ]);
        return $instance;
    }
}

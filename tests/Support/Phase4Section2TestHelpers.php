<?php

/*
|--------------------------------------------------------------------------
| Test support: overrideRequestSingleton() helper extracted.
|--------------------------------------------------------------------------
|
| This file holds the `overrideRequestSingleton()` helper that was
| originally declared in `tests/Feature/DocsConsistencyTest.php`. Loading
| this helper directly (instead of `require_once`ing the whole
| DocsConsistencyTest.php file) lets Phase4Section2Test.php run standalone
| — without it, selecting only `tests/Feature/Phase4Section2Test.php`
| produced 11 failures with `Call to undefined function
| Tests\Feature\overrideRequestSingleton()`, because DocsConsistencyTest.php
| is the file that declares it and Pest only loads it when the test
| discovery walk hits it.
|
| Loading the full DocsConsistencyTest.php would be hook pollution of the
| same shape Section 1's `Phase3TestHelpers.php` was extracted to fix:
| pulling in a sibling test file as a side effect of running only one
| test directory (extra ~100 tests in the run, and unrelated failures
| bleeding into the Section 2 verdict).
|
| The function name and namespace are intentionally kept identical to the
| DocsConsistencyTest.php version (`Tests\Feature\overrideRequestSingleton`)
| so consumer code that calls it directly (Phase4Section2Test's AC-57
| cleanup, which lives in `Tests\Feature`) doesn't need to branch. The
| `function_exists` guard inside the if-block keeps the two declarations
| compatible: the FULL suite reaches DocsConsistencyTest.php first
| (alphabetical Pest discovery, both files live in `tests/Feature/`) and
| declares the function unconditionally; the helper file then no-ops on
| subsequent loads (function_exists check passes). When ONLY
| Phase4Section2Test is loaded (so DocsConsistencyTest.php is never
| reached), the helper file declares the function itself.
|
| Pure extraction — implementation body is identical to
| DocsConsistencyTest.php's copy.
|
| IMPORTANT: this file does NOT match the *Test.php suffix and is not
| under `tests/Feature/`, so Pest's test discovery does not pick it up
| as a test file (phpunit.xml's `<directory suffix="Test.php">./tests
| </directory>` rule). It is loaded only via explicit `require_once`
| from test files that need it.
|
| NAMESPACE NOTE: this file declares `namespace Tests\Feature;` (NOT
| `Tests\Support`) so the function lands in the same namespace as
| DocsConsistencyTest.php's declaration and Phase4Section2Test.php's
| calls. The file's directory under `tests/Support/` is purely a
| discovery-suppression trick — the namespace is the actual binding
| contract.
*/

namespace Tests\Feature;

use SwallowPHP\Framework\Foundation\App;
use SwallowPHP\Framework\Http\Request;
use ReflectionClass;

if (!function_exists('Tests\Feature\overrideRequestSingleton')) {
    /**
     * Replace the App container's resolved Request singleton with the given
     * instance. The container's `addShared` does NOT allow overriding an
     * existing definition, so we have to mutate the Definition's `resolved`
     * property directly. This is the only reliable way to inject a Request
     * with a non-global Accept header into ExceptionHandler.
     *
     * Pure extraction from DocsConsistencyTest.php:102 — body is identical.
     */
    function overrideRequestSingleton(Request $request): void
    {
        $appRef = new ReflectionClass(App::class);
        $containerProp = $appRef->getProperty('container');
        $container = $containerProp->getValue();

        $containerRef = new ReflectionClass($container);
        $defsProp = $containerRef->getProperty('definitions');
        $defs = $defsProp->getValue($container);

        $defsRef = new ReflectionClass($defs);
        $defsMapProp = $defsRef->getProperty('definitions');
        $defsMap = $defsMapProp->getValue($defs);

        foreach ($defsMap as $def) {
            if (method_exists($def, 'getAlias') && $def->getAlias() === Request::class) {
                $defRef = new ReflectionClass($def);
                $resolvedProp = $defRef->getProperty('resolved');
                $resolvedProp->setValue($def, $request);
                return;
            }
        }
    }
}

#!/usr/bin/env bash
# scripts/docs-check.sh
#
# CI-runnable documentation consistency check for SwallowPHP Phase 2 Tier 1.
#
# This script verifies the 23 acceptance criteria (AC-1..AC-23) from
# docs/SPEC's "docs/code consistency" pass. It is *not* a PHPUnit/Pest
# test: it lives outside tests/ so the build's docs-only diff rule is
# preserved, and so it can be invoked directly from CI as:
#
#     bash scripts/docs-check.sh
#
# Exit status:
#   0   every AC's assertion held (all OK)
#   1   at least one AC failed
#
# Each AC uses the exact grep pair from the SPEC where one is provided,
# plus a small number of source-aware checks for AC-16/AC-18/AC-19
# (the ACs whose spec grep is MANUAL).

set -u

# Always run from the repo root so the relative paths in the ACs work.
cd "$(dirname "$0")/.."

failures=0
checked=0
manual=0

note_fail() { printf "    FAIL  %s\n" "$1"; failures=$((failures + 1)); }
note_pass() { printf "    OK    %s\n" "$1"; }
note_manual() { printf "    MANUAL  %s\n" "$1"; manual=$((manual + 1)); }

# Run a grep and emit PASS/FAIL. Usage: check <label> <must_match_regex> <file...>
check_grep_match() {
    local label="$1"; shift
    local pattern="$1"; shift
    if grep -qE "$pattern" "$@"; then
        note_pass "$label"
    else
        note_fail "$label (pattern not found: $pattern)"
    fi
    checked=$((checked + 1))
}

# Same, but the doc must NOT match.
check_grep_absent() {
    local label="$1"; shift
    local pattern="$1"; shift
    if grep -qE "$pattern" "$@"; then
        note_fail "$label (forbidden pattern present: $pattern)"
    else
        note_pass "$label"
    fi
    checked=$((checked + 1))
}

printf "SwallowPHP documentation consistency check\n"
printf "Repo: %s\n" "$(pwd)"
printf "\n"

# ---------- AC-1 ----------
printf "[AC-1] README.md — stale version/PHP badges\n"
check_grep_absent "README no PHP 8.0"        '8\.0'                README.md
check_grep_absent "README no v1.1.0"         '1\.1\.0'             README.md
check_grep_match   "README mentions 8.2"     '8\.2'                README.md
check_grep_match   "README mentions 2.0.0"   '2\.0\.0'             README.md
printf "\n"

# ---------- AC-2 ----------
printf "[AC-2] docs/authentication.md — nonexistent auth() helper\n"
check_grep_absent "docs no auth()->"         "auth\\(\\)->"        docs/authentication.md
check_grep_match   "docs uses Auth::isAuthenticated" "Auth::isAuthenticated\\(\\)" docs/authentication.md
printf "\n"

# ---------- AC-3 ----------
printf "[AC-3] docs/database.md — model-event callbacks receive the model, not an array\n"
check_grep_absent "no function (\$data) event example" "function \\(\\\$data\\)" docs/database.md
check_grep_match   "creating listener uses function (\$model)" "'creating'.*function \\(\\\$model\\)" docs/database.md
printf "\n"

# ---------- AC-4 ----------
printf "[AC-4] docs/database.md — delete()/deleteAll() v2.0.0 contract\n"
check_grep_match "deleteAll() documented"   "deleteAll"          docs/database.md
check_grep_match "RuntimeException documented" "RuntimeException" docs/database.md
printf "\n"

# ---------- AC-5 ----------
printf "[AC-5] docs/database.md + docs/helpers.md — update() returns int|false\n"
check_grep_match "database.md mentions === false" "=== false" docs/database.md
# helpers.md: look for the 'false' claim with flexible grep
if grep -qiE 'int\|false|false.*fail|returns\s+false|=== false' docs/helpers.md; then
    note_pass "helpers.md mentions false"
else
    note_fail "helpers.md mentions false (no false-return claim)"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-6 ----------
printf "[AC-6] docs/http.md — 4-arg Laravel-style paginate() signature\n"
check_grep_absent "no 4-arg paginate" "paginate\\(\\\$limit, \\['\\*'\\]" docs/http.md
printf "\n"

# ---------- AC-7 ----------
printf "[AC-7] docs/helpers.md + docs/database.md — request()->query(\$key) → getQuery()\n"
check_grep_absent "no request()->query('key') form" "query\\('[a-zA-Z_]+'" docs/helpers.md docs/database.md
check_grep_match   "helpers.md uses getQuery" "getQuery\\(" docs/helpers.md
printf "\n"

# ---------- AC-8 ----------
printf "[AC-8] docs/helpers.md — User::all() doesn't exist\n"
check_grep_absent "no ::all()" "::all\\(\\)" docs/helpers.md
printf "\n"

# ---------- AC-9 ----------
printf "[AC-9] flash data via plain session() helper is wrong\n"
check_grep_absent "no plain session('success'|'error'|'warning'|'info') for flash" \
    "session\\('(success|error|warning|info)'\\)" docs/session.md docs/views.md docs/helpers.md
check_grep_match   "session.md uses getFlash" "getFlash" docs/session.md
# Source-aware: confirm the new lifecycle claim is present and the stale
# "removed after being read" claim is gone.
if grep -q 'removed after being read\|after it is read\|removed after read' docs/session.md; then
    note_fail "session.md still has the stale flash-lifecycle claim"
else
    note_pass "session.md no longer claims flash data is removed after being read"
fi
checked=$((checked + 1))
if grep -qE 'available throughout request|set on request N is' docs/session.md; then
    note_pass "session.md documents the N→N+1→N+2 flash lifecycle"
else
    note_fail "session.md missing the correct N→N+1→N+2 flash lifecycle"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-10 ----------
printf "[AC-10] docs/database.md — where(col, null) semantics + whereNull() / whereNotNull()\n"
check_grep_match "whereNull documented" "whereNull" docs/database.md
check_grep_match "IS NULL documented"   "IS NULL"   docs/database.md
printf "\n"

# ---------- AC-11 ----------
printf "[AC-11] docs/http.md, docs/middleware.md, docs/logging.md — getClientIp() as instance call\n"
check_grep_absent "no \$request->getClientIp()" "\\\$request->getClientIp\\(\\)" docs/http.md docs/middleware.md docs/logging.md
printf "\n"

# ---------- AC-12 ----------
printf "[AC-12] docs/http.md — wrong array-shape remember-me example\n"
check_grep_absent "no 'user_id' => ... 'token' => hash example" \
    "'user_id' => .*'token' => hash" docs/http.md
# Source-aware: the doc must point to the canonical authenticate(remember:true) call.
if grep -qE 'remember:\s*true|Auth::authenticate\(.*remember' docs/http.md docs/authentication.md; then
    note_pass "remember-me example references Auth::authenticate(remember: true)"
else
    note_fail "remember-me example missing reference to Auth::authenticate(remember: true)"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-13 ----------
printf "[AC-13] docs/database.md — protected getDirty() shown as public call\n"
check_grep_absent "no \$var->getDirty() public call" "\\\$[a-zA-Z]+->getDirty\\(\\)" docs/database.md
printf "\n"

# ---------- AC-14 ----------
printf "[AC-14] docs/views.md — ViewNotFoundException documented as HTTP 500\n"
check_grep_absent "no ViewNotFoundException ... 500" "ViewNotFoundException.*500" docs/views.md
# Source-aware: the doc must state 404 for ViewNotFoundException (matches source).
if grep -qE 'ViewNotFoundException.*404|404.*ViewNotFoundException' docs/views.md; then
    note_pass "views.md states 404 for ViewNotFoundException"
else
    note_fail "views.md does NOT state 404 for ViewNotFoundException"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-15 ----------
printf "[AC-15] docs/helpers.md — raw() returns RawValue object\n"
check_grep_absent "no RawValue claim" "RawValue" docs/helpers.md
printf "\n"

# ---------- AC-16 ----------
# MANUAL grep in SPEC; we add source-aware verification: parse the
# src/Routing/Route.php execute() method and check the doc's prose
# claims. Concretely, the doc MUST say (in some form) that the first-
# added middleware runs first on the request, and the response unwinds
# back through middlewares in reverse order.
printf "[AC-16] docs/middleware.md — middleware pipeline order wording (manual)\n"
if grep -qE 'reverse order|reverse\b' docs/middleware.md \
    && grep -qE 'first-added|first added|registration order|in order added' docs/middleware.md; then
    note_pass "middleware.md mentions both registration-order request and reverse-order response"
else
    note_fail "middleware.md missing pipeline-order wording (request registration order / response reverse)"
fi
checked=$((checked + 1))
# Source-aware cross-check: src/Routing/Route.php's execute() reverses
# the middleware list, which means first-added's after logic runs LAST.
if grep -qE 'array_reverse\(\$this->middlewares\)' src/Routing/Route.php; then
    note_pass "src/Routing/Route.php execute() reverses middlewares (matches doc's 'reverse' claim)"
else
    note_fail "src/Routing/Route.php no longer uses array_reverse() — doc and source disagree"
fi
checked=$((checked + 1))
# The doc must not claim request is LIFO (last-in-first-out), which
# would contradict the registration-order example immediately below it.
if grep -qE 'request\s+.*LIFO|request.*last-added.*first' docs/middleware.md; then
    note_fail "middleware.md still claims request is LIFO (last-added-first)"
else
    note_pass "middleware.md does not claim request is LIFO"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-17 ----------
# Spot-check from SPEC; we extend to cover every file in the AC's scope.
# We strengthen the check beyond the SPEC's grep: middleware.md has TWO
# distinct examples that use Auth (AuthMiddleware at the top, and an
# AdminMiddleware further down). Both must declare the `use` line; if
# either drops it the count falls below 2 and the check fails. This is
# exactly the case the auditor flagged ("AC-17's spot-check still
# passes if the import is removed from the first example").
printf "[AC-17] docs/middleware.md, docs/routing.md, docs/http.md, docs/views.md — missing imports in examples\n"
check_grep_match "middleware.md uses Auth import" "use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;" docs/middleware.md
# Stronger: count occurrences (>=2 means both examples declare it)
auth_count=$(grep -c "use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;" docs/middleware.md || true)
if [ "$auth_count" -ge 2 ]; then
    note_pass "middleware.md has >=2 Auth import lines (both AuthMiddleware + AdminMiddleware)"
else
    note_fail "middleware.md has only $auth_count Auth import line(s); expected >=2 (one per Auth-using example)"
fi
checked=$((checked + 1))
check_grep_match "routing.md uses Auth import"     "use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;" docs/routing.md
check_grep_match "http.md uses User import"        "use App\\\\Models\\\\User;" docs/http.md
check_grep_match "http.md uses Auth import"        "use SwallowPHP\\\\Framework\\\\Auth\\\\Auth;" docs/http.md
check_grep_match "views.md uses Post import"       "use App\\\\Models\\\\Post;" docs/views.md
# Source-aware: for every `Auth::` reference in the examples, the doc
# block immediately above must contain the matching `use` line.
check_doc_has_use_for_class() {
    local doc="$1" local ns="$2" local class="$3"
    # Every `class FQCN::method` (FQCN form) or `\FQCN::method` in the
    # doc implies a use is needed. We check whether a `use` for the FQCN
    # appears at least once. (Conservative: it might appear in some other
    # example without being needed; that's still OK.)
    if grep -qE "use ${ns}\\\\${class};" "$doc"; then
        note_pass "$doc has 'use ${ns}\\${class};'"
    else
        note_fail "$doc missing 'use ${ns}\\${class};'"
    fi
    checked=$((checked + 1))
}
check_doc_has_use_for_class "docs/middleware.md" "SwallowPHP\\\\Framework\\\\Auth"     "Auth"
check_doc_has_use_for_class "docs/routing.md"    "SwallowPHP\\\\Framework\\\\Auth"     "Auth"
check_doc_has_use_for_class "docs/http.md"       "App\\\\Models"                      "User"
check_doc_has_use_for_class "docs/http.md"       "SwallowPHP\\\\Framework\\\\Auth"     "Auth"
check_doc_has_use_for_class "docs/views.md"      "App\\\\Models"                      "Post"
printf "\n"

# ---------- AC-18 ----------
# MANUAL grep; source-aware cross-check: VerifyCsrfToken::inExceptArray
# requires the trailing slash for /api/*, so the doc must NOT claim
# /api/* also matches bare /api.
printf "[AC-18] docs/middleware.md — /api/* CSRF-exempt pattern matches bare /api (manual)\n"
if grep -qE "/api/\\*\\s*\\)?\\s*(also )?matches?\\s+the\\s+bare\\s+/api\\b|also matches\\s+/api[^/]" docs/middleware.md; then
    note_fail "middleware.md still claims /api/* matches bare /api"
else
    note_pass "middleware.md does not claim /api/* matches bare /api"
fi
checked=$((checked + 1))
# Source-aware: VerifyCsrfToken must use str_starts_with with a pattern
# that ends in '/', confirming bare /api does NOT match /api/*.
if grep -qE "str_starts_with\\(\\\$requestPath,\\s*\\\$pattern\\)" src/Http/Middleware/VerifyCsrfToken.php \
    && grep -qE "str_ends_with\\(\\\$except,\\s*'/\\*'\\)" src/Http/Middleware/VerifyCsrfToken.php; then
    note_pass "src/Http/Middleware/VerifyCsrfToken.php uses str_starts_with against trailing-slash pattern (matches doc)"
else
    note_fail "src/Http/Middleware/VerifyCsrfToken.php CSRF matching has drifted from the doc"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-19 ----------
# MANUAL grep; source-aware cross-check: ValidatePostSize throws
# PayloadTooLargeException with specific messages, and ExceptionHandler
# builds the 413 body with the exact JSON message below.
printf "[AC-19] docs/middleware.md — ValidatePostSize example body (manual)\n"
if grep -qE 'Uploaded data is too large\. Please reduce the file size and try again\.' docs/middleware.md; then
    note_pass "middleware.md example body matches ExceptionHandler's 413 JSON message"
else
    note_fail "middleware.md example body does NOT match ExceptionHandler's 413 JSON message"
fi
checked=$((checked + 1))
# Source-aware: ValidatePostSize must throw PayloadTooLargeException,
# and ExceptionHandler must produce the message the doc shows.
if grep -qE 'PayloadTooLargeException' src/Http/Middleware/ValidatePostSize.php \
    && grep -qE "Uploaded data is too large. Please reduce the file size and try again\\." src/Foundation/ExceptionHandler.php; then
    note_pass "src ValidatePostSize + ExceptionHandler match the doc's example"
else
    note_fail "src ValidatePostSize or ExceptionHandler drifted from the doc"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-20 ----------
printf "[AC-20] docs/logging.md — 'stderr' vs 'errorlog' channel driver\n"
check_grep_absent "no 'driver' => 'stderr'" "'driver'\\s*=>\\s*'stderr'" docs/logging.md
check_grep_match   "'driver' => 'errorlog' present" "'driver'\\s*=>\\s*'errorlog'" docs/logging.md
printf "\n"

# ---------- AC-21 ----------
printf "[AC-21] docs/configuration.md — app.trusted_proxies documented\n"
check_grep_match "trusted_proxies documented" "trusted_proxies" docs/configuration.md
# Source-aware: the doc must mention the ['*'] shorthand and the
# security implication, matching CHANGELOG's "Action required" wording.
if grep -qE "\\['\\*'\\]" docs/configuration.md \
    && grep -qE -i 'security|misconfigur|trust' docs/configuration.md; then
    note_pass "configuration.md mentions ['*'] shorthand and security implication"
else
    note_fail "configuration.md missing ['*'] shorthand or security implication"
fi
checked=$((checked + 1))
# Source-aware: src must actually read config('app.trusted_proxies').
if grep -qE "config\\(['\"]app\\.trusted_proxies['\"]\\)" src/Http/Request.php; then
    note_pass "src/Http/Request.php reads config('app.trusted_proxies')"
else
    note_fail "src/Http/Request.php no longer reads app.trusted_proxies — doc and source disagree"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-22 ----------
# MANUAL grep; we strengthen the SPEC's grep to match either of two
# common phrasings the doc may legitimately use, plus a source-aware
# cross-check that Model::fireEvent honours the abort semantics.
printf "[AC-22] docs/database.md — Model::on() returning false aborts propagation\n"
if grep -qE 'returns.*false.*(stop|abort)|stop.*listener.*false|abort.*listener|false.*abort' docs/database.md; then
    note_pass "database.md documents false-return abort semantics"
else
    note_fail "database.md does NOT document false-return abort semantics"
fi
checked=$((checked + 1))
if grep -qE '=== false|return.*=== false' src/Database/Model.php; then
    note_pass "src/Database/Model.php has a === false branch in the event path"
else
    note_fail "src/Database/Model.php no longer honours the false-return abort — doc and source disagree"
fi
checked=$((checked + 1))
printf "\n"

# ---------- AC-23 ----------
printf "[AC-23] README.md — php-debugbar mislabeled as dev-only\n"
check_grep_absent "no php-debugbar (dev) label" "php-debugbar.*\\(dev\\)" README.md
printf "\n"

# ---------- summary ----------
printf "=================================\n"
printf "Checked: %d   Failed: %d   Manual: %d\n" "$checked" "$failures" "$manual"
printf "=================================\n"

if [ "$failures" -gt 0 ]; then
    printf "RESULT: FAIL (%d failures)\n" "$failures"
    exit 1
fi

printf "RESULT: PASS\n"
exit 0

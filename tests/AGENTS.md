# tests/AGENTS.md — TrustedLogin Client SDK test guide

Read this before writing or running tests. The repo has two parallel test suites that look similar but require very different infrastructure.

## Two suites, two configs

| Suite | Files | Bootstrap | Config | Needs WP test env? |
|---|---|---|---|---|
| **Integration** | `tests/test-*.php` | `tests/bootstrap.php` | `phpunit.xml.dist` | **Yes** — extends `WP_UnitTestCase`, requires a real WordPress install + database |
| **Unit** | `tests/Unit/*Test.php` | `tests/Unit/bootstrap.php` | `phpunit-unit.xml` | **No** — extends plain `PHPUnit\Framework\TestCase`, stubs WP functions inline |

If a test can be written without depending on the WP database, custom post types, or hooks firing in a real WP runtime, it belongs in `tests/Unit/`. Reach for `WP_UnitTestCase` only when there's no other way.

## Running tests

### Unit suite (preferred for new tests)

The cheapest path. Runs in seconds against a tiny PHP container — no MySQL, no WordPress. The local toolchain handles PHP 8.0 + PHPUnit 9 via the `gkunit` Docker wrapper:

```bash
gkunit test --php 8.0 --plugin-dir "$(pwd)" -- --configuration=/app/plugin/phpunit-unit.xml
```

Or directly with Docker (faster for iteration, same image):

```bash
docker run --rm \
    -v "$(pwd)":/app/plugin \
    -w /app/plugin \
    gravitykit/php:8.0 \
    vendor/bin/phpunit -c phpunit-unit.xml
```

The `gravitykit/php:8.0` image is pulled by `gkunit` on first use. You don't need to build anything.

### Integration suite

Needs `WP_TESTS_DIR` set to a WordPress test library checkout, plus a MySQL test database. The pre-existing setup is brittle on local machines (works in CI). Locally, prefer `gkunit`:

```bash
gkunit test --php 8.0 --plugin-dir "$(pwd)"
```

(Without an explicit `--configuration`, gkunit picks `phpunit.xml.dist`, which runs the integration suite.)

If running outside gkunit, `composer test` works **only** when `WP_TESTS_DIR` points at a fully installed WP test lib that's been initialized with `bin/install-wp-tests.sh` (the trustedlogin-connector repo ships that script — copy from there if needed).

### Linting + static analysis

```bash
composer lint        # phpcs against .phpcs.xml.dist
composer format      # phpcbf — auto-fix linting
composer phpstan     # static analysis
```

## Pitfalls — read this before debugging silent failures

These are non-obvious; each one cost time the first time it bit.

### 1. `src/*.php` files self-abort outside WordPress

Every class file starts with:

```php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
```

If a unit-test bootstrap forgets to define `ABSPATH` before requiring the autoloader, PHP silently `exit`s the moment Composer's autoloader reaches one of these files. PHPUnit then reports zero tests with no error.

**Fix:** `tests/Unit/bootstrap.php` defines `ABSPATH` before `require_once 'vendor/autoload.php'`. Don't remove that line.

### 2. `Config`, `Remote`, and `LoginAttempts` are `final`

`Logging` is the only injectable dependency you can subclass. For everything else, use **anonymous-class duck-typed doubles + `ReflectionClass::newInstanceWithoutConstructor()`** to install them on the SUT. The pattern:

```php
$double = new class {
    public $captured;
    public function send( $path, $body = array(), ... ) { /* record call */ }
};

$rc  = new \ReflectionClass( LoginAttempts::class );
$sut = $rc->newInstanceWithoutConstructor();

foreach ( array( 'config' => $cfg, 'remote' => $double, 'logging' => $log ) as $name => $val ) {
    $prop = $rc->getProperty( $name );
    $prop->setAccessible( true );
    $prop->setValue( $sut, $val );
}
```

`getProperty()` returns a fresh `ReflectionProperty` each call — capture it in a variable and call `setAccessible(true)` + `setValue()` on the same reference, otherwise the second call to `getProperty()` returns a new (still-private) property and `setValue()` throws.

### 3. PHPUnit silently exits 0 with no output when no tests are discovered

If your test file has a parse error, a missing class, an uncaught fatal during require, or a namespace that PHPUnit's discovery can't reflect, `vendor/bin/phpunit -c phpunit-unit.xml` will exit 0 and print nothing. Diagnostic recipe:

```bash
# Confirm PHP can load the test file at all
docker run --rm -v $PWD:/app/plugin -w /app/plugin gravitykit/php:8.0 \
    php -l tests/Unit/YourTest.php

# Step through requires in a tmp script that writes progress to a file:
cat > /tmp/diag.php <<'PHP'
<?php
file_put_contents('/app/plugin/.diag-step', 'start');
require '/app/plugin/vendor/autoload.php';
file_put_contents('/app/plugin/.diag-step', 'autoload');
require '/app/plugin/tests/Unit/bootstrap.php';
file_put_contents('/app/plugin/.diag-step', 'bootstrap');
require '/app/plugin/tests/Unit/YourTest.php';
file_put_contents('/app/plugin/.diag-step', 'testfile');
PHP
docker run --rm -v $PWD:/app/plugin -v /tmp/diag.php:/tmp/diag.php gravitykit/php:8.0 php /tmp/diag.php
cat .diag-step  # tells you which step crashed
```

If `.diag-step` says `bootstrap` but the test file doesn't load, you're hitting pitfall #1 (ABSPATH) or a parse error.

### 4. Mixed namespace-block and file-namespace declarations confuse PHPUnit

Don't mix:

```php
namespace { /* global stubs */ }
namespace TrustedLogin\Tests\Unit { /* test class */ }
```

with a non-block `namespace TrustedLogin\Tests\Unit;` declaration. Pick one. The unit suite uses the non-block form (cleaner) and ships stubs in `tests/Unit/bootstrap.php` instead of inline.

### 5. Constants are process-global and persist across tests

`define('TRUSTEDLOGIN_DISABLE_AUDIT_TESTNS', true)` in one test stays defined for every subsequent test in the same PHPUnit run. PHP has no way to undefine constants.

**Fix:** if a test needs to define a per-namespace constant, give that test a **unique namespace** (return a different string from the Config double's `ns()` method). The `LoginAttemptsUnitTest` does this via `makeSutWithNamespace()` — copy the helper if you need it.

### 6. `colors="false"` in `phpunit-unit.xml`

ANSI escape sequences from PHPUnit's progress bar can confuse some shell environments and tooling. The unit config has `colors="false"` deliberately; don't flip it back.

## Where new tests go

| You're testing… | File location | TestCase parent |
|---|---|---|
| Pure logic with no WP dependency | `tests/Unit/<Subject>Test.php` | `\PHPUnit\Framework\TestCase` |
| Code that calls WP database, hooks, REST | `tests/test-<subject>.php` | `\WP_UnitTestCase` |
| AJAX handlers | `tests/test-ajax.php` (existing) | `\WP_Ajax_UnitTestCase` |

### Naming

- Unit-suite class names match the file: `tests/Unit/FooTest.php` declares `class FooTest extends TestCase`.
- Integration-suite class names follow the existing convention: `tests/test-foo.php` declares `class TrustedLoginFooTest extends WP_UnitTestCase`. The class name doesn't have to match the filename for integration tests because the `default` testsuite uses `prefix="test-" suffix=".php"` discovery.

### Annotations

Tests can group via `@group <name>` on the docblock. Existing groups: `@group login-attempts`, `@group unit`. Filter by group on the command line with `--group <name>`.

## Stub patterns for the unit suite

`tests/Unit/bootstrap.php` already stubs:

- `wp_unslash` (pass-through with stripslashes)
- `wp_remote_retrieve_response_code` / `wp_remote_retrieve_body` (read from a fake `$response` array shape: `['response' => ['code' => 201], 'body' => '…']`)
- `is_wp_error` (instanceof check)
- `\WP_Error` (minimal class with `get_error_code` / `get_error_message`)

If a new SUT calls a WP function not on this list, **add the stub to bootstrap.php** rather than inlining it in the test file. Keep test files focused on assertions.

For HTTP responses returned by Remote-shaped doubles, the canonical shape is:

```php
$remote->next_response = array(
    'response' => array( 'code' => 201 ),
    'body'     => '{"id":"lpat_..."}',
);
```

That matches what `wp_remote_retrieve_*` reads from.

## Existing unit-suite coverage (so you don't duplicate)

`tests/Unit/LoginAttemptsUnitTest.php` already covers:

- `is_audit_log_enabled()` — default-on, opt-out via `TRUSTEDLOGIN_DISABLE_AUDIT_<NS>` constant
- `resolve_client_ip()` — full priority matrix (CF / XFF first hop / REMOTE_ADDR / all-invalid)
- `report()` input gating — missing secret_id, missing required fields, secret_id pull-out, 3 s timeout
- `report()` defensive sanitization — non-hex `identifier_hash` dropped, valid hex preserved; invalid `client_ip` dropped, valid preserved
- `report()` UTF-8-safe truncation — `mb_strcut` boundary correctness for `detailed_reason` and `client_user_agent`
- `report()` secret scrubbing — Bearer tokens, Stripe sk_live/sk_test keys, password=value pairs
- `report()` status-code matrix — 201 OK, 422 / 429 / 5xx / 3xx, malformed JSON, missing id, network WP_Error, exception thrown by Remote

## Existing integration-suite coverage

Each `tests/test-*.php` file targets one src/ class. Keep that mapping clean — don't add cross-class assertions to `test-config.php` etc. If a new behavior spans two classes, write a new file.

## Commit hygiene

Per the repo's top-level `AGENTS.md`, commit messages and code comments must describe what the code does today, not what it used to do or what audit / review process flagged it. Tests are public artifacts too — name them by the property they assert (`test_foo_rejects_garbage_input`), not by the bug they prevent (`test_foo_no_longer_eats_pets`).

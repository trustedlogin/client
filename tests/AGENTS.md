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

## Tests that pass for the wrong reason

A green test is necessary but not sufficient. Below are the patterns that produce false confidence — recurring shapes seen in this codebase and adjacent ones (TrustedLogin SaaS / Connector). Self-review every test against this list before committing.

### 1. The narrowed assertion that hides an unidentified emitter

Pattern: `assertNothingSent()` fails because something is dispatching, you don't know what, and you replace it with `assertNotSentTo($specific_class)` to make the test pass.

Diagnostic: dump the captured emissions (Reflection on the fake's internal `notifications` / `events` / `mail` array). Every test framework's `fake()` exposes the captured items somewhere. Once you know the source, decide:
- It's a legitimate emitter from the test fixtures? Move `fake()` to AFTER the fixture creation so it's not observed.
- It's an emitter from the SUT? Either widen the test to assert it's the only thing sent, or trace it to a sibling listener that needs its own coverage.

The narrow-the-assertion shortcut is almost always wrong — the unidentified emitter is either real coverage or a real bug.

### 2. The wiring assertion that replaces the property assertion

Pattern: the actual property (e.g. "endpoint rejects requests without nonce") is hard to drive in the test framework, so the test asserts the property's *prerequisite* (e.g. "the filter responsible for the property is registered") and calls it covered.

Wiring assertions are useful but not equivalent to property tests. If the test framework can't drive the auth path, find a workaround:
- Set the magic globals the production path checks (e.g. `$GLOBALS['wp_rest_auth_cookie'] = true` to fake "this is cookie auth").
- Call the filter callback directly rather than through `apply_filters()` — bypasses unrelated callbacks that may set headers / cookies and break the test environment.
- If the test framework genuinely can't reach the property, write an e2e (Playwright / browser-driven) test instead of accepting a wiring proxy.

### 3. The synthetic input that doesn't match production

Pattern: a test injects an error code / payload shape / event the SUT *could* receive in theory, but never actually sees in production. The test passes because it tests the synthetic path; the production path remains untested.

Examples:
- A WP_Error with code `'http_error_404'` when the production code only ever emits `'not_found'`.
- A request body field that the production client never sends.
- An event with a payload shape produced by no live emitter.

Diagnostic: trace every test fixture back to its real-world producer. If you can't find one, the test is testing a hypothetical, not the system. Either change the fixture to match production, or document explicitly that this test pins a defense-in-depth fallback (and add a sibling test for the real production input).

### 4. The defense gap cemented as "expected"

Pattern: a defensive test pins behavior that's a pragmatic design choice (e.g. "Connector trusts SaaS for clamping"). The test green-lights the design but also locks the gap in place, making the next person who looks at it less likely to question whether the trust boundary is right.

If the design is genuinely correct, fine — but add a sibling test that pins the *defense-in-depth* layer (e.g. "Connector also clamps locally so a SaaS regression can't bleed through"). Two layers, two tests, two opportunities for a future change to be flagged.

### 5. Acceptance ranges so wide they swallow the bug

Pattern: `assertContains($status, [200, 401, 403, 404, 405, 413, 414])` because you weren't sure which exact status the test would produce. The list is wide enough that a 5xx-fix regression that drops the response to 400 still passes.

Always:
- Either narrow to one status per test (split the test).
- Or, when range is intentional (404 vs 405 differ by web-server config), explicitly enumerate ONLY the acceptable codes and exclude 5xx + 200-on-rejection.

### 6. The test that wasn't actually exercising the SUT

Pattern: a parameter-binding mistake, type coercion, or sanitizer call in the request pipeline transforms the test input before it reaches the SUT. The test "passes" because the SUT was given trivially-valid input.

The classic case in this codebase: the WP REST `'sanitize_callback' => 'absint'` route arg. **`absint(-50) = 50`, not 0.** A test sending `-50` to exercise a min-clamp branch reaches the controller as `50` — well inside the valid range, no clamp triggered, test green for the wrong reason. Always trace what the SUT actually receives:

```php
$args = $this->dispatch_with_per_page( -50 );
// add temporarily: $this->fail( var_export( $args, true ) );
```

Once you know what the SUT really sees, restructure the test to either use a value that survives sanitization or to assert the sanitization transform itself.

### 7. Test pollution from process-global state

Pattern: tests pass when run alone but fail in combination. The first-run results are real; the combined-run failures are not "flake".

Common pollution sources in WP / Laravel test environments:
- PHP `define()` constants — once defined, they're permanent for the run. Use unique names per test.
- `add_action()` / `add_filter()` registrations — accumulate across test classes unless explicitly removed in `tearDown()`. Use `remove_all_actions(<hook>)` / `remove_all_filters(<hook>)`.
- `$_GET` / `$_SERVER` — survive across tests unless reset in `setUp()`.
- `$GLOBALS['wp_rest_auth_cookie']` and similar WP REST globals.
- Headers already sent — once PHPUnit echoes the progress dot, any later `header()` call warns.

Always restore globals in a `try { } finally { }` block when a test mutates them.

## WP REST testing — gotchas in `WP_UnitTestCase`

If a Client SDK feature ever exposes a REST route, these caught me on the SaaS / Connector side:

### `dispatch()` skips `check_authentication()`

`rest_get_server()->dispatch( $request )` runs the route handler but does NOT run the auth filter chain. `check_authentication()` only fires from `serve_request()`. To test auth-chain behavior:
- Call the specific filter callback directly (e.g. `rest_cookie_check_errors( null )`).
- Or apply the filter manually with `apply_filters( 'rest_authentication_errors', null )`.

Both bypass the dispatch path — which means the route's permission_callback isn't exercised by them. You usually want both: filter test asserts auth behavior, dispatch test asserts routing + permission.

### Cookie auth simulation

`wp_set_current_user( $admin_id )` does NOT set `$wp_rest_auth_cookie`. `rest_cookie_check_errors()` reads that global to decide whether to enforce the nonce branch. To test cookie-auth behavior under WP_UnitTestCase:

```php
$GLOBALS['wp_rest_auth_cookie'] = true;
try {
    $result = rest_cookie_check_errors( null );
    // ...
} finally {
    unset( $GLOBALS['wp_rest_auth_cookie'] );
}
```

### `rest_cookie_check_errors()` calls `header()` on success

When the nonce is valid, the function calls `rest_get_server()->send_header( 'X-WP-Nonce', ... )` to refresh the response nonce. Native `header()` warns when output has already been sent (PHPUnit's progress dots count). With `convertWarningsToExceptions="true"` in phpunit-integration.xml, that warning becomes a `TypeError` and the test fails.

Workaround: `@`-suppress the call when you don't care about response headers:

```php
return @rest_cookie_check_errors( null );
```

### Route registration must happen on `rest_api_init`

Calling `register_rest_route` from outside the `rest_api_init` action triggers an `_doing_it_wrong` notice. WP_UnitTestCase converts that to a test failure. Wrap registration in the action:

```php
add_action( 'rest_api_init', function () {
    ( new MyEndpoint() )->register();
} );
do_action( 'rest_api_init', rest_get_server() );
```

### Resolver / dependency injection via filters

If the endpoint's `permission_callback` ends in real instantiation, inject a fake via a project-defined filter (e.g. `FILTER_RESOLVER_INJECT`) and short-circuit before the real call. Type hints on the production code may force you to wrap a duck-typed double in a real instance — test the resolver layer with the real class, the dependent layer with the duck.

## Generalizable findings recap

A condensed list to reread before any new test commit:

1. Faking notifications/events: place the `fake()` call AFTER fixture creation so the fixtures' legitimate emissions don't pollute the test's assertion target.
2. `absint(-N) = N`, not 0. Sanitizers transform inputs BEFORE the SUT; test what the SUT actually receives.
3. `WP_REST_Server::dispatch()` skips `check_authentication()`. Drive the auth pipeline manually if you want to test it.
4. Process-global state (constants, hooks, $_SERVER, REST globals) survives across tests. Use unique values per test or restore in `finally`.
5. A test that passes when run alone but fails in combination is signaling pollution, not flake.
6. `final` classes can't be `extend`ed for stubbing — use anonymous classes + Reflection on private properties.
7. Wiring assertions ("the filter is registered") are weaker than property assertions ("the filter rejects bad input"). Prefer property tests; fall back to wiring only when the framework genuinely can't drive the property.
8. If a test's input passes through any sanitizer/coercer/validator before reaching the SUT, document what the SUT actually sees. The expected branch may not be the one actually exercised.

## Real-Client integration tests vs. Reflection-stub unit tests

For tests that exercise behavior across multiple SDK objects (grant, revoke, endpoint replay), prefer the **real-Client pattern**:

```php
$client = new \TrustedLogin\Client( $config, false );  // false = no init() / no WP hooks
```

`$init = false` constructs the full dependency graph (Remote, SiteAccess, SupportUser, Endpoint, Ajax) without firing the hook-registration side effects. The test exercises real flows; only the OUTERMOST boundary (HTTP / Remote) needs a stub if you want to control SaaS responses.

Use Reflection-stub unit tests only when:
- The SUT is a private method with no exposed entry point (`Form::get_login_inline_css`).
- The SUT's dependencies are themselves `final` classes that can't be subclassed and aren't injectable through any public constructor.

For `test-grant-security.php` style integration tests, the real-Client pattern handles:
- The full email-collision dance in `SupportUser::create()`.
- Hook-driven flows (`maybe_revoke_support` triggers `do_action('trustedlogin/{ns}/access/revoke')` consumed by `Cron::revoke`).
- Cron schedule + delete chains.

### Multisite test-environment quirks

`WP_TESTS_MULTISITE=1` is set in `phpunit.xml.dist`. That means:

- A user created with `factory()->user->create(['role' => 'administrator'])` is a regular site admin without `delete_users`. In multisite, that cap requires super-admin. Tests that exercise revoke / delete must call `grant_super_admin($user_id)` after `wp_set_current_user`.
- `email_exists()` checks the network-wide users table, so support-user creation with the same vendor email collides across sites.

### `SupportUser::get()` identifier-length contract

`get($id)` hashes the input only when `strlen($id) > 32`. Production `site_identifier_hash` values are 64-byte hex (128 chars), so the hash branch always fires. Test fixtures using short labels (`'targetA'`) hit the no-hash branch and lookup fails silently.

**Fix:** use 40-char identifiers in tests so they always go through the hash path:

```php
const IDENT_TARGET = 'targetA-aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
```

### Cron action handler is wired separately from the Endpoint

`Endpoint::maybe_revoke_support` triggers `do_action('trustedlogin/{ns}/access/revoke', $user_identifier)`. The consumer is `Cron::revoke`, registered by `Cron::init()` — which `Client::init()` calls. If a test instantiates `Endpoint` directly (without `Client::init`), it must wire the Cron handler itself:

```php
$this->cron = new \TrustedLogin\Cron( $this->config, $this->logging );
$this->cron->init();
```

Otherwise the `do_action` fires into a void and the actual delete never happens — the test sees the support user still present after revoke.

Detach in `tearDown` to avoid handler accumulation across test methods:

```php
remove_action(
    'trustedlogin/' . $this->config->ns() . '/access/revoke',
    array( $this->cron, 'revoke' ),
    1
);
```

### `wp_safe_redirect` and `wp_die` in tests

Both `exit()` after their work, which would halt the test runner. Use a filter to abort the redirect or a custom handler for wp_die:

```php
add_filter( 'wp_redirect', static function () { return false; }, 10, 2 );
```

Returning `false` from `wp_redirect` skips the actual `header()` call and lets the test continue.

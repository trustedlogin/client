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

The cheapest path. Runs in seconds against the host PHP — no Docker, no MySQL, no WordPress.

```bash
composer test:unit
```

That's `phpunit --configuration=phpunit-unit.xml`. The unit-only config skips `tests/bootstrap.php` (which expects a WP test lib) and only loads the plugin's autoloader, so it works on any machine with PHP and the dev composer dependencies installed.

### Integration suite

Needs `WP_TESTS_DIR` set to a WordPress test library checkout, plus a MySQL test database.

```bash
composer test:integration   # alias for: phpunit --configuration=phpunit.xml.dist
composer test               # same — phpunit picks up phpunit.xml.dist by default
```

If `WP_TESTS_DIR` isn't set, `tests/bootstrap.php` will fail loudly. Bootstrap a local WP test lib with `bin/install-wp-tests.sh` (copy from the trustedlogin-connector repo if needed) before running.

> Why no Docker wrapper here: the test infra is intentionally TrustedLogin-native. If you maintain a multi-PHP-version Docker setup elsewhere, run it from outside the repo against `composer test:unit` / `composer test:integration` — but nothing in this repo's tooling depends on it.

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

If your test file has a parse error, a missing class, an uncaught fatal during require, or a namespace that PHPUnit's discovery can't reflect, `composer test:unit` will exit 0 and print nothing. Diagnostic recipe:

```bash
# Confirm PHP can load the test file at all
php -l tests/Unit/YourTest.php

# Step through requires in a tmp script that writes progress to a file:
cat > /tmp/diag.php <<'PHP'
<?php
file_put_contents(__DIR__ . '/.diag-step', 'start');
require __DIR__ . '/vendor/autoload.php';
file_put_contents(__DIR__ . '/.diag-step', 'autoload');
require __DIR__ . '/tests/Unit/bootstrap.php';
file_put_contents(__DIR__ . '/.diag-step', 'bootstrap');
require __DIR__ . '/tests/Unit/YourTest.php';
file_put_contents(__DIR__ . '/.diag-step', 'testfile');
PHP
php /tmp/diag.php
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

## Test layering — what goes where

The suite is split across three layers, and each layer has a specific job. When picking where to put a new test, ask: **does this test require the production wire path, or is it really an integration test?**

| Layer | Lives in | Tests | Use when |
|---|---|---|---|
| Unit | `tests/Unit/` (own bootstrap) | Isolated PHP logic, no WP | Pure functions, parsing, data shape |
| Integration | `tests/test-*.php` (PHPUnit + WP_UnitTestCase) | SDK behavior in real WP | "Does this method do the right thing in PHP?" |
| E2E | `tests/e2e/tests/*.spec.ts` (Playwright + Apache + wp-cron) | Production wire path | "Does this work for a real admin in a browser, or when wp-cron actually fires?" |

### When something belongs in PHPUnit, not e2e

A test belongs in PHPUnit (faster, easier to debug) UNLESS it requires:

- **Real HTTP context.** wp-cli runs as CLI — no `$_SERVER['HTTPS']`, no headers, different output buffering. If the bug only manifests under Apache, the test must drive a real HTTP request.
- **Real wp-cron dispatch.** PHPUnit can call action handlers directly, but that bypasses wp-cron's deduplication, locking, and per-event scheduling. Use `wp cron event run --due-now` only when verifying that wp-cron actually finds and fires a registered hook.
- **JavaScript state machine.** AJAX nonce flow, popup messaging, DOM updates after click — none of these run in PHP-only tests.
- **Auth gates at HTTP time.** `current_user_can` at the AJAX handler, REST `permission_callback`, admin-page menu cap. The cap matrix on a `WP_User` is testable in PHPUnit; the HTTP-time gate is not.

### Real e2e specs in this repo

After the Phase-1 migration, `tests/e2e/tests/` only contains specs that actually require Playwright:

| Spec | Requires |
|---|---|
| `cap-enforcement.spec.ts` | Real HTTP requests as a logged-in support user, asserts wp-admin returns the `wp_die()` interstitial |
| `cron-expiration.spec.ts` | `wp cron event run --due-now` — production wp-cron dispatch path |
| `revoke-flow.spec.ts` | Same — verifies the new SaaS-revoke retry hook is wired such that wp-cron actually finds it |
| `grant-flow.spec.ts` | Real admin login → click → AJAX → DOM update |
| `revoke-flow-browser.spec.ts` | Same — drives the revoke-link nonce gate at HTTP time |
| `extend-flow-browser.spec.ts` | Same — drives existing-grant detection through the form |
| `grant-error-banner.spec.ts` | Forces SaaS 500 mid-grant, asserts user-friendly error UX + rollback (no orphan support user) |

### Common e2e pitfall — fake-e2e via `wp eval`

The pre-Phase-1 e2e suite had several specs that used `wp eval` to call SDK methods directly and assert on PHP-level state. Those are **integration tests in disguise** — Playwright is just a runner, the test never exercises the production wire path. Symptoms a spec is fake-e2e:

- It only uses `wpCli()` and never opens a `Page` (or only opens one to login).
- It would run unchanged if you replaced `wp eval` with a PHPUnit `assertEquals`.
- Failures reproduce identically when you call the same SDK method from PHPUnit.

These belong in `tests/test-*.php`. Phase-1 migrated 7 such specs back into PHPUnit; the e2e directory is honest about what it tests now.

### Browser-driven flow specs — real admin login

`helpers/login.ts::loginAsAdmin(page)` drives the actual `wp-login.php` form submission. Cookies land in the page's BrowserContext jar; subsequent navigation is authenticated.

```ts
import { test } from '@playwright/test';
import { loginAsAdmin } from './helpers/login';
import { GrantForm } from './helpers/grant-form';

test( 'admin can revoke', async ( { page } ) => {
    await loginAsAdmin( page );
    const form = new GrantForm( page );
    await form.navigate();
    await form.revokeButton().click();
    // ...
} );
```

**Don't use `tl-test-login` mu-plugin for browser-flow specs.** That helper is a cookie-jar shortcut, appropriate only when auth itself isn't what's under test (cap-enforcement uses it because the cap gate is the SUT, not the login). Real flow specs (grant/revoke/extend/error) MUST go through `wp-login.php` so they validate the production auth wire path end-to-end.

### Pre-seeding state for browser specs

`helpers/seed.ts::seedSupportUser()` mints a support user via the SDK directly through wp-cli. Browser-flow specs that start from "a grant already exists" (revoke, extend) use this instead of driving the full grant flow first — that'd couple every spec to grant-flow's stability and triple test runtime.

### Multisite gotcha — `wpmu_delete_user` load order

`wpmu_delete_user` lives in `wp-admin/includes/ms.php` which is **not** auto-loaded outside network admin context. The SDK's `function_exists('wpmu_delete_user')` returns false during admin_init dispatches like the revoke-via-URL flow, and the user record leaks into the network table even though wp_delete_user removed it from the per-site usermeta.

The fix (already applied in `SupportUser::delete()` and `Client::rollback_orphan_support_user()`):

```php
if ( is_multisite() ) {
    require_once ABSPATH . 'wp-admin/includes/ms.php';
}
```

This is the kind of bug PHPUnit can't catch — the suite runs as multisite and ms.php IS loaded by the test bootstrap. Only browser-driven e2e against admin_init exposes the load-order gap.

## Capability-enforcement layer cake

When a change touches `SupportRole` or `SupportUser`, three layers each catch a different class of bug. Skip any one and a real defect can ship green:

1. **Unit (`tests/Unit/*`)** — pins the assoc-vs-list normalization at the `Config` / `SupportRole::normalize_caps_map()` boundary, before WordPress role storage is involved.
2. **Integration (`tests/test-cap-management.php`)** — exercises the real `SupportRole::create()` against a real `WP_Role`, then confirms `$user->has_cap('edit_posts') === false` via `wp_set_current_user()`. This is the same `has_cap()` resolver every wp-admin screen uses, so when this passes the cap matrix on the live user is correct.
3. **E2E (`tests/e2e/tests/cap-enforcement.spec.ts`)** — drives an actual Apache request as a freshly-minted support user and asserts wp-admin renders the `wp_die()` interstitial. Covers role-clone scenarios for editor, administrator (with `prevented_caps` stripping), subscriber, and the role-refresh-on-second-grant path.

Don't trust the unit layer alone — the original list-shape bug passed every assoc-shape unit test and only surfaced when the integration layer drove `remove_cap()` against a real `WP_Role`.

### Cap-enforcement e2e mechanics

- Mint support users via the SDK's real classes inside `wpCli`. `(new SupportRole($cfg, $log))->create()` then `(new SupportUser($cfg, $log))->create()` returns a real `user_id`. Each test uses a unique namespace (`'capns_' + random`) so role slugs and emails don't collide across scenarios.
- A small `tl-test-login.php` mu-plugin (gated on the `TL_TEST_LOGIN_SECRET` constant — defaults to `e2e-only`) accepts `?user_id=N&k=secret` and calls `wp_set_auth_cookie()`. That's the cleanest way to put a freshly-minted user into Playwright's cookie jar without going through the full grant flow.
- Don't grep response bodies for `Sorry, you are not allowed` — that string leaks into JS l10n bundles on legitimate wp-admin screens and false-positives. Match `<title>WordPress &rsaquo; Error` instead — that's the `wp_die()` interstitial's unique signal.

### E2E debugging — visibility into failed tests

When a Playwright test fails, several artifacts are produced automatically. Use them — don't dig through test-results/ by hand.

| Tool | What it shows | When to reach for it |
|---|---|---|
| `npx playwright show-report` | HTML report with every failed test, its error, screenshot, trace links | First stop after any failed run |
| `npx playwright show-trace test-results/<test>/trace.zip` | Time-traveling debugger — every action, network request, DOM snapshot, console log | When the assertion is right but the actual behaviour is unclear |
| `cat test-results/<test>/error-context.md` | Concise failure summary auto-written by Playwright | Quick check from the terminal |
| `cat test-results/wp-debug.log` | Last 500 lines of `client-wp`\'s WordPress `debug.log`, captured by the global-teardown if any test failed | When a failure looks WordPress-side (PHP warning, hook misfire, multisite quirk) |
| `npx playwright test --headed` | Watch the browser run live | Reproducing flakes; visual confirmation |
| `PWDEBUG=1 npx playwright test <spec>` | Step through the test in Playwright Inspector | When you need to pause mid-flow and inspect state |

The global teardown only writes `wp-debug.log` when there are failure folders under `test-results/` — clean runs leave nothing behind.

### wp-cli stderr surfacing

`wpCli()` uses `spawnSync` (not `execSync`) so it can capture stderr on success too. Any non-empty stderr — typically PHP warnings/notices that don\'t cause a non-zero exit — gets forwarded to the test runner\'s stderr prefixed with `[wp-cli stderr (label)]`. That means:

- A spec that "passes" while the SDK emits a PHP deprecation surfaces the warning in CI logs and the Playwright HTML report.
- A wp-cli call that hits a `wp_die()` mid-eval still throws (non-zero exit), but a silent `Notice: undefined index` no longer hides.

Docker compose framing (`Container e2e-mariadb-1 Running`, etc.) is filtered out of both stdout and stderr so the operator sees only signal.

### E2E (browser) suite — general gotchas

These apply to any spec under `tests/e2e/`, not just cap-enforcement:

- **Docker mount staleness.** `docker compose restart <service>` does NOT pick up new volume mounts on a running container. Adding a new mu-plugin or fixture file to `docker-compose.yml` requires `docker compose up -d --force-recreate <service>`. The bind-mounted file is silently absent inside the container if you only restart — Apache returns 404 for the new endpoint with no error in any log. Symptom: a freshly-added mu-plugin endpoint returns the WordPress 404 template even though the file is on disk on the host.

- **Wordfence-leftover after a SIGKILLed run.** `compat-wordfence.spec.ts` activates Wordfence in `beforeAll` and deactivates it in `afterAll`. If a test run is killed before `afterAll` (e.g. `pkill -9 playwright` mid-debug, Ctrl-C ignored), Wordfence stays network-active. Subsequent runs hit the WAF on every request — login, AJAX, page loads each grow from <1s to ~8s, blowing past Playwright's 15s `actionTimeout` and timing out the click → navigation race in browser-driven flow specs. **Recovery:** `docker compose run --rm wp-cli-client wp plugin deactivate wordfence --network`. **Prevention:** `global-setup.ts` deactivates it defensively on every run, so this state shouldn't survive into a fresh `npx playwright test` invocation.

- **`waitForURL` predicates that are already true.** A common authoring mistake: `Promise.all([page.waitForURL(predicate), button.click()])` where the predicate matches the URL the page is *already on*. The wait resolves immediately on entry, the Promise.all resolves when the click completes, and you race ahead of the click's actual side effect (typically an AJAX-then-redirect chain that takes another 1-3s). Use `waitForResponse('**/admin-ajax.php')`, `waitForLoadState('load')`, or — simplest — assert directly on the post-click DOM state with a generous timeout.

- **`page.click` waits for "scheduled navigations" by default.** After login, WP redirects to `/wp-admin/` which loads slow widgets (admin pointers, MOTW-blocked external resources). Click waits for the load event before resolving and that can blow past `actionTimeout`. Use `click({ noWaitAfter: true })` and assert with `waitForURL` separately when only the navigation start matters.

### `caps/add` and `caps/remove` shape-normalization contract

Both settings accept either:

```php
'caps' => array( 'remove' => array( 'edit_posts' => 'reason text' ) ), // assoc
'caps' => array( 'remove' => array( 'edit_posts' ) ),                  // list
```

`SupportRole::normalize_caps_map()` reshapes list entries into `[cap_name => '']` so the key is always the cap name. Anything calling `caps/add` or `caps/remove` programmatically should funnel through this helper — the prevented-cap guard in `Config::validate()` already does, and so does the cap-merge in `SupportRole::create()`. New call sites must do the same.

### `SupportRole::create()` reconcile semantics

When the role already exists from a prior grant, `create()` no longer returns it untouched. It computes the desired cap set from the current config (clone source + `caps/add` − `prevented_caps` − `caps/remove`) and reconciles via `add_cap`/`remove_cap` so cap-config changes between grants take effect. Two implications for tests:

- The role-refresh test (`test_existing_role_is_refreshed_when_cap_config_changes`) is now a real assertion, not a documented gap.
- If clone source is missing, `create()` falls back to "return whatever existing role is there" — the historical behavior — because reconcile against a nonexistent clone is undefined. The pre-existing `test_support_user_create_role` test pins this fallback.

### `SupportUser::create()` email-collision guard

`$allow_existing_user_match = true` only flips when the configured `vendor/email` actually contains the `{hash}` placeholder. Tests that exercise this guard must use a vendor email WITHOUT `{hash}` for the collision case to fire — otherwise the guard correctly stays in opt-in mode and a "second create" silently rebinds to the existing user.

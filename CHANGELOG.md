## 1.11.0 (develop)

This release lets vendors register a webhook URL in their TrustedLogin dashboard and have customer sites pick it up automatically — no Config-array rebuild, no plugin update on the customer side. Configuring webhooks moves out of the plugin's PHP and into the dashboard.

#### 🚀 Added

- The customer site now caches the webhook URL the SaaS returns in the `POST /api/v1/sites/` response (under the option `tl_{namespace}_webhook_url`, autoload off). On every grant / extend / login_in / revoke event, the SDK fires a webhook to that cached URL — no code change, no redeploy.
- New strict sanitizer `Config::sanitize_webhook_url()` accepts only `https://` URLs, rejects URLs containing user/pass, ASCII control characters, or longer than 2,048 chars. The SaaS validates the same set at registration time so the SDK and dashboard agree on what's a valid URL.

#### 🛠 Changed

- The SDK's webhook delivery now goes through `wp_safe_remote_post` (was `wp_remote_post`), with an additional pre-flight that resolves the webhook host and refuses to deliver when it points at a private / loopback / link-local / AWS IMDS address — defense in depth against an unrelated plugin flipping `http_request_host_is_external` permissive.
- Webhook URLs are treated as bearer secrets: the four log lines emitted by the webhook delivery path now redact to host-only (path / query / fragment never appears in logs), and the `Remote::send()` debug response dump scrubs `webhookUrl` from the SaaS response body before writing.
- The `webhook/url` Config key (and its legacy `webhook_url` alias) still work but emit a one-time-per-request deprecation log encouraging integrators to remove the Config key and use the dashboard-registered URL instead. When both are set, Config wins (back-compat) and a distinct shadowing log fires so integrators can detect the override.

#### 🛡️ Security

- New CI-gating `@group security-critical` PHPUnit suite that fails fast if any of the webhook-URL log redactions regress.

#### 👨‍💻 Developer Updates

- New helper `Remote::redact_url()` returns the host portion of a URL (or `[invalid-url]` on parse failure) — useful for any integrator code that wants to surface a webhook URL in admin notices or logs without leaking the secret-bearing portion.

## 1.10.0 (May 6, 2026)

This release rewrites the failed-login feedback flow so support agents land on **their** Connector with a per-attempt SaaS record (instead of on the customer's `wp-login.php` with a generic banner), overhauls the Grant Access screen's customer-facing error messages, and fixes several long-standing capability and multisite issues.

#### 🚀 Added

- New `LoginAttempts` class that POSTs failed support logins to the TrustedLogin SaaS at `POST /api/v1/sites/{secret_id}/login-attempts`. Single-shot, 3-second hard timeout (so a slow SaaS never blocks the agent UX), no retries.
- New `Endpoint::render_standalone_failure_page()` — a generic "Support login could not complete" page rendered on the customer site when the SaaS POST can't be made or the redirect target isn't trusted. Returns HTTP 200 (browsers replace 4xx/5xx with their own error chrome).
- Per-namespace audit opt-out via the `TRUSTEDLOGIN_DISABLE_AUDIT_{NS}` constant (uppercase namespace), matching the existing `TRUSTEDLOGIN_DISABLE_{NS}` pattern.
- `Remote::send()` gains an optional 5th `$timeout` argument (default `null` → existing 15-second behaviour, back-compat with all existing callers).
- Pre-flight check on the Grant Support Access screen that detects when the plugin's support site is unreachable (firewall intercept, misconfigured installation, network failure) and replaces the grant button with a clear error banner, a "Contact support" button, and a "Try reconnecting" link.
- Post-redirect login feedback so support agents who hit an expired, revoked, or blocked access key now see a friendly explanation instead of a silent no-op landing page:
  - Success banner on wp-admin when access is granted.
  - Info notice when an already-authenticated user hits their own access link (now includes the current user's display name).
  - "Go back" link on failure screens that safely returns the agent to the vendor surface they came from.
- `postMessage` responses to the grant popup so the opening window learns when access is granted, extended, or revoked without polling.
- A hidden input on the form containing the support user's expiration date, along with that expiration in the opener message.
- New filter `trustedlogin/{namespace}/login_feedback/allowed_referer_urls` so vendors who run support from multiple domains (marketing site, help portal, white-label domains) can accept Referers from all of them. See the [Client hooks reference](https://docs.trustedlogin.com/Client/hooks).
- New filter `trustedlogin/{namespace}/webhook/request_args`, giving integrators full control over headers and body shape sent to the configured webhook URL.

#### 🛠 Changed

- Rewrites every customer-facing error message to drop internal jargon ("TrustedLogin", "vendor", "endpoint", "publicKey") and name the actionable next step. For example, "Invalid response. Missing key: publicKey" becomes "Support access could not be set up. The plugin's support team needs to finish configuring their end — please contact them and let them know."
- The `caps/add` and `caps/remove` settings now accept both list (`['edit_posts']`) and associative (`['edit_posts' => 'reason']`) array shapes. Previously the list shape silently failed to add or remove the configured capability.
- The support role is now reconciled against the current `caps` configuration on each grant. Previously, once the role had been created, subsequent changes to `caps/add` or `caps/remove` were ignored until the role was deleted.
- A second support-user creation with the same `vendor/email` now returns `WP_Error('email_exists')` unless the email contains the `{hash}` placeholder. Previously the SDK would rebind the new session to whatever user already held that address.
- `Client::revoke_access()` returns `true` once the local cleanup completes, regardless of whether the SaaS sync succeeded. Failed SaaS revocations are queued for retry by a new cron handler with linear backoff (5/10/15/20/25 minutes, capped at 5 attempts) so the SaaS-side site record eventually drops without blocking the admin's revoke action on transient SaaS errors.
- On WordPress multisite, support-user deletion now removes the user from the network user table as well as the per-site usermeta. Previously the per-site delete ran but `wpmu_delete_user()` was unavailable in the request context where revoke fires, leaving an orphan user record that collided with subsequent grants on the same vendor email.
- `Endpoint::fail_login()` is rewritten: on the `login_failed` branch it pre-captures the matched user's `site_identifier_hash` BEFORE `SupportUser::maybe_login()` deletes the expired user, derives `secret_id`, POSTs to SaaS, and on success redirects the agent back to the trusted referer with `?tl_attempt=lpat_…`. On `security_check_failed` (no user resolved), or on any failure (SaaS unreachable, untrusted referer, audit disabled, 422/429/5xx), renders the standalone fallback page instead of `wp-login.php`.
- `Endpoint::__construct` widens by two optional args (`LoginAttempts`, `SupportUser`) so the new `fail_login()` path has its dependencies wired. Existing 2-arg call sites continue to work — only the `maybe_login_support()` flow needs the new args.
- The Grant Access screen's grant button now renders as a real `<button>` element so the browser's native `disabled` attribute prevents a rapid double-click from minting two support users. Combined with a JS-side re-entrancy guard to catch synthetic-burst inputs.
- The Grant Access screen CSS is roughly 21% smaller — an inline source map was removed from the compiled stylesheet.
- Transient rows are no longer autoloaded by WordPress, so Client SDK transients no longer pay the cost of being loaded on every page request.

#### 🐛 Fixed

- A site running two TrustedLogin-enabled plugins now correctly resolves each Client instance to its own namespace. Previously `Config::ns()` used a function-static cache, so the second instance silently inherited the first's namespace and every namespaced filter, hook, option, menu, and role collapsed onto the first.
- Fixes webhook POST requests being blocked by security plugins (Wordfence, Cloudflare, Imunify360, Sucuri) as false-positive XSS. The webhook body is now JSON-encoded with a `Content-Type: application/json` header by default, avoiding the pattern that tripped those rules. Integrators whose custom receiver needs form encoding can revert via the `webhook/request_args` filter.
- Fixes a denial-of-service in the lockdown counter where distinct support-user identifiers from the same IP could trigger a site-wide lockdown. The counter is now scoped per IP.
- Fixes `Client::revoke_access( 'all' )` not returning `true` after a successful revoke loop.
- Fixes an infinite loop in `SupportUser::generate_unique_username()` when the initial username was taken.
- Fixes the revoke nonce being shared across support users. The nonce is now scoped to the specific support user identifier.

#### 🗑 Removed

- `Form::print_login_error_screen()`, `Form::get_login_feedback_html()`, `Endpoint::public_failure_messages()`, the `?tl_error=` query-param branch in `Form::maybe_print_request_screen()`, the `tl-login-feedback*` CSS, and the previous wp-login.php-override redirect path. Roughly 250 LoC.
- The old `tests/e2e/tests/login-feedback.spec.ts` (the wp-login.php override flow). Replaced by `tests/e2e/tests/login-attempts.spec.ts` covering the new SaaS-mediated round trip.

#### 🔒 Security

- `detailed_reason` is freeform internal forensic text. SaaS stores it but never returns it via the read API; the customer site's local log keeps the full text. The plaintext identifier never crosses the wire — `identifier_hash` is `sha256(site_identifier_hash)`, derived from user-meta on the customer side, not the URL parameter.
- `endpoint_mismatch` reporting is intentionally NOT wired. The existing silent no-op at `Endpoint::maybe_login_support()` line ~184 is the anti-probing defence; surfacing it would create an attacker oracle for endpoint-hash guesses.
- The post-redirect "Go back" link is hardened against phishing — the Referer host must match one of the vendor's configured URLs, and the configured URL (not the raw Referer) is what gets rendered.
- Validates the vendor public key before caching — must be exactly 64 hex characters. Adds an optional `vendor/public_key_fingerprint` Config setting for pinning the key against MITM substitution.
- Adds sensitive-header scrubbing to the debug log output (`Authorization`, `auth`, `api_key` are redacted before serialization).
- Stores a persistent random salt in `tl_{namespace}_log_salt` so log filenames are no longer guessable from `home_url()` alone.

#### 💻 Developer Updates

- Adds `Utils::delete_transient( $transient )` as the counterpart to `Utils::set_transient()`. Previously callers had to reach through the abstraction and call `delete_option()` directly.
- Adds `Remote::body_looks_like_html()` and introduces new error codes returned by `Remote::handle_response()`: `vendor_response_not_json` (firewall/CDN intercept detected), `missing_public_key` (vendor's installation not fully configured), and `unexpected_response_code` (HTTP status preserved in the error data).
- Replaces the `bool $sanitize` parameter on `Utils::get_request_param()` with a `callable $sanitize_callback` (default: `'sanitize_text_field'`). The legacy `bool` signature is still honored for back-compatibility.
- Adds a proper `require` block to `composer.json` pinning PHP 5.3+ and `ext-curl`/`ext-json`. Previously the PHP floor lived in `require-dev` and was not enforced at install time.
- Adds `composer test:unit` and `composer test:integration` scripts so the two-suite test layout has first-class entry points.
- `Ajax::__construct` now accepts an optional `Client` instance so the ajax handler can reuse the already-constructed object graph instead of rebuilding it per request.
- Removes the `postMessage` alt-scheme fallback in `trustedlogin.js` in favor of single-origin scheme-strict delivery.
- Excludes `*.css.map`, `tests/`, and dev config files from published composer dist / GitHub source archives via `.gitattributes` `export-ignore`.

## 1.9.0 (August 25, 2024)

- Added a minimum `vendor/namespace` length of five characters to help prevent collisions with other instances
- Fixed a flash of un-styled content on the Grant Access screens by outputting CSS earlier
- Addressed potential error when the `WP_Filesystem` class is not found
- Moved TrustedLogin images to inline CSS to simplify the build process
  - Removed need for `--relative_images_dir` flag in `build-sass` script
  - Removed `src/assets/loading.svg`
  - Removed `src/assets/lock.svg`
- Improved coding standards and documentation

## 1.8.0 (July 18, 2024)

- Implemented many speed enhancements
- Moved logging directory creation into own private method: `Logging::setup_logging_directory()` to clean up the `Logging::setup_klogger()` method
- Now compliant with WordPress PHPCS
- Use `gmdate()` instead of `date()` for log files and for users registration dates
- Moved `SecurityChecks::get_ip()` to `Utils::get_ip()`
- Added `Utils::get_user_agent()` to generate a user agent string with an optional max length
- Improved handling of potential errors
- Security enhancements
  - Escaped all error messages
  - Removed usage of `$_REQUEST` in favor of `$_POST` and `$_GET`
- Implemented PHPCS and PHPStan checks (thanks, [Daniel](https://code-atlantic.com))

## 1.7.0 (January 29, 2024)

- Added Utils class to handle common utility functions
- Converted usage of `get_site_transient()` and `set_site_transient()` to using `Utils::get_transient()` and `Utils::set_transient()`.
  - Scopes the storage to each blog instead of per-network, preventing potential issues with multisite
  - Fixes potential issues with object caching plugins that don't support transients, while allowing for auto-expiring data to be stored in the database
  - Prevents data from being "cleaned up" by site optimization plugins that remove expired transients

## 1.6.2 (January 26, 2024)

- Removed unnecessary request body when revoking site access
- Added index.php files to prevent directory listings
- Added check for a potential error when revoking support user

## 1.6.1 (September 22, 2023)

- Removed unnecessary payload when revoking site access
- Improved error logging:
  - Added error data to the logging, in addition to the code & message
  - Now returns the full API response when the response body is invalid
  - Switched to just-in-time creation of logging directory and log file
  - Added "Learn more" link to the logging directory `index.html`
  - Renamed the log files to `client-{namespace}-{Y-m-d}-{hash}.log` to be easier to distinguish and less verbose
- Fixed AJAX status code not being properly set when encountering an error

## 1.6.0 (September 7, 2023)

- Added `clone_role` configuration setting to allow the support user to be created with an existing role, rather than a clone of a role
- Added a `trustedlogin_{ns}_support_role` capability to the cloned support user role in order to better identify that the role is created by TrustedLogin
- Added `terms_of_service/url` setting to allow linking to a custom terms of service page
  - If not defined, the Terms of Service text and link will not be shown
- Converted CSS generation to use SCSS mixins to allow easier overrides by themes and plugins
- Removed borders around the role descriptions in the Grant Access form
- Clarified the language surrounding user roles in the Grant Access form
- Moved the admin toolbar link to next to the "Howdy, {username}" menu
  - Relabeled the link from "Revoke TrustedLogin" to "Revoke Access"
- Improved user creation flow to prevent errors when creating a user with an existing email address
- Fixed error when using PHP in strict mode
- Fixed error creating the support user when the `vendor/website` configuration exceeded 100 characters in length

## 1.5.1 (2023-04-18)

- Fixed PHP error caused by HEREDOC template formatting in `Form.php`

## 1.5.0 (2023-04-13)

- Added the ability for users to create support tickets when granting access—to enable, set `webhook/create_ticket` to `true` in the configuration array
  - Added second parameter, `$ticket_data` to `Client::grant_access()` method
  - Added `ticket` to the webhook data, with the following keys:
	- `message` (string)
- Added `Config::get_settings()` public method to get all settings
- Added `Encryption::get_remote_encryption_key_url()` public method to get the final URL used to fetch the vendor public key
- Added `Logging::get_log_file_path()` public method to get the full path to the log file
- Filtered the `$_POST` request that generates access to allow only defined fields
- Created new `Form.php` file and `Form` class to handle form rendering
  - Moved form-related methods from `Admin` to `Form`
- Modified `auth.scss` to support new ticket fields, admin debugging rendering, and improve styling

## 1.4.0 (2023-03-01)

- Added ability to send debug data, generated using the WordPress Site Health report, via webhook
- Added `Client::get_debug_data()` private method
- Modified the `webhook_url` configuration setting to be an array. Now, `webhook` is an array of `url` and `debug_data` keys
  - Passing `webhook_url` is still supported for backwards compatibility
- The SDK will no longer load on sites that lack Sodium, which is bundled with PHP 7.2+ and WordPress 5.2+, and available as a [PECL extension](https://pecl.php.net/package/libsodium) for PHP 7.0 and 7.1
- Added a public `Encryption::meets_requirements()` method to check whether the site meets the requirements for encryption
- Removed all Composer package dependencies
  - Added our own logging class
- Fixed typo in `trustedlogin/{namespace}/license_key` filter name

## 1.3.7 (2022-11-08)

- Improved styling of the authorization form
- Fixed `php-scoper` support by setting the root namespace for `\WP_Error` and `\WP_User`
- Fixed the role message always showing "similar" to a role when it was the same role
- Fix docblock to prevent Strauss from namespacing it

## 1.3.6 (2022-10-13)

- Fixed hard-coded message about the support user being created "1 day ago"
- Added missing translation hints
- Updated npm dependencies

## 1.3.5 (2022-10-12)

- Fixed rescheduling cron hooks when support access is extended

## 1.3.4 (2022-10-11)

- Changed to use `hash()` instead of `wp_hash()` for log naming; `wp_hash()` can be overridden, which is potentially insecure
- Switched to naming logs using a `sha256` hash for additional security

## 1.3.3 (2022-10-02)

- Fixed logging an error when license key configuration was undefined

## 1.3.2 (2022-09-30)

- Added `trustedlogin/{ns}/vendor/public_key/website` filter to modify the website used to fetch public key (this can be helpful when running tests)
- Added `.tl-client-grant-button` and `.tl-client-revoke-button` CSS classes to the respective buttons in the Auth screen
- Changed logging levels from `notice` to `error` when fetching the Vendor public key fails

## 1.3.1 (2022-09-21)

- Fixed PHP 8.1 warning related to performing string actions on `null`

## 1.3 (2022-08-12)

- Changed Now display the reference ID by default in both the login screen and the Grant Access screen
- Added `trustedlogin/{ns}/template/auth/display_reference` filter to control whether the reference ID is shown in the access form
- Added error handling when `SiteAccess::get_access_key()` fails

## 1.2 (2022-01-25)

- Fixed WordPress Multisite support  ([#84](https://github.com/trustedlogin/client/issues/84))
  - Also run `wpmu_delete_user()` when deleting support users
  - Use `{get|update|delete}_site_option` instead of `{get|update|delete}_option`
  - Add blog ID to hashes for unique keys and email hashes for each blog on a network
- Fixed hashing an empty string when no license was supplied
- Removed unnecessary database call by only registering endpoint when there's a valid TrustedLogin login request ([#75](https://github.com/trustedlogin/client/issues/75))
- Revoke TrustedLogin now always points to the Dashboard
  - Removed second argument from the `SupportUser::get_revoke_url()` method (`$current_url`)
- Added namespace in passed webhook data (under the key `ns`) to allow for more complex webhook functionality ([#83](https://github.com/trustedlogin/client/issues/83))

## 1.1 (2021-12-13)

- Improved admin menu configuration
  - Enhanced logic around whether and how to add a TrustedLogin menu to the sidebar depending on the `menu/slug` setting:
    - If `null`, the a top-level menu will be added.
    - If `false`, a menu item will not be added.
    - If a string, the `menu/slug` setting will be used as the `$parent_slug` argument passed to the [`add_submenu_page()` function](https://developer.wordpress.org/reference/functions/add_submenu_page/)
  - Added `menu/icon_url` setting that is used as the `$icon_url` parameter in [`add_menu_page()` function](https://developer.wordpress.org/reference/functions/add_menu_page/)
- If granting access fails, the reference ID is now passed onto the support URL using the `?ref=` query parameter
- Removed third arguments for `trustedlogin/{ns}/template/auth` and `'trustedlogin/{ns}/template/auth/footer_links` filters, since the namespace is already known
- Improved WordPress backward-compatibility by removing usage of:
  - `wp_date()` (added in WordPress 5.3); used `DateTime` instead
  - `wp_clear_scheduled_hook()` (added in WP 4.9); used `wp_clear_scheduled_hook()` instead
- Fixed filter naming: `trustedlogin/{ns}/public_key` renamed to `trustedlogin/{ns}/vendor_public_key`

## 1.0.2 (2021-10-07)

- Added `SupportUser::is_active()` method to check whether the passed user exists and has an expiration time in the future
- Added `ref` to the to `trustedlogin/{namespace}/access/extended` hook `$data` argument
- Modified some `WP_Error` error codes to be more consistent

## 1.0.1 (2021-09-27)

- Fixed issue where non-support users may see the "Revoke TrustedLogin" admin bar link

## 1.0.0 (2021-09-22)

This is the initial production release of TrustedLogin! Thank you to everyone who has worked on the project, including [Hector Kolonas](https://github.com/inztinkt), [Josh Pollock](https://github.com/Shelob9), and [Shawn Hooper](https://github.com/shawnhooper).

In addition, a deep thanks to our security auditors: James Golovich with [Pritech](https://www.pritect.net) and Ryan Dewhurst with [WPScan](https://wpscan.com).

# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

TrustedLogin Client SDK - A PHP library that enables WordPress plugin/theme developers to securely grant support access to customer sites. The SDK handles encrypted credential exchange with the TrustedLogin SaaS platform, temporary support user creation, and automatic access expiration.

## Development Commands

```bash
# Local development with wp-env
wp-env start                    # Start local WordPress environment
wp-env stop                     # Stop local environment

# Testing
npm run test:php                # Run PHPUnit tests in wp-env container
composer test                   # Run PHPUnit tests directly

# Code quality
composer lint                   # Check for linting errors (PHPCS)
composer format                 # Auto-fix linting issues (PHPCBF)
composer phpstan                # Run static analysis
```

## Architecture

### Core Flow
1. **Client** (`src/Client.php`) - Main entry point; orchestrates all components. Instantiated with a `Config` object by the integrating plugin.
2. **Config** (`src/Config.php`) - Validates and stores configuration (API keys, vendor info, decay time, capabilities).
3. **SiteAccess** (`src/SiteAccess.php`) - Manages syncing encrypted secrets to TrustedLogin SaaS.
4. **Endpoint** (`src/Endpoint.php`) - Creates the unique login endpoint URL for support access.
5. **SupportUser** (`src/SupportUser.php`) - Creates/manages the temporary WordPress admin user with custom role.

### Supporting Components
- **Encryption** (`src/Encryption.php`) - Sodium-based encryption for secure credential exchange with TrustedLogin servers.
- **Remote** (`src/Remote.php`) - HTTP communication with TrustedLogin SaaS API (`https://app.trustedlogin.com/api/v1/`).
- **Envelope** (`src/Envelope.php`) - Wraps encrypted data for transmission to SaaS.
- **SupportRole** (`src/SupportRole.php`) - Creates cloned WordPress role with modified capabilities.
- **Cron** (`src/Cron.php`) - Schedules automatic user cleanup on expiration.
- **Ajax** (`src/Ajax.php`) - Handles AJAX requests for granting/revoking access.
- **Admin** (`src/Admin.php`) - Admin UI integration.
- **Form** (`src/Form.php`) - Renders the TrustedLogin grant access form.

### Key Concepts
- **Namespace**: Each integrating plugin must define a unique namespace (5-96 chars) to avoid collisions.
- **Decay**: Access duration (default 1 week, configurable between 1-30 days).
- **Site Identifier Hash**: Unique random hash identifying each access grant.
- **Secret ID**: Combines site identifier with endpoint hash for SaaS lookup.

## Testing

Tests are in `tests/` directory with `test-` prefix. Key test files:
- `test-client.php` - Main client functionality
- `test-config.php` - Configuration validation
- `test-encryption.php` - Encryption/decryption
- `test-siteaccess.php` - SaaS sync operations
- `test-users.php` - Support user creation/deletion

The `TL_DOING_TESTS` constant must be defined for tests to run (handled by `tests/bootstrap.php`).

## Code Standards

- **PHP Compatibility**: PHP 5.3+ (uses Sodium with polyfill support for older WordPress)
- **WordPress Compatibility**: 5.2+ (4.1+ with sodium_compat)
- **Coding Standard**: WordPress Coding Standards (WPCS)
- **Namespace**: `TrustedLogin\` with PSR-4 autoloading
- **Prefixes**: `tl` or `trustedlogin` for globals

## PR Checklist

Per `pull_request_template.md`:
- PHP 5.3 compatible
- WordPress 5.2 compatible
- Version bumped in `src/Client.php` (`const VERSION`)
- CHANGELOG.md updated
- `@since TODO` in docblocks updated
- Docs updated at https://docs.trustedlogin.com/Client/intro

## Key Constants

- `TRUSTEDLOGIN_DISABLE` - Disables all TL clients globally
- `TRUSTEDLOGIN_DISABLE_{NS}` - Disables specific namespace (uppercase)
- `TL_DOING_TESTS` - Enables test mode

## Hooks Pattern

All hooks are namespaced: `trustedlogin/{namespace}/action_name`

Examples:
- `trustedlogin/{ns}/access/created`
- `trustedlogin/{ns}/access/extended`
- `trustedlogin/{ns}/access/revoked`
- `trustedlogin/{ns}/logged_in`

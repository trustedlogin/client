# Changelog for TrustedLogin Client

## 1.0.2 (2021-10-07)

- Added `SupportUser::is_active()` method to check whether the passed user exists and has an expiration time in the future.
- Added `ref` to the to `trustedlogin/{namespace}/access/extended` hook `$data` argument
- Modified some `WP_Error` error codes to be more consistent

## 1.0.1 (2021-09-27)

- Fixed issue where non-support users may see the "Revoke TrustedLogin" admin bar link.

## 1.0.0 (2021-09-22)

This is the initial production release of TrustedLogin! Thank you to everyone who has worked on the project, including [Hector Kolonas](https://github.com/inztinkt), [Josh Pollock](https://github.com/Shelob9), and [Shawn Hooper](https://github.com/shawnhooper).

In addition, a deep thanks to our security auditors: James Golovich with [Pritech](https://www.pritect.net) and Ryan Dewhurst with [WPScan](https://wpscan.com).

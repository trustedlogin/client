# TrustedLogin SDK
Easily and securely log in to your customers sites when providing support.

### [🔍 See the Changelog](https://github.com/trustedlogin/client/blob/main/CHANGELOG.md)

### [📖 Read the Documentation](https://trustedlogin.github.io/docs/Client/intro)

### Requirements:

- PHP 5.3.0 or greater
- WordPress 5.2 or greater

### Local Development And Testing

#### WordPress 4.1+ support

By default, TrustedLogin supports WordPress 5.2 or newer; this is the first version that includes the Sodium cryptography library. To support earlier versions of WordPress (version 4.1 or greater), add the following libraries to your Composer `require` definitions:

```json
"paragonie/random_compat": "<9.99",
"paragonie/sodium_compat": "^1.12"
```

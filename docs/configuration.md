# Configuration


## Logging

### Default settings:
```php
'logging' => array(
    'enabled' => false,
    'directory' => null,
    'threshold' => 'debug',
    'options' => array(),
),
```

### logging/enabled

_Optional._ Default: `false`

Whether to enable logging TrustedLogin activity to a file. Helpful for debugging.

To enable logging in TrustedLogin, the minimum configuration necessary is:

```php
'logging' => array(
    'enabled' => true,
),
```

### logging/directory

_Optional._ Default: `null`

If `null`, TrustedLogin will generate its own directory inside the `wp-uploads/` directory. The path for logs is
`/wp-uploads/trustedlogin-logs/`. Created directories are protected by an index.html file to prevent browsing.

### logging/threshold

_Optional._ Default: `debug`

This setting defines the level of logging that should be recorded.

The default setting if logging is to record all logs (`debug`).

The available options include the logging levels defined in
[PSR-3 `Psr\Log\LogLevel`](https://www.php-fig.org/psr/psr-3/#5-psrlogloglevel):

- `emergency`
- `alert`
- `critical`
- `error`
- `warning`
- `notice`
- `info`
- `debug`

Setting `logging/threshold` to `error` will record logs with the level of `error` and above (`error`, `critical`,
`alert`, and `emergency`).

### logging/options

_Optional._ Default: `[]`

This setting can be used to pass additional options to the `\Katzgrau\KLogger\Logger` class. See [the KLogger docs
for more information](https://github.com/katzgrau/KLogger#additional-options).

### Log file names

There is one log file generated per day. Log file names use a hash to make them more secure, in this format:
`trustedlogin-debug-{{Date in Y-m-d format}}-{{hash}}.log`

Example: `trustedlogin-debug-2020-07-27-39dabe12636f200938bbe8790c0aef94.log`

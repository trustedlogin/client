# trustedlogin-client
 Easily and securely log in to your customers sites when providing support.

## Including in your plugin or theme

> ### When you see ⚠️, make sure to replace with your own names!
> In the examples below, we're going to pretend your plugin or theme is named "Pro Block Builder" and your business is named Widgets, Co. These should not be the names you use—make sure to update the sample code below to match your business and plugin/theme name!

### 1. The best way: Use Composer and Strauss

- If you don't have a `composer.json` file for your plugin or theme:
    - Copy the [Sample Plugin's composer.json file](https://github.com/trustedlogin/trustedlogin-example/blob/master/composer.json)
    - Replace `name` with your business and your plugin's name (needs to be all lowercase). For example: `name: "widgets-co/pro-block-builder"`.
    - If you have a theme, replace `"type": "wordpress-plugin",` with `"type": "wordpress-theme",`
- If you have a `composer.json file` for your plugin or theme, add/merge the `repositories`, `require`, `require-dev`, `autoload`, `extra`, and `scripts` rules from the [Sample Plugin's composer.json file](https://github.com/trustedlogin/trustedlogin-example/blob/master/composer.json)
- In the `composer.json` file, in the `dep_namespace`, `dep_directory`, `classmap_directory`, and `classmap_prefix` settings, replace each "ReplaceMe" with your own namespace, like `ProBlockBuilder`. For example, `"dep_namespace": "\\ReplaceMe\\",` will become `"dep_namespace": "\\ProBlockBuilder\\",` ⚠️
- Using a command-line application (Terminal on the Mac, Windows Terminal on Windows): Change directories into your plugin or theme's folder `cd /path/to/wp/wp-content/plugins/pro-block-builder/` or `cd /path/to/wp/wp-content/themes/pro-block-builder/` ⚠️
- Run `composer install`. If you see "command not found: composer", you will need to install Composer. [Here's how to install Composer](https://getcomposer.org/doc/00-intro.md).
- In your plugin or theme, instantiate a new object using your namespace (`ProBlockBuilder`) ⚠️:
```php
// Check class_exists() for sites running PHP 5.2.x
if ( class_exists( '\ProBlockBuilder\TrustedLogin\Client') ) {
    new \ProBlockBuilder\TrustedLogin\Client( $config ); // ⚠️
}
```

### No-conflict mode

Some plugins like Gravity Forms and GravityView have a "no-conflict mode" to limit script and style conflicts. If you see
scripts and styles not loading on your Grant Support Access page, that's what's going on.

The WordPress script and style handles registered by TrustedLogin are formatted as `trustedlogin-{namespace}`.
Here's an example of how GravityView (with a namespace of `gravityview`) allows TrustedLogin scripts:

```php
add_filter( 'gravityview_noconflict_scripts', function ( $allowed_scripts = array() ) {

	$allowed_scripts[] = 'trustedlogin-gravityview'; // GravityView's namespace is `gravityview`

	return $allowed_scripts;
} );
```

## Security details

### Logging in

Every time a login occurs using a TrustedLogin link, the login is also verified by the TrustedLogin service.

In the future, the TrustedLogin service will analyze the usage patterns of access keys to ensure security.

### Lockdown mode

TrustedLogin should not generate incorrect access keys. If incorrect access keys are used to attempt a login, it may be the sign of a brute force attack on your plugin.

When TrustedLogin identifies more than 3 incorrect logins in 10 minutes, TrustedLogin enables lockdown mode for the plugin for 20 minutes.

Lockdown mode:

- Prevents all site access using the plugin's TrustedLogin link
- Notifies the TrustedLogin service of the lockdown
- Runs the `trustedlogin/{namespace}/lockdown/after` action so developers can customize behavior

#### Preventing sites from going into lockdown:

When setting up TrustedLogin on a testing site, it may be helpful to temporarily disable lockdown mode.

Security checks will automatically be disabled for `local` and `development` sites based on the value of the [`wp_get_environment_type()`](https://developer.wordpress.org/reference/functions/wp_get_environment_type/) function.

You can also define a `TRUSTEDLOGIN_TESTING_{NAMESPACE}` constant in the site's `wp-config.php` file.

## TrustedLogin Client `Config` settings

| Key | Type | Description | Default | Required? |
| --- | ---  | --- | --- | :---: |
| `auth/public_key` | `string` | The TrustedLogin key for the vendor, found in "API Keys" on https://app.trustedlogin.com. | `null` | ✅ |
| `auth/license_key` | `string`, `null` | If enabled, the license key for the current client. This is used as a lookup value when integrating with help desk support widgets. If not defined, a cryptographic hash will be generated to use as the Access Key. | `null` |
| `role` | `string` | The role to clone when creating a new Support User. | `editor` | ✅ |
| `vendor/namespace` | `string` | Slug for vendor. Must be unique. Must be shorter than 96 characters. | `null` | ✅ |
| `vendor/title` | `string` | Name of the vendor company. Used in text such as `Visit the %s website` | `null` | ✅ |
| `vendor/email` | `string` | Email address for support. Used when creating usernames. Recommended: use `{hash}` dynamic replacement ([see below](#email-hash)). | `null` | ✅ |
| `vendor/website` | `string` | URL to the vendor website. Must be a valid URL. | `null` | ✅ |
| `vendor/support_url` | `string` | URL to the vendor support page. Shown to users in the Grant Access form and also serves as a backup to redirect users if the TrustedLogin server is unreachable. Must be a valid URL. | `null` | ✅ |
| `vendor/display_name` | `string` | Optional. Display name for the support team. See "Display Name vs Title" below. | `null` | |
| `vendor/logo_url` | `string` | Optional. URL to the vendor logo. Displayed in the Grant Access form. May be inline SVG. Must be local to comply with WordPress.org. | `null` |
| `caps/add` | `array` | An array of additional capabilities to be granted to the Support User after their user role is cloned based on the `role` setting.<br><br>The key is the capability slug and the value is the reason why it is needed. Example: `[ 'gf_full_access' => 'Support will need to see and edit the forms, entries, and Gravity Forms settings on your site.' ]` | `[]` | |
| `caps/remove` | `array` | An array of capabilities you want to _remove_ from Support User. If you want to remove access to WooCommerce, for example, you could remove the `manage_woocommerce` cap by using this setting: `[ 'manage_woocommerce' => 'We don\'t need to manage your shop!' ]`. | `[]` | |
| `decay` | `int` | If defined, how long should support be granted access to the site? Defaults to a week in seconds (`604800`). Minimum: 1 day (`86400`). Maximum: 30 days (`2592000`). If `decay` is not defined, support access will not expire. | `604800` | |
| `menu/slug`| `string` | TrustedLogin adds a submenu item to the sidebar in the Dashboard. The `menu/slug` setting is the slug name for the parent menu (or the file name of a standard WordPress admin page). `$parent_slug` argument passed to the [`add_submenu_page()` function](https://developer.wordpress.org/reference/functions/add_submenu_page/). | `null` | |
| `menu/title`| `string` | The title of the submenu in the sidebar menu. | `Grant Support Access` | |
| `menu/priority` | `int` | The priority of the `admin_menu` action used by TrustedLogin.  | `100` | |
| `menu/position` | `int` | The `$position` argument passed to the [`add_submenu_page()` function](https://developer.wordpress.org/reference/functions/add_submenu_page/) function. | `null` | |
| `logging/enabled` | `bool` | If enabled, logs are stored in `wp-uploads/trustedlogin-logs` | `false` | |
| `logging/directory` | `string` | Override the directory where logs are stored. | `''` _(empty string)_ | |
| `logging/threshold` | `bool` | Define what [PSR log level](https://www.php-fig.org/psr/psr-3/#5-psrlogloglevel) should be logged. To log everything, set the threshold to `debug`.| `notice` | |
| `logging/options` | `array` | [KLogger Additional Options](https://github.com/katzgrau/klogger#additional-options) array | `['extension' => 'log', 'dateFormat' => 'Y-m-d G:i:s.u', 'filename' => null, 'flushFrequency' => false, 'logFormat' => false, 'appendContext' => true ]` ||
| `paths/css` | `string` | Where to load CSS assets from. By default, the bundled TrustedLogin CSS file will be used. Must be local to comply with WordPress.org. | `{plugin_dir_url() to Config.php}/assets/trustedlogin.css` | |
| `paths/js` | `string` | Where to load JS assets from. By default, the bundled TrustedLogin JS file will be used. Must be local to comply with WordPress.org. | `{plugin_dir_url() to Config.php}/assets/trustedlogin.js` | |
| `reassign_posts` | `bool` | When the Support User is revoked, should posts & pages be re-assigned to a site administrator? If `false`, posts and pages created by the user will be deleted. Passed as the second argument to [the `wp_delete_user()` function](https://developer.wordpress.org/reference/functions/wp_delete_user/). <br><br>When `reassign_posts` setting is enabled, TrustedLogin will attempt to assign posts created by the user to the best-guess administrator: the user with the longest-active `administrator` role.| `true` | |
| `require_ssl` | `bool` | Whether to use TrustedLogin when the site isn't served over HTTPS. TrustedLogin will still work, but the requests may not be secure. If `false`, the TrustedLogin "Grant Access" button will take users to the `vendor/support_url` URL directly. | `true` | |
| `webhook_url` | `string` | If defined, TrustedLogin will send a `POST` request to the defined URL. Must be a valid URL if defined. See the Webhooks section below. | `null` | |

## Display Name vs Title

If `vendor/title` is set to `GravityView`, the default confirmation screen will say `Grant GravityView access to your site.`

When `vendor/display_name` is also defined, the text will read `GravityView Support`, the default confirmation screen will say `Grant GravityView Support access to your site.`

## Task-specific email addresses

In order to prevent email address collision, we recommend using "plus addresses" (also called "task-specific email addresses") for your `vendor/email` setting.

Rather than `support@example.com`, use `support+{hash}@example.com`. `{hash}` will be dynamically replaced when used in
the email address.

This is supported by many email providers, including [Gmail](https://docs.microsoft.com/en-us/exchange/recipients-in-exchange-online/plus-addressing-in-exchange-online), [Microsoft](https://docs.microsoft.com/en-us/exchange/recipients-in-exchange-online/plus-addressing-in-exchange-online), [Fastmail](https://www.fastmail.com/help/receive/addressing.html), and [ProtonMail](https://protonmail.com/support/knowledge-base/creating-aliases/).

## Invalid capabilities

The Support User will be created based on the role defined in the configuration (see configuration above).

The following capabilities are never allowed when creating users through TrustedLogin, regardless of the role:

- `create_users`
- `delete_users`
- `edit_users`
- `promote_users`
- `delete_site`
- `remove_users`

A goal for TrustedLogin is to instill confidence in the end user that they are not creating security holes when granting
support access to their site.

## Webhooks

If the `webhook_url` setting is defined and a valid URL, the URL will be pinged when a Support User is created, access is extended, or access is revoked.

| Key | Type | Description |
| ---  | ---  | --- |
| `url` | `string` | The site URL from where the webhook was triggered, as returned by `get_site_url()` |
| `action` | `string` | The type of trigger: `created`, `extended`, or `revoked` |

## Logging

The most secure option is to disable logging. This should be the default, but sometimes logs are necessary.

1. TrustedLogin creates a `trustedlogin-logs` directory inside the `wp-content/uploads/` directory.
2. An empty `index.html` file is placed inside the directory to prevent browsing.
3. New log files are created daily for each TrustedLogin namespace. The default log `filename` format is `trustedlogin-debug-{date}-{hash}`.
   - `{date}` is `YYYY-MM-DD` format
   - The hash is generated using `wp_hash()` using on the `vendor/namespace`, site `home_url()`, and the day of the year (`date('z')`). The point of the hash is to make log names harder to guess (security by obscurity).

## Hooks

### `trustedlogin/{namespace}/login/refused`

Runs after the identifier fails security checks. Could be triggered for the following reasons:

- The site is in lockdown mode (brute force attacks detected)

## FAQ

### WordPress.org compliance

TrustedLogin requires user action to provide logins. This is in compliance with WordPress.org.

All files (vendor logo, CSS, and JS files) must be local (using `plugin_dir_url()` or similar) to comply with WordPress.org rules.

### If my `vendor/namespace` isn't unique, what happens?

There will be an issue generating the login screen, but it will cause no security problems. The namespace is not used in
encryption or when generating the requests to your website.

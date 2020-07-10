# TrustedLogin SDK
Easily and securely log in to your customers sites when providing support.

## Our priority: [SDKs should not crash sites](https://www.bugsnag.com/blog/sdks-should-not-crash-apps)

When you integrate TrustedLogin into your project (theme, plugin, or custom code), you are counting on us not to mess up your customer or clients' sites. We take that extremely seriously.

-------

## Including in your plugin or theme

> ### When you see ⚠️, make sure to replace with your own names!
> In the examples below, we're going to pretend your plugin or theme is named "Pro Block Builder" and your business is named Widgets, Co. These should not be the names you use—make sure to update the sample code below to match your business and plugin/theme name!

### 1. The best way: Use Composer and Mozart

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

### 2. Copy three files, and modify the namespace

1. Copy a few files where you want them in your plugin:
    - `/src/Client.php`
    - `/src/assets/js/trustedlogin.css`
    - `/src/assets/js/trustedlogin.js`
1. Edit the `Client.php` file you just copied:
    - Find `namespace TrustedLogin;`
    - Replace that twith `namespace \ProBlockBuilder\TrustedLogin;`. ⚠️ Remember: `ProBlockBuilder` is the name that represents the imaginary Pro Block Builder plugin. Replace this with a namespace that fits your own plugin!
1. Add an `include()` in your plugin or theme `require 'path/to/Client.php';`
1. Define path to your main plugin's file `define( 'PRO_BLOCK_BUILDER_FILE', __FILE__ );` ⚠️
1. Create a new configuration array URLs to your copied CSS and JS files:
```php
// Make sure to update MY_PLUGIN_FILE to use your constant name (like PRO_BLOCK_BUILDER_FILE)
$config = array(
    // [...] Other settings here
    'paths' => array(
        'css' => plugins_url( 'assets/css/my-trustedlogin.css', PRO_BLOCK_BUILDER_FILE ), // ⚠️
        'js' => plugins_url( 'assets/css/my-trustedlogin.css', PRO_BLOCK_BUILDER_FILE ), // ⚠️
    ),
    // [...] Other settings here
);
```
6. Create a new object using your namespace defined in Step 2 (`ProBlockBuilder`):
```php
// Check class_exists() for sites running PHP 5.2.x
if ( class_exists( '\ProBlockBuilder\TrustedLogin\Client') ) {
    new \ProBlockBuilder\TrustedLogin\Client( $config ); // ⚠️
}
```

## Installing JS Assets

- Change directory to this directory using `cd "path/to/dir/trustedlogin-client"`
- Run `yarn install && yarn copyfiles`

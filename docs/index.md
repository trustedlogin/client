# Getting Started

Adding TrustedLogin to your project involves:

1. Setting up an account on [trustedlogin.com](https://app.trustedlogin.com)
2. Creating a project on TrustedLogin
3. Including this client SDK ("Software Development Kit") in your code
4. Configuring the client with your TrustedLogin credentials

Let's get started!

----

## Create an Account on [TrustedLogin.com](https://app.trustedlogin.com/register)

1. Visit [TrustedLogin.com to register](https://app.trustedlogin.com/register)
1. When registering, For "Team Name", enter your project's name. You'll have the chance to add additional projects later.

![Screenshot of the registration form](https://i.gravityview.co/MRT5fQ+)

## Create a Project on TrustedLogin

1. Once logged-in to TrustedLogin's admin, click on the "Teams" link
2. On the Teams page, click on the gear icon next to your Team ![](https://i.gravityview.co/efY3gY+)
3. Fill in the details on the Team page

1. Click on

## Integrate with your plugin or theme

### **Note**: When you see ⚠️, make sure to replace with your own names!
> In the examples below, we're going to pretend your plugin or theme is named "Pro Block Builder" and your business is named Widgets, Co. These should not be the names you use—make sure to update the sample code below to match your business and plugin/theme name!

### 1. The best way: Use Composer and Mozart

- If you don't have a `composer.json` file for your plugin or theme:
    - Copy the [Example Plugin's composer.json file](https://github.com/trustedlogin/trustedlogin-example/blob/master/composer.json)
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

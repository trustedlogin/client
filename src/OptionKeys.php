<?php
/**
 * Class OptionKeys
 *
 * @package TrustedLogin\Client
 *
 * @copyright 2020 Katz Web Services, Inc.
 */
namespace TrustedLogin;

use \TrustedLogin\Config;
use TrustedLogin\Config;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

final class OptionKeys {

	/**
	 * @var Config $config
	 */
	private $config;

	/**
	 * @var string $endpoint_option The namespaced setting name for storing part of the auto-login endpoint
	 * @example 'tl_{vendor/namespace}_endpoint'
	 * @since 0.3.0
	 */
	private $endpoint_option;

	/**
	 * @var string $identifier_meta_key The namespaced setting name for storing the unique identifier hash in user meta
	 * @example tl_{vendor/namespace}_id
	 * @since 0.7.0
	 */
	private $identifier_meta_key;

	/**
	 * @var int $expires_meta_key The namespaced setting name for storing the timestamp the user expires
	 * @example tl_{vendor/namespace}_expires
	 * @since 0.7.0
	 */
	private $expires_meta_key;

	/**
	 * @var int $created_by_meta_key The ID of the user who created the TrustedLogin access
	 * @since 0.9.7
	 */
	private $created_by_meta_key;

	/**
	 * @var string $public_key_option Where the plugin should store the public key for encrypting data
	 * @since 0.5.0
	 */
	private $public_key_option;

	/**
	 * @var string $sharable_accesskey_option Where the plugin should store the shareable access key
	 * @since 0.9.2
	 */
	private $sharable_accesskey_option;


	public function __construct( Config $config ) {

		$this->config = $config;

	}

	public function init() {

		$namespace = $this->config->ns();

		/**
		 * Filter: Set endpoint setting name
		 *
		 * @since 0.3.0
		 *
		 * @param string
		 * @param Config $config
		 */
		$this->endpoint_option = apply_filters(
			'trustedlogin/' . $namespace . '/options/endpoint',
			'tl_' . $namespace . '_endpoint',
			$this->config
		);

		/**
		 * Filter: Sets the site option name for the Public Key for encryption functions
		 *
		 * @since 0.5.0
		 *
		 * @param string $public_key_option
		 * @param Config $config
		 */
		$this->public_key_option = apply_filters(
			'trustedlogin/' . $namespace . '/options/public_key',
			'tl_' . $namespace . '_public_key',
			$this->config
		);

		$this->identifier_meta_key = 'tl_' . $namespace . '_id';
		$this->expires_meta_key    = 'tl_' . $namespace . '_expires';
		$this->created_by_meta_key = 'tl_' . $namespace . '_created_by';

		/**
		 * Filter: Sets the site option name for the Shareable accessKey if it's used
		 *
		 * @since 0.9.2
		 *
		 * @param string $sharable_accesskey_option
		 * @param Config $config
		 */
		$this->sharable_accesskey_option = apply_filters(
			'trustedlogin/' . $namespace . '/options/sharable_accesskey',
			'tl_' . $namespace . '_sharable_accesskey',
			$this->config
		);
	}

	/**
	 * Magic Method: Instead of throwing an error when a variable isn't set, return null.
	 * @param  string      $name Key for the data retrieval.
	 * @return mixed|null    The stored data.
	 */
	public function __get( $name ) {
		if( isset( $this->{$name} ) ) {
			return $this->{$name};
		} else {
			return NULL;
		}
	}

}

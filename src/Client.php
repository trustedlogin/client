<?php
/**
 * The TrustedLogin drop-in class. Include this file and instantiate the class and you have secure support.
 *
 * @version 0.9.2
 * @copyright 2020 Katz Web Services, Inc.
 *
 * ###                    ###
 * ###   HEY DEVELOPER!   ###
 * ###                    ###
 * ###  (read me first)   ###
 *
 * Thanks for integrating TrustedLogin.
 *
 * 0. If you haven't already, sign up for a TrustedLogin account {@see https://www.trustedlogin.com}
 * 1. Prefix the namespace below with your own namespace (`namespace \ReplaceThisExample\TrustedLogin;`)
 * 2. Instantiate this class with a configuration array ({@see https://www.trustedlogin.com/configuration/} for more info)
 */
namespace TrustedLogin;

// Exit if accessed directly
if ( ! defined('ABSPATH') ) {
	exit;
}

use \Exception;
use \WP_Error;
use \WP_User;
use \WP_Admin_Bar;

/**
 * The TrustedLogin all-in-one drop-in class.
 */
final class Client {

	/**
	 * @var string The current drop-in file version
	 * @since 0.1.0
	 */
	const version = '0.9.6';

	/**
	 * @var Config
	 */
	private $config;

	/**
	 * @var null|Logging $logging
	 */
	private $logging;

	/**
	 * @var SupportUser $support_user
	 */
	private $support_user;

	/**
	 * @var Remote $remote
	 */
	private $remote;

	/**
	 * @var Cron $cron
	 */
	private $cron;

	/**
	 * @var Endpoint $endpoint
	 */
	private $endpoint;

	/**
	 * @var Admin $admin
	 */
	private $admin;

	/**
	 * @var Ajax
	 */
	private $ajax;

	/**
	 * @var SiteAccess $site_access
	 */
	private $site_access;

	/**
	 * @var Encryption
	 */
	private $encryption;

	/**
	 * TrustedLogin constructor.
	 *
	 * @see https://docs.trustedlogin.com/ for more information
	 *
	 * @param Config $config
	 * @param bool $init Whether to initialize everything on instantiation
	 *
	 */
	public function __construct( Config $config, $init = true ) {

		try {
			$config->validate();
		} catch ( Exception $exception ) {
			return;
		}

		$this->config = $config;

		$this->logging = new Logging( $config );

		$this->endpoint = new Endpoint( $this->config, $this->logging );

		$this->cron = new Cron( $this->config, $this->logging );

		$this->support_user = new SupportUser( $this->config, $this->logging );

		$this->admin = new Admin( $this->config, $this->logging );

		$this->ajax = new Ajax( $this->config, $this->logging );

		$this->remote = new Remote( $this->config, $this->logging );

		$this->site_access = new SiteAccess( $this->config, $this->logging );

		$this->encryption = new Encryption( $this->config, $this->remote, $this->logging );

		if ( $init ) {
			$this->init();
		}
	}

	/**
	 * Initialize all the things!
	 *
	 */
	public function init() {
		$this->admin->init();
		$this->endpoint->init();
		$this->remote->init();
		$this->cron->init();
		$this->ajax->init();
	}

}

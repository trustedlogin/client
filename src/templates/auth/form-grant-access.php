<?php
/**
 * Auth form grant access
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/auth/form-grant-access.php.
 *
 * HOWEVER, on occasion WooCommerce will need to update template files and you
 * (the theme developer) will need to copy the new files to your theme to
 * maintain compatibility. We try to do this as little as possible, but it does
 * happen. When this occurs the version of the template file will be bumped and
 * the readme will list any important changes.
 *
 * @see https://docs.woocommerce.com/document/template-structure/
 * @package WooCommerce/Templates/Auth
 * @version 2.4.0
 */

defined( 'ABSPATH' ) || exit;
?>

<?php do_action( 'trustedlogin/auth/page_header' ); ?>

<h1>
	<?php
	/* Translators: %s App name. */
	printf( esc_html__( '%s would like to connect to your store', 'trustedlogin' ), esc_html( $vendor['title'] ) );
	?>
</h1>

<?php # wc_print_notices(); ?>

<p>
	<?php
	/* Translators: %1$s App name, %2$s scope. */
	printf( esc_html__( 'This will give "%1$s" %2$s access which will allow it to:', 'trustedlogin' ), '<strong>' . esc_html( $vendor['title'] ) . '</strong>', '<strong>' . esc_html( 'REPLACEME' ) . '</strong>' );
	?>
</p>

<ul class="tl-auth-permissions">
	<?php foreach ( $permissions as $permission ) : ?>
		<li><?php echo esc_html( $permission ); ?></li>
	<?php endforeach; ?>
</ul>

<div class="tl-auth-logged-in-as">
	<?php echo get_avatar( $user->ID, 70 ); ?>
	<p>
		<?php
		/* Translators: %s display name. */
		printf( esc_html__( 'Logged in as %s', 'trustedlogin' ), esc_html( $user->display_name ) );
		?>
		<a href="<?php echo esc_url( $logout_url ); ?>" class="tl-auth-logout"><?php esc_html_e( 'Logout', 'trustedlogin' ); ?></a>
</div>

<p class="tl-auth-actions">
	<a href="<?php echo esc_url( $granted_url ); ?>" class="button button-primary tl-auth-approve"><?php esc_html_e( 'Approve', 'trustedlogin' ); ?></a>
	<a href="<?php echo esc_url( $return_url ); ?>" class="button tl-auth-deny"><?php esc_html_e( 'Deny', 'trustedlogin' ); ?></a>
</p>

<?php do_action( 'trustedlogin/auth/page_footer' ); ?>

<?php

$slug = env( 'TRUSTEDLOGIN_SLUG' );
$file_name = $slug . '.scss';

$path = rtrim( dirname( dirname( __FILE__ ) ), '/\\' ); // Path to trustedlogin-client/, untrailingslashit()
$path .= '/src/assets/src/';

file_put_contents( $path . $file_name, '$namespace: "' . $slug. '" !default;' );

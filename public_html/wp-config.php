<?php


/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'thesleqw_car');

/** Database username */
define('DB_USER', 'thesleqw_car');

/** Database password */
define('DB_PASSWORD', 'eVKw@?;^s@t#');

/** Database hostname */
define('DB_HOST', 'localhost');

/** Database charset to use in creating database tables. */
define('DB_CHARSET', 'utf8mb4');

/** The database collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY', 'n9@[zlR/vrocrZYeT&-9P:=iGduHvfJrb%AgQ!,v*FtY9/RS]+/rf^Z&Hav&,Hh,');
define('SECURE_AUTH_KEY', '/.)S677 W-hQ.fyT/!!-qTs5lEET z_WCd?-=`1%bC]*X[@s0OZ}k{5m8.KR0;G[');
define('LOGGED_IN_KEY', 'Xl#a.]|CxzYIq<~:Mz|hjoi{@PY(pi,2>Su%C0u* 8N}(U>ysZ]olO1~uCP=*Y%.');
define('NONCE_KEY', 'j=C^y`F{jK&1Up9u;),TSS@x0vsTl6?Cpx!L$,Y,,fJU=lTmn;M!^jmR:R0qavAU');
define('AUTH_SALT', '(heqixc& :,WJ| #to7]^5`D#e~R[5,{W/K[gPG@gJ/W H;1c7[9l?0?UER%`$<F');
define('SECURE_AUTH_SALT', '-YH]:L9?Mn!&hy-,/,,_8P673>RGVuclo2A|p^p1_z#&@`E@Fk%}=EYpZN6.sgQr');
define('LOGGED_IN_SALT', '7O6:`{MqY0W25 -mDT;v0R>p~YTG;0B5!*fvj<Jv{UQMWST9vQLUWyr/7JUP+@Xu');
define('NONCE_SALT', '>hvHI/9X$f,+uA]^=hMF)d&`.5O$UhNL)Du??SnBmqA7UEK !(kd]>sbmJo#Xa-`');

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'w4pMd_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */


define('WP_DEBUG', true);
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false ); // щоб не сипало на екран



/* Add any custom values between this line and the "stop editing" line. */


/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';

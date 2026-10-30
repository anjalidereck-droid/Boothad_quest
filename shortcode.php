<?php
/**
 * shortcode.php
 * =============
 * Adds the shortcode  [boothad_quest]
 *
 * Type [boothad_quest] on any page (in Elementor use the "Shortcode" widget) and
 * the game appears right there, as part of your page. No iframe, no second
 * website, no extra hosting.
 *
 * The game's files (CSS and JavaScript) are only loaded on pages that use the
 * shortcode, so the rest of your website is not slowed down.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function dq_shortcode() {
	$v = DQ_VERSION;

	wp_enqueue_style( 'dq-game', DQ_URL . 'assets/game.css', array(), $v );

	// The order matters: scenes (drawing), api (talking to WordPress), game (the brain).
	wp_enqueue_script( 'dq-scenes', DQ_URL . 'assets/scenes.js', array(), $v, true );
	wp_enqueue_script( 'dq-api', DQ_URL . 'assets/api.js', array( 'dq-scenes' ), $v, true );
	wp_enqueue_script( 'dq-game', DQ_URL . 'assets/game.js', array( 'dq-api' ), $v, true );

	// Give the JavaScript the address of our API. Works with any permalink setting.
	wp_localize_script( 'dq-api', 'DQ_CONFIG', array( 'api' => esc_url_raw( rest_url( 'boothad-quest/v1/' ) ) ) );

	ob_start();
	include DQ_DIR . 'includes/game-template.php';
	return ob_get_clean();
}
add_shortcode( 'boothad_quest', 'dq_shortcode' );

// The game used to be called "Dove Quest". This keeps any page that still has
// [dove_quest] on it working, so nothing breaks when you switch over.
add_shortcode( 'dove_quest', 'dq_shortcode' );

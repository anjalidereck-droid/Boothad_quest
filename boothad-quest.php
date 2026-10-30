<?php
/**
 * Plugin Name:       Boothad Quest
 * Description:       A castle-quest multiple choice game for children, with adaptive difficulty. Add it to any page with the shortcode [boothad_quest]. Includes a teacher area to see each child's progress and to edit the questions.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 * Text Domain:       boothad-quest
 *
 * HOW THIS PLUGIN IS ORGANISED
 * ----------------------------
 * boothad-quest.php          this file: loads everything else
 * includes/staircase.php  the adaptive difficulty rule (the "staircase")
 * includes/db.php         creates the database tables, saves questions
 * includes/rest.php       the game's API: log in, get a question, check an answer
 * includes/admin.php      the teacher screens: progress, questions, settings
 * includes/shortcode.php  the [boothad_quest] shortcode that puts the game on a page
 * assets/                 the game's JavaScript and CSS (what the child sees)
 * data/                   the sample questions
 */

// Stop anyone opening this file directly in the browser.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DQ_VERSION', '1.0.0' );
define( 'DQ_DB_VERSION', '1' );
define( 'DQ_DIR', plugin_dir_path( __FILE__ ) );
define( 'DQ_URL', plugin_dir_url( __FILE__ ) );

require_once DQ_DIR . 'includes/staircase.php';
require_once DQ_DIR . 'includes/db.php';
require_once DQ_DIR . 'includes/rest.php';
require_once DQ_DIR . 'includes/shortcode.php';
if ( is_admin() ) {
	require_once DQ_DIR . 'includes/admin.php';
}

// When the plugin is activated, create the tables and add the sample questions.
register_activation_hook( __FILE__, 'dq_install' );

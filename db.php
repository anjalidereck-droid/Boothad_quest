<?php
/**
 * db.php
 * ======
 * The database: creating the tables, reading settings, saving questions.
 *
 * WordPress already has a database. We add 5 small tables of our own. Their
 * names start with your WordPress prefix (usually "wp_") then "dq_":
 *
 *   dq_questions : the question bank (you edit this in the teacher area)
 *   dq_players   : one row per child (nickname + scrambled PIN, never a real name)
 *   dq_progress  : one row per child AND skill: level and XP
 *   dq_attempts  : one row for EVERY answer ever given (the full history)
 *   dq_rounds    : one row each time a child finishes a quest
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Full table name, for example dq_t('players') gives "wp_dq_players". */
function dq_t( $name ) {
	global $wpdb;
	return $wpdb->prefix . 'dq_' . $name;
}

/** Plugin settings with defaults (you change them in Boothad Quest > Settings). */
function dq_settings() {
	return wp_parse_args(
		get_option( 'dq_settings', array() ),
		array(
			'weekly_goal'  => 5,      // quests per week the child is encouraged to finish
			'min_think_ms' => 1500,   // answers faster than this are treated as guesses
		)
	);
}

/** The current time in UTC, in the format the database wants. */
function dq_now() {
	return gmdate( 'Y-m-d H:i:s' );
}

// ---------------------------------------------------------------------------
// Create the tables
// ---------------------------------------------------------------------------

function dq_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();

	// dbDelta() is WordPress's safe "create or update this table" tool.
	// It is fussy about layout: one column per line, and TWO spaces after PRIMARY KEY.
	dbDelta( "CREATE TABLE " . dq_t( 'questions' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  skill varchar(60) NOT NULL,
  difficulty tinyint(3) unsigned NOT NULL,
  question text NOT NULL,
  options longtext NOT NULL,
  correct_index tinyint(3) unsigned NOT NULL,
  hint text NULL,
  explanation text NULL,
  PRIMARY KEY  (id),
  KEY skill_difficulty (skill,difficulty)
) $charset;" );

	dbDelta( "CREATE TABLE " . dq_t( 'players' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  nickname varchar(30) NOT NULL,
  nickname_key varchar(30) NOT NULL,
  pin_hash varchar(255) NOT NULL,
  created_at datetime NOT NULL,
  last_seen datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY nickname_key (nickname_key)
) $charset;" );

	dbDelta( "CREATE TABLE " . dq_t( 'progress' ) . " (
  child_id bigint(20) unsigned NOT NULL,
  skill varchar(60) NOT NULL,
  level decimal(4,2) NOT NULL,
  xp int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (child_id,skill)
) $charset;" );

	dbDelta( "CREATE TABLE " . dq_t( 'attempts' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  child_id bigint(20) unsigned NOT NULL,
  question_id bigint(20) unsigned NOT NULL,
  skill varchar(60) NOT NULL,
  chosen tinyint(3) unsigned NOT NULL,
  correct tinyint(1) NOT NULL,
  time_ms int(11) NOT NULL,
  hint_used tinyint(1) NOT NULL,
  counted tinyint(1) NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY child_id (child_id),
  KEY question_id (question_id)
) $charset;" );

	dbDelta( "CREATE TABLE " . dq_t( 'rounds' ) . " (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  child_id bigint(20) unsigned NOT NULL,
  skill varchar(60) NOT NULL,
  finished_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY child_id (child_id)
) $charset;" );

	update_option( 'dq_db_version', DQ_DB_VERSION );
	add_option( 'dq_settings', array() );
	dq_seed_questions_if_empty();
}

/** If the plugin is updated, bring the tables up to date. */
function dq_maybe_upgrade() {
	if ( get_option( 'dq_db_version' ) !== DQ_DB_VERSION ) {
		dq_install();
	}
}
add_action( 'plugins_loaded', 'dq_maybe_upgrade' );

// ---------------------------------------------------------------------------
// Questions
// ---------------------------------------------------------------------------

/**
 * Check a question that came from a form or a CSV file.
 * Returns array( true, $clean_row ) or array( false, 'what is wrong' ).
 */
function dq_validate_question( $in ) {
	$skill      = trim( sanitize_text_field( $in['skill'] ?? '' ) );
	$difficulty = (int) ( $in['difficulty'] ?? 0 );
	$question   = trim( sanitize_textarea_field( $in['question'] ?? '' ) );
	$correct    = (int) ( $in['correct_index'] ?? -1 );
	$hint       = trim( sanitize_textarea_field( $in['hint'] ?? '' ) );
	$expl       = trim( sanitize_textarea_field( $in['explanation'] ?? '' ) );

	// Keep only non-empty options. Remember which one was the right answer.
	$options     = array();
	$new_correct = -1;
	foreach ( (array) ( $in['options'] ?? array() ) as $i => $opt ) {
		$opt = trim( sanitize_text_field( $opt ) );
		if ( '' === $opt ) {
			continue;
		}
		if ( (int) $i === $correct ) {
			$new_correct = count( $options );
		}
		$options[] = $opt;
	}

	if ( '' === $skill || strlen( $skill ) > 60 ) {
		return array( false, 'Skill is required (60 characters at most).' );
	}
	if ( $difficulty < 1 || $difficulty > 5 ) {
		return array( false, 'Difficulty must be from 1 to 5.' );
	}
	if ( '' === $question ) {
		return array( false, 'The question text is empty.' );
	}
	if ( count( $options ) < 2 || count( $options ) > 4 ) {
		return array( false, 'Give 2 to 4 answer options.' );
	}
	if ( $new_correct < 0 ) {
		return array( false, 'Mark which option is correct (and make sure it is not empty).' );
	}

	return array(
		true,
		array(
			'skill'         => $skill,
			'difficulty'    => $difficulty,
			'question'      => $question,
			'options'       => wp_json_encode( $options ),
			'correct_index' => $new_correct,
			'hint'          => $hint,
			'explanation'   => $expl,
		),
	);
}

/** Save a validated question. Pass an id to update, or 0 to add a new one. */
function dq_save_question( $row, $id = 0 ) {
	global $wpdb;
	if ( $id ) {
		$wpdb->update( dq_t( 'questions' ), $row, array( 'id' => $id ) );
		return $id;
	}
	$wpdb->insert( dq_t( 'questions' ), $row );
	return (int) $wpdb->insert_id;
}

/** Add the 45 sample questions the first time the plugin is activated. */
function dq_seed_questions_if_empty() {
	global $wpdb;
	if ( (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . dq_t( 'questions' ) ) > 0 ) {
		return;
	}
	$json = file_get_contents( DQ_DIR . 'data/sample-questions.json' );
	$rows = json_decode( $json, true );
	foreach ( (array) $rows as $r ) {
		$r['correct_index'] = (int) $r['correct_index'];
		// The JSON stores options as a list; validation wants indexed options + a correct index.
		list( $ok, $clean ) = dq_validate_question( $r );
		if ( $ok ) {
			dq_save_question( $clean );
		}
	}
}

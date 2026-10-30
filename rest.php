<?php
/**
 * rest.php
 * ========
 * The game's API. The game (JavaScript in the child's browser) talks to
 * WordPress through these addresses:
 *
 *   POST /wp-json/boothad-quest/v1/login          nickname + PIN, returns a token
 *   GET  /wp-json/boothad-quest/v1/skills         the map: levels, XP, weekly goal
 *   GET  /wp-json/boothad-quest/v1/next-question  pick the next question
 *   GET  /wp-json/boothad-quest/v1/hint/{id}      the free hint
 *   POST /wp-json/boothad-quest/v1/answer         check an answer, move the staircase
 *   POST /wp-json/boothad-quest/v1/round-finish   a quest ended (weekly goal)
 *
 * LOGIN: children do NOT get WordPress accounts. They pick a nickname and a
 * 4-digit PIN. After login the server hands back a signed TOKEN. The browser
 * sends it in the "X-DQ-Token" header with every request, and the server reads
 * the child's id FROM THE TOKEN. A child cannot pretend to be another child.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Tokens
// ---------------------------------------------------------------------------

function dq_b64url( $s ) {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

function dq_b64url_decode( $s ) {
	return base64_decode( strtr( $s, '-_', '+/' ) );
}

/** Make a signed token that says "this is player N" and lasts 30 days. */
function dq_token_make( $player_id ) {
	$payload = dq_b64url( wp_json_encode( array( 'cid' => (int) $player_id, 'exp' => time() + 30 * DAY_IN_SECONDS ) ) );
	$sig     = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	return $payload . '.' . $sig;
}

/** Check a token. Returns the player id, or 0 if it is forged, expired or unknown. */
function dq_token_verify( $token ) {
	if ( ! is_string( $token ) || false === strpos( $token, '.' ) ) {
		return 0;
	}
	list( $payload, $sig ) = explode( '.', $token, 2 );
	$expected              = hash_hmac( 'sha256', $payload, wp_salt( 'auth' ) );
	if ( ! hash_equals( $expected, $sig ) ) {
		return 0;
	}
	$data = json_decode( dq_b64url_decode( $payload ), true );
	if ( ! is_array( $data ) || empty( $data['cid'] ) || empty( $data['exp'] ) || $data['exp'] < time() ) {
		return 0;
	}
	global $wpdb;
	$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . dq_t( 'players' ) . ' WHERE id = %d', $data['cid'] ) );
	return $exists ? (int) $exists : 0;
}

/**
 * Used as the "permission_callback" of every route except login.
 * Stores the player id in a global so the route can use it.
 */
function dq_api_auth( WP_REST_Request $request ) {
	$id = dq_token_verify( (string) $request->get_header( 'x_dq_token' ) );
	if ( ! $id ) {
		return new WP_Error( 'dq_auth', 'Please log in again.', array( 'status' => 401 ) );
	}
	$GLOBALS['dq_player_id'] = $id;
	return true;
}

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

add_action(
	'rest_api_init',
	function () {
		$ns = 'boothad-quest/v1';
		register_rest_route( $ns, '/login', array( 'methods' => 'POST', 'callback' => 'dq_api_login', 'permission_callback' => '__return_true' ) );
		register_rest_route( $ns, '/skills', array( 'methods' => 'GET', 'callback' => 'dq_api_skills', 'permission_callback' => 'dq_api_auth' ) );
		register_rest_route( $ns, '/next-question', array( 'methods' => 'GET', 'callback' => 'dq_api_next_question', 'permission_callback' => 'dq_api_auth' ) );
		register_rest_route( $ns, '/hint/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => 'dq_api_hint', 'permission_callback' => 'dq_api_auth' ) );
		register_rest_route( $ns, '/answer', array( 'methods' => 'POST', 'callback' => 'dq_api_answer', 'permission_callback' => 'dq_api_auth' ) );
		register_rest_route( $ns, '/round-finish', array( 'methods' => 'POST', 'callback' => 'dq_api_round_finish', 'permission_callback' => 'dq_api_auth' ) );
	}
);

// Game data must never be cached (by the browser, LiteSpeed, a CDN...).
add_filter(
	'rest_post_dispatch',
	function ( $result, $server, $request ) {
		if ( 0 === strpos( $request->get_route(), '/boothad-quest/' ) ) {
			$result->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		}
		return $result;
	},
	10,
	3
);

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** The player's progress row for a skill. Created on first use. */
function dq_progress_row( $player_id, $skill ) {
	global $wpdb;
	$t   = dq_t( 'progress' );
	$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE child_id = %d AND skill = %s", $player_id, $skill ) );
	if ( ! $row ) {
		$wpdb->insert( $t, array( 'child_id' => $player_id, 'skill' => $skill, 'level' => DQ_START_LEVEL, 'xp' => 0 ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE child_id = %d AND skill = %s", $player_id, $skill ) );
	}
	return $row;
}

/** A question for the browser. The correct answer is NOT included. */
function dq_question_for_browser( $row ) {
	return array(
		'id'         => (int) $row->id,
		'skill'      => $row->skill,
		'difficulty' => (int) $row->difficulty,
		'text'       => $row->question,
		'options'    => json_decode( $row->options, true ),
	);
}

/** From a list of questions pick one closest to the target difficulty, avoiding $exclude if possible. */
function dq_choose_question( $rows, $target, $exclude ) {
	$pool = array_values( array_filter( $rows, function ( $r ) use ( $exclude ) {
		return ! in_array( (int) $r->id, $exclude, true );
	} ) );
	if ( ! $pool ) {
		$pool = $rows;   // everything was excluded: allow repeats
	}
	if ( ! $pool ) {
		return null;
	}
	$closest = min( array_map( function ( $r ) use ( $target ) {
		return abs( (int) $r->difficulty - $target );
	}, $pool ) );
	$best    = array_values( array_filter( $pool, function ( $r ) use ( $target, $closest ) {
		return abs( (int) $r->difficulty - $target ) === $closest;
	} ) );
	return $best[ array_rand( $best ) ];
}

function dq_touch_player( $player_id ) {
	global $wpdb;
	$wpdb->update( dq_t( 'players' ), array( 'last_seen' => dq_now() ), array( 'id' => $player_id ) );
}

// ---------------------------------------------------------------------------
// Route: login
// ---------------------------------------------------------------------------

function dq_api_login( WP_REST_Request $request ) {
	global $wpdb;

	// Stop PIN guessing: at most 15 tries per minute from one address.
	$ip_key = 'dq_login_' . md5( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'unknown' );
	$tries  = (int) get_transient( $ip_key );
	if ( $tries >= 15 ) {
		return new WP_Error( 'dq_rate', 'Too many tries. Please wait a minute.', array( 'status' => 429 ) );
	}
	set_transient( $ip_key, $tries + 1, MINUTE_IN_SECONDS );

	$data = $request->get_json_params();
	$name = trim( (string) ( $data['name'] ?? '' ) );
	$pin  = (string) ( $data['pin'] ?? '' );

	if ( ! preg_match( '/^[A-Za-z0-9 _-]{2,20}$/', $name ) ) {
		return new WP_Error( 'dq_name', 'Nickname: 2 to 20 letters or numbers. Please do not use your real name.', array( 'status' => 400 ) );
	}
	if ( ! preg_match( '/^\d{4}$/', $pin ) ) {
		return new WP_Error( 'dq_pin', 'PIN must be exactly 4 digits.', array( 'status' => 400 ) );
	}

	$t      = dq_t( 'players' );
	$key    = strtolower( $name );
	$player = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE nickname_key = %s", $key ) );

	if ( ! $player ) {
		// New player: store only a scrambled (hashed) PIN.
		$wpdb->insert( $t, array(
			'nickname'     => $name,
			'nickname_key' => $key,
			'pin_hash'     => password_hash( $pin, PASSWORD_DEFAULT ),
			'created_at'   => dq_now(),
			'last_seen'    => dq_now(),
		) );
		$player = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE id = %d", $wpdb->insert_id ) );
	} elseif ( ! password_verify( $pin, $player->pin_hash ) ) {
		return new WP_Error( 'dq_login', 'That nickname is taken, or the PIN is wrong.', array( 'status' => 401 ) );
	}

	dq_touch_player( $player->id );
	return array( 'token' => dq_token_make( $player->id ), 'name' => $player->nickname );
}

// ---------------------------------------------------------------------------
// Route: the map
// ---------------------------------------------------------------------------

function dq_api_skills() {
	global $wpdb;
	$pid    = $GLOBALS['dq_player_id'];
	$skills = $wpdb->get_col( 'SELECT DISTINCT skill FROM ' . dq_t( 'questions' ) . ' ORDER BY skill' );

	$out      = array();
	$total_xp = 0;
	foreach ( $skills as $skill ) {
		$prog      = dq_progress_row( $pid, $skill );
		$level     = (float) $prog->level;
		$total_xp += (int) $prog->xp;
		$out[]     = array(
			'skill'       => $skill,
			'level'       => dq_display_level( $level ),
			'level_exact' => round( $level, 2 ),
			'mastery'     => dq_mastery_percent( $level ),
			'xp'          => (int) $prog->xp,
		);
	}

	$since  = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$weekly = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . dq_t( 'rounds' ) . ' WHERE child_id = %d AND finished_at >= %s', $pid, $since ) );

	return array(
		'skills'        => $out,
		'total_xp'      => $total_xp,
		'weekly_rounds' => $weekly,
		'weekly_goal'   => (int) dq_settings()['weekly_goal'],
		'max_level'     => (int) DQ_MAX_LEVEL,
	);
}

// ---------------------------------------------------------------------------
// Route: next question
// ---------------------------------------------------------------------------

function dq_api_next_question( WP_REST_Request $request ) {
	global $wpdb;
	$pid           = $GLOBALS['dq_player_id'];
	$skill         = (string) $request->get_param( 'skill' );
	$kind          = (string) $request->get_param( 'kind' );
	$preview_stage = (int) $request->get_param( 'preview_stage' );
	$exclude       = array_filter( array_map( 'intval', explode( ',', (string) $request->get_param( 'exclude' ) ) ) );
	$q             = dq_t( 'questions' );

	// Repair quest: questions the child's LATEST attempt got wrong.
	if ( 'repair' === $kind ) {
		$a    = dq_t( 'attempts' );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT q.* FROM $q q WHERE q.id IN (
				SELECT x.question_id FROM $a x
				WHERE x.child_id = %d
				  AND x.id = (SELECT MAX(y.id) FROM $a y WHERE y.child_id = x.child_id AND y.question_id = x.question_id)
				  AND x.correct = 0)",
			$pid
		) );
		$rows = array_values( array_filter( $rows, function ( $r ) use ( $exclude ) {
			return ! in_array( (int) $r->id, $exclude, true );
		} ) );
		if ( ! $rows ) {
			return array( 'none' => true );
		}
		return dq_question_for_browser( $rows[ array_rand( $rows ) ] );
	}

	if ( $preview_stage ) {
		$target = max( 1, min( (int) DQ_MAX_LEVEL, $preview_stage ) );
	} else {
		$target = dq_display_level( (float) dq_progress_row( $pid, $skill )->level );
	}

	$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $q WHERE skill = %s", $skill ) );
	$chosen = dq_choose_question( $rows, $target, $exclude );
	return $chosen ? dq_question_for_browser( $chosen ) : array( 'none' => true );
}

// ---------------------------------------------------------------------------
// Route: hint
// ---------------------------------------------------------------------------

function dq_api_hint( WP_REST_Request $request ) {
	global $wpdb;
	$hint = $wpdb->get_var( $wpdb->prepare( 'SELECT hint FROM ' . dq_t( 'questions' ) . ' WHERE id = %d', (int) $request['id'] ) );
	return array( 'hint' => (string) $hint );
}

// ---------------------------------------------------------------------------
// Route: check an answer (the heart of the game)
// ---------------------------------------------------------------------------

function dq_api_answer( WP_REST_Request $request ) {
	global $wpdb;
	$pid  = $GLOBALS['dq_player_id'];
	$data = $request->get_json_params();

	$question_id = (int) ( $data['question_id'] ?? 0 );
	$chosen      = isset( $data['chosen'] ) ? (int) $data['chosen'] : -1;
	$time_ms     = max( 0, (int) ( $data['time_ms'] ?? 0 ) );
	$hint_used   = ! empty( $data['hint_used'] ) ? 1 : 0;
	$is_repair   = ( $data['kind'] ?? '' ) === 'repair';
	$is_preview  = ! empty( $data['preview'] );

	$qt = dq_t( 'questions' );
	$q  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $qt WHERE id = %d", $question_id ) );
	if ( ! $q || $chosen < 0 ) {
		return new WP_Error( 'dq_bad', 'Bad request.', array( 'status' => 400 ) );
	}

	// 1. Right or wrong?
	$correct = ( $chosen === (int) $q->correct_index );

	// A preview (demo) answer shows the result but saves nothing.
	if ( $is_preview ) {
		return array(
			'correct'       => $correct,
			'correct_index' => (int) $q->correct_index,
			'explanation'   => (string) $q->explanation,
			'counted'       => true,
			'xp_gained'     => 0,
			'total_xp'      => 0,
			'level'         => (int) $q->difficulty,
			'level_exact'   => null,
			'level_change'  => 'same',
		);
	}

	// 2. Staircase (skipped for suspiciously fast answers: probably a guess).
	$prog      = dq_progress_row( $pid, $q->skill );
	$old_level = (float) $prog->level;
	$counted   = $time_ms >= (int) dq_settings()['min_think_ms'];
	$new_level = $counted ? dq_update_level( $old_level, $correct ) : $old_level;

	// 3. XP
	$xp = 0;
	if ( $counted ) {
		$xp = $correct ? ( 10 + 2 * (int) $q->difficulty ) : 2;
		if ( $is_repair && $correct ) {
			$xp *= 2;
		}
	}

	// 4. Save.
	$wpdb->update( dq_t( 'progress' ), array( 'level' => $new_level, 'xp' => (int) $prog->xp + $xp ), array( 'child_id' => $pid, 'skill' => $q->skill ) );
	$wpdb->insert( dq_t( 'attempts' ), array(
		'child_id'    => $pid,
		'question_id' => $question_id,
		'skill'       => $q->skill,
		'chosen'      => $chosen,
		'correct'     => $correct ? 1 : 0,
		'time_ms'     => $time_ms,
		'hint_used'   => $hint_used,
		'counted'     => $counted ? 1 : 0,
		'created_at'  => dq_now(),
	) );
	dq_touch_player( $pid );

	$total_xp = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(xp),0) FROM ' . dq_t( 'progress' ) . ' WHERE child_id = %d', $pid ) );

	// 5. Reply.
	$old_shown = dq_display_level( $old_level );
	$new_shown = dq_display_level( $new_level );
	$change    = $new_shown > $old_shown ? 'up' : ( $new_shown < $old_shown ? 'down' : 'same' );

	return array(
		'correct'       => $correct,
		'correct_index' => (int) $q->correct_index,
		'explanation'   => (string) $q->explanation,
		'counted'       => $counted,
		'xp_gained'     => $xp,
		'total_xp'      => $total_xp,
		'level'         => $new_shown,
		'level_exact'   => round( $new_level, 2 ),
		'level_change'  => $change,
	);
}

// ---------------------------------------------------------------------------
// Route: a quest ended
// ---------------------------------------------------------------------------

function dq_api_round_finish( WP_REST_Request $request ) {
	global $wpdb;
	$data  = $request->get_json_params();
	$skill = substr( sanitize_text_field( (string) ( $data['skill'] ?? 'repair' ) ), 0, 60 );
	$wpdb->insert( dq_t( 'rounds' ), array( 'child_id' => $GLOBALS['dq_player_id'], 'skill' => $skill, 'finished_at' => dq_now() ) );
	return array( 'ok' => true );
}

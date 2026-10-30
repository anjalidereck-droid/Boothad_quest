<?php
/**
 * admin.php
 * =========
 * The TEACHER AREA, inside your normal WordPress dashboard (wp-admin).
 * Only logged-in WordPress administrators can see it.
 *
 *   Boothad Quest > Overview   how everyone is doing, and the most-missed questions
 *   Boothad Quest > Players    every child, then one child's full progress
 *   Boothad Quest > Questions  add, edit, delete, import and export questions
 *   Boothad Quest > Settings   weekly goal and the "too fast" guess limit
 *
 * Every button that changes something checks two things first:
 *   1. you are an administrator            (current_user_can)
 *   2. the request really came from this page (a "nonce" security code)
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Menu
// ---------------------------------------------------------------------------

add_action( 'admin_menu', 'dq_admin_menu' );
function dq_admin_menu() {
	add_menu_page( 'Boothad Quest', 'Boothad Quest', 'manage_options', 'boothad-quest', 'dq_page_overview', 'dashicons-shield', 58 );
	add_submenu_page( 'boothad-quest', 'Overview', 'Overview', 'manage_options', 'boothad-quest', 'dq_page_overview' );
	add_submenu_page( 'boothad-quest', 'Players', 'Players', 'manage_options', 'dq-players', 'dq_page_players' );
	add_submenu_page( 'boothad-quest', 'Questions', 'Questions', 'manage_options', 'dq-questions', 'dq_page_questions' );
	add_submenu_page( 'boothad-quest', 'Settings', 'Settings', 'manage_options', 'dq-settings', 'dq_page_settings' );
}

// ---------------------------------------------------------------------------
// Small helpers
// ---------------------------------------------------------------------------

/** Stop anyone who is not an administrator. */
function dq_require_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You do not have permission to do this.', 403 );
	}
}

/** Go back to one of our pages with a message in the address. */
function dq_redirect( $page, $args = array() ) {
	wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
	exit;
}

/** Show a green or red message that was passed in the address. */
function dq_notice() {
	if ( ! empty( $_GET['dq_ok'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dq_ok'] ) ) ) . '</p></div>';
	}
	if ( ! empty( $_GET['dq_err'] ) ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['dq_err'] ) ) ) . '</p></div>';
	}
}

/** A date from the database (UTC) shown in the website's own time zone. */
function dq_show_date( $utc ) {
	if ( ! $utc ) {
		return '';
	}
	return esc_html( get_date_from_gmt( $utc, 'j M Y H:i' ) );
}

function dq_pct( $right, $total ) {
	return $total > 0 ? round( 100 * $right / $total ) . '%' : '&ndash;';
}

function dq_stage_name( $n ) {
	$names = array( 1 => 'The Gate', 2 => 'The Courtyard', 3 => 'The Fire Ramparts', 4 => 'The Goblin Hall', 5 => "The Dragon's Lair" );
	return $names[ $n ] ?? '';
}

function dq_admin_styles() {
	echo '<style>
	.dq-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:12px;margin:16px 0}
	.dq-card{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:14px 16px}
	.dq-card b{display:block;font-size:26px;line-height:1.2}
	.dq-card span{color:#50575e}
	.dq-wrap table.widefat td,.dq-wrap table.widefat th{vertical-align:top}
	.dq-bad{color:#b32d2e;font-weight:600}.dq-good{color:#00763a;font-weight:600}
	.dq-form td,.dq-form th{padding:6px 10px 6px 0;vertical-align:top;text-align:left}
	.dq-form input[type=text],.dq-form textarea,.dq-form select{width:100%;max-width:560px}
	.dq-box{background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:12px 16px;margin:16px 0}
	</style>';
}

// ---------------------------------------------------------------------------
// OVERVIEW
// ---------------------------------------------------------------------------

function dq_page_overview() {
	dq_require_admin();
	global $wpdb;
	$p = dq_t( 'players' );
	$a = dq_t( 'attempts' );
	$r = dq_t( 'rounds' );
	$q = dq_t( 'questions' );
	$g = dq_t( 'progress' );

	$since        = gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$players      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $p" );
	$active       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT child_id) FROM $a WHERE created_at >= %s", $since ) );
	$attempts     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $a WHERE counted = 1" );
	$right        = (int) $wpdb->get_var( "SELECT COALESCE(SUM(correct),0) FROM $a WHERE counted = 1" );
	$quests_week  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $r WHERE finished_at >= %s", $since ) );
	$questions    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $q" );

	echo '<div class="wrap dq-wrap"><h1>Boothad Quest: overview</h1>';
	dq_admin_styles();
	dq_notice();

	echo '<div class="dq-cards">';
	foreach ( array(
		array( $players, 'children registered' ),
		array( $active, 'active in the last 7 days' ),
		array( $quests_week, 'quests finished this week' ),
		array( $attempts, 'answers given' ),
		array( dq_pct( $right, $attempts ), 'answers correct' ),
		array( $questions, 'questions in the bank' ),
	) as $c ) {
		echo '<div class="dq-card"><b>' . wp_kses_post( $c[0] ) . '</b><span>' . esc_html( $c[1] ) . '</span></div>';
	}
	echo '</div>';

	// Per skill: how many children, their average stage, how accurate.
	$skills = $wpdb->get_results(
		"SELECT g.skill, COUNT(*) AS kids, AVG(g.level) AS avg_level, SUM(g.xp) AS xp
		 FROM $g g WHERE g.xp > 0 GROUP BY g.skill ORDER BY g.skill"
	);
	echo '<h2>Skills</h2>';
	if ( ! $skills ) {
		echo '<p>No answers yet. Once children play, their progress appears here.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:720px"><thead><tr><th>Skill</th><th>Children playing</th><th>Average stage</th><th>Accuracy</th></tr></thead><tbody>';
		foreach ( $skills as $s ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) n, COALESCE(SUM(correct),0) r FROM $a WHERE skill = %s AND counted = 1", $s->skill ) );
			printf(
				'<tr><td>%s</td><td>%d</td><td>%.1f of 5</td><td>%s</td></tr>',
				esc_html( $s->skill ),
				(int) $s->kids,
				(float) $s->avg_level,
				wp_kses_post( dq_pct( (int) $row->r, (int) $row->n ) )
			);
		}
		echo '</tbody></table>';
	}

	// The questions children miss most (at least 3 answers each).
	$missed = $wpdb->get_results(
		"SELECT q.id, q.skill, q.difficulty, q.question, COUNT(*) AS n, SUM(a.correct) AS r
		 FROM $a a JOIN $q q ON q.id = a.question_id
		 WHERE a.counted = 1
		 GROUP BY q.id, q.skill, q.difficulty, q.question
		 HAVING COUNT(*) >= 3
		 ORDER BY SUM(a.correct) * 1.0 / COUNT(*) ASC, COUNT(*) DESC
		 LIMIT 10"
	);
	echo '<h2>Most-missed questions</h2>';
	echo '<p>These are the questions to teach, or to check for mistakes. Click one to edit it.</p>';
	if ( ! $missed ) {
		echo '<p>Nothing yet. A question needs at least 3 answers to appear here.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Skill</th><th>Stage</th><th>Question</th><th>Answered</th><th>Correct</th></tr></thead><tbody>';
		foreach ( $missed as $m ) {
			$edit = admin_url( 'admin.php?page=dq-questions&action=edit&id=' . (int) $m->id );
			printf(
				'<tr><td>%s</td><td>%d</td><td><a href="%s">%s</a></td><td>%d</td><td class="dq-bad">%s</td></tr>',
				esc_html( $m->skill ),
				(int) $m->difficulty,
				esc_url( $edit ),
				esc_html( wp_trim_words( $m->question, 16 ) ),
				(int) $m->n,
				wp_kses_post( dq_pct( (int) $m->r, (int) $m->n ) )
			);
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

// ---------------------------------------------------------------------------
// PLAYERS
// ---------------------------------------------------------------------------

function dq_page_players() {
	dq_require_admin();
	if ( ! empty( $_GET['player'] ) ) {
		dq_page_player_detail( (int) $_GET['player'] );
		return;
	}
	global $wpdb;
	$p = dq_t( 'players' );
	$a = dq_t( 'attempts' );
	$g = dq_t( 'progress' );
	$r = dq_t( 'rounds' );

	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
	$where  = '';
	if ( '' !== $search ) {
		$where = $wpdb->prepare( 'WHERE p.nickname LIKE %s', '%' . $wpdb->esc_like( $search ) . '%' );
	}
	$rows = $wpdb->get_results(
		"SELECT p.id, p.nickname, p.created_at, p.last_seen,
		   (SELECT COALESCE(SUM(xp),0) FROM $g WHERE child_id = p.id) AS xp,
		   (SELECT COUNT(*) FROM $a WHERE child_id = p.id AND counted = 1) AS attempts,
		   (SELECT COALESCE(SUM(correct),0) FROM $a WHERE child_id = p.id AND counted = 1) AS right_count,
		   (SELECT COUNT(*) FROM $r WHERE child_id = p.id) AS quests
		 FROM $p p $where ORDER BY p.last_seen DESC LIMIT 300"
	);

	echo '<div class="wrap dq-wrap"><h1>Boothad Quest: players</h1>';
	dq_admin_styles();
	dq_notice();
	echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="dq-players">'
		. '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="Search nickname"> '
		. '<button class="button">Search</button></form>';

	if ( ! $rows ) {
		echo '<p>No children yet. They appear here after they log in on your game page.</p></div>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Nickname</th><th>Joined</th><th>Last active</th><th>XP</th><th>Answers</th><th>Accuracy</th><th>Quests</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$url = admin_url( 'admin.php?page=dq-players&player=' . (int) $row->id );
		printf(
			'<tr><td><a href="%s"><b>%s</b></a></td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%s</td><td>%d</td><td><a class="button button-small" href="%s">View progress</a></td></tr>',
			esc_url( $url ),
			esc_html( $row->nickname ),
			dq_show_date( $row->created_at ),
			dq_show_date( $row->last_seen ),
			(int) $row->xp,
			(int) $row->attempts,
			wp_kses_post( dq_pct( (int) $row->right_count, (int) $row->attempts ) ),
			(int) $row->quests,
			esc_url( $url )
		);
	}
	echo '</tbody></table></div>';
}

function dq_page_player_detail( $player_id ) {
	global $wpdb;
	$p      = dq_t( 'players' );
	$a      = dq_t( 'attempts' );
	$g      = dq_t( 'progress' );
	$q      = dq_t( 'questions' );
	$player = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $p WHERE id = %d", $player_id ) );

	echo '<div class="wrap dq-wrap">';
	dq_admin_styles();
	if ( ! $player ) {
		echo '<h1>Player not found</h1><a href="' . esc_url( admin_url( 'admin.php?page=dq-players' ) ) . '">Back to players</a></div>';
		return;
	}
	echo '<h1>' . esc_html( $player->nickname ) . '</h1>';
	echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=dq-players' ) ) . '">&larr; All players</a> &nbsp; Joined ' . dq_show_date( $player->created_at ) . ' &middot; Last active ' . dq_show_date( $player->last_seen ) . '</p>';
	dq_notice();

	// Per skill: stage reached and accuracy. This shows the weak subjects.
	$skills = $wpdb->get_results( $wpdb->prepare(
		"SELECT g.skill, g.level, g.xp,
		   (SELECT COUNT(*) FROM $a WHERE child_id = g.child_id AND skill = g.skill AND counted = 1) AS n,
		   (SELECT COALESCE(SUM(correct),0) FROM $a WHERE child_id = g.child_id AND skill = g.skill AND counted = 1) AS r,
		   (SELECT COALESCE(AVG(time_ms),0) FROM $a WHERE child_id = g.child_id AND skill = g.skill AND counted = 1) AS avg_ms
		 FROM $g g WHERE g.child_id = %d ORDER BY g.skill",
		$player_id
	) );
	echo '<h2>Progress by skill</h2><table class="widefat striped" style="max-width:820px"><thead><tr><th>Skill</th><th>Stage reached</th><th>XP</th><th>Answers</th><th>Accuracy</th><th>Average time</th></tr></thead><tbody>';
	foreach ( $skills as $s ) {
		$stage = dq_display_level( (float) $s->level );
		printf(
			'<tr><td>%s</td><td>%d: %s</td><td>%d</td><td>%d</td><td>%s</td><td>%s</td></tr>',
			esc_html( $s->skill ),
			$stage,
			esc_html( dq_stage_name( $stage ) ),
			(int) $s->xp,
			(int) $s->n,
			wp_kses_post( dq_pct( (int) $s->r, (int) $s->n ) ),
			$s->n ? esc_html( round( $s->avg_ms / 1000, 1 ) . ' s' ) : '&ndash;'
		);
	}
	echo '</tbody></table>';

	// Questions this child keeps getting wrong: material for the next lesson.
	$weak = $wpdb->get_results( $wpdb->prepare(
		"SELECT q.id, q.skill, q.question, q.options, q.correct_index, COUNT(*) AS n, SUM(a.correct) AS r
		 FROM $a a JOIN $q q ON q.id = a.question_id
		 WHERE a.child_id = %d AND a.counted = 1
		 GROUP BY q.id, q.skill, q.question, q.options, q.correct_index
		 HAVING SUM(a.correct) < COUNT(*)
		 ORDER BY SUM(a.correct) * 1.0 / COUNT(*) ASC, COUNT(*) DESC LIMIT 10",
		$player_id
	) );
	echo '<h2>Questions to revisit</h2>';
	if ( ! $weak ) {
		echo '<p>No mistakes yet.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>Skill</th><th>Question</th><th>Right answer</th><th>Correct</th></tr></thead><tbody>';
		foreach ( $weak as $w ) {
			$opts = json_decode( $w->options, true );
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td class="dq-bad">%d of %d</td></tr>',
				esc_html( $w->skill ),
				esc_html( wp_trim_words( $w->question, 18 ) ),
				esc_html( $opts[ (int) $w->correct_index ] ?? '' ),
				(int) $w->r,
				(int) $w->n
			);
		}
		echo '</tbody></table>';
	}

	// The last 40 answers, with what the child chose.
	$recent = $wpdb->get_results( $wpdb->prepare(
		"SELECT a.*, q.question, q.options, q.correct_index FROM $a a LEFT JOIN $q q ON q.id = a.question_id
		 WHERE a.child_id = %d ORDER BY a.id DESC LIMIT 40",
		$player_id
	) );
	echo '<h2>Recent answers</h2>';
	if ( ! $recent ) {
		echo '<p>No answers yet.</p>';
	} else {
		echo '<table class="widefat striped"><thead><tr><th>When</th><th>Skill</th><th>Question</th><th>Chose</th><th>Result</th><th>Time</th><th>Hint</th></tr></thead><tbody>';
		foreach ( $recent as $x ) {
			$opts   = json_decode( (string) $x->options, true );
			$chose  = $opts[ (int) $x->chosen ] ?? '';
			$result = $x->correct ? '<span class="dq-good">Correct</span>' : '<span class="dq-bad">Wrong</span>';
			if ( ! $x->counted ) {
				$result .= ' <em>(too fast, ignored)</em>';
			}
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s s</td><td>%s</td></tr>',
				dq_show_date( $x->created_at ),
				esc_html( $x->skill ),
				$x->question ? esc_html( wp_trim_words( $x->question, 14 ) ) : '<em>(deleted question)</em>',
				esc_html( $chose ),
				wp_kses_post( $result ),
				esc_html( round( $x->time_ms / 1000, 1 ) ),
				$x->hint_used ? 'yes' : ''
			);
		}
		echo '</tbody></table>';
	}

	// Housekeeping: forgotten PIN, or remove the child completely.
	echo '<div class="dq-box"><h2 style="margin-top:0">Manage this child</h2>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-bottom:12px">';
	wp_nonce_field( 'dq_reset_pin' );
	echo '<input type="hidden" name="action" value="dq_reset_pin"><input type="hidden" name="player" value="' . (int) $player_id . '">';
	echo 'Set a new PIN: <input type="text" name="pin" maxlength="4" size="6" pattern="\d{4}" placeholder="4 digits" required> <button class="button">Change PIN</button></form>';
	$del = wp_nonce_url( admin_url( 'admin-post.php?action=dq_delete_player&player=' . (int) $player_id ), 'dq_delete_player_' . (int) $player_id );
	echo '<a class="button" style="color:#b32d2e;border-color:#b32d2e" href="' . esc_url( $del ) . '" onclick="return confirm(\'Delete this child and ALL their progress? This cannot be undone.\');">Delete this child and all their data</a>';
	echo '</div></div>';
}

add_action( 'admin_post_dq_reset_pin', function () {
	dq_require_admin();
	check_admin_referer( 'dq_reset_pin' );
	global $wpdb;
	$id  = (int) ( $_POST['player'] ?? 0 );
	$pin = (string) ( $_POST['pin'] ?? '' );
	if ( ! preg_match( '/^\d{4}$/', $pin ) ) {
		dq_redirect( 'dq-players', array( 'player' => $id, 'dq_err' => 'The PIN must be exactly 4 digits.' ) );
	}
	$wpdb->update( dq_t( 'players' ), array( 'pin_hash' => password_hash( $pin, PASSWORD_DEFAULT ) ), array( 'id' => $id ) );
	dq_redirect( 'dq-players', array( 'player' => $id, 'dq_ok' => 'PIN changed.' ) );
} );

add_action( 'admin_post_dq_delete_player', function () {
	dq_require_admin();
	$id = (int) ( $_GET['player'] ?? 0 );
	check_admin_referer( 'dq_delete_player_' . $id );
	global $wpdb;
	foreach ( array( 'progress', 'attempts', 'rounds' ) as $table ) {
		$wpdb->delete( dq_t( $table ), array( 'child_id' => $id ) );
	}
	$wpdb->delete( dq_t( 'players' ), array( 'id' => $id ) );
	dq_redirect( 'dq-players', array( 'dq_ok' => 'Child deleted.' ) );
} );

// ---------------------------------------------------------------------------
// QUESTIONS
// ---------------------------------------------------------------------------

function dq_page_questions() {
	dq_require_admin();
	if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] ) {
		dq_page_question_form( (int) ( $_GET['id'] ?? 0 ) );
		return;
	}
	global $wpdb;
	$q = dq_t( 'questions' );
	$a = dq_t( 'attempts' );

	$skill_filter = isset( $_GET['skill'] ) ? sanitize_text_field( wp_unslash( $_GET['skill'] ) ) : '';
	$diff_filter  = isset( $_GET['difficulty'] ) ? (int) $_GET['difficulty'] : 0;

	$where = array( '1=1' );
	if ( '' !== $skill_filter ) {
		$where[] = $wpdb->prepare( 'q.skill = %s', $skill_filter );
	}
	if ( $diff_filter ) {
		$where[] = $wpdb->prepare( 'q.difficulty = %d', $diff_filter );
	}
	$rows = $wpdb->get_results(
		"SELECT q.*, COUNT(a.id) AS asked, COALESCE(SUM(a.correct),0) AS right_count
		 FROM $q q LEFT JOIN $a a ON a.question_id = q.id AND a.counted = 1
		 WHERE " . implode( ' AND ', $where ) . "
		 GROUP BY q.id, q.skill, q.difficulty, q.question, q.options, q.correct_index, q.hint, q.explanation
		 ORDER BY q.skill, q.difficulty, q.id LIMIT 500"
	);
	$all_skills = $wpdb->get_col( "SELECT DISTINCT skill FROM $q ORDER BY skill" );

	echo '<div class="wrap dq-wrap"><h1 class="wp-heading-inline">Boothad Quest: questions</h1> ';
	echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=dq-questions&action=edit&id=0' ) ) . '">Add new question</a><hr class="wp-header-end">';
	dq_admin_styles();
	dq_notice();

	echo '<form method="get" style="margin:12px 0"><input type="hidden" name="page" value="dq-questions">Skill: <select name="skill"><option value="">All</option>';
	foreach ( $all_skills as $s ) {
		echo '<option value="' . esc_attr( $s ) . '"' . selected( $skill_filter, $s, false ) . '>' . esc_html( $s ) . '</option>';
	}
	echo '</select> Stage: <select name="difficulty"><option value="0">All</option>';
	for ( $i = 1; $i <= 5; $i++ ) {
		echo '<option value="' . $i . '"' . selected( $diff_filter, $i, false ) . '>' . $i . ': ' . esc_html( dq_stage_name( $i ) ) . '</option>';
	}
	echo '</select> <button class="button">Filter</button></form>';

	echo '<p>' . count( $rows ) . ' question(s). <strong>Stage</strong> is the difficulty: 1 is easiest (the gate), 5 is hardest (the dragon). The game picks a question whose stage matches the child.</p>';

	echo '<table class="widefat striped"><thead><tr><th>ID</th><th>Skill</th><th>Stage</th><th>Question</th><th>Right answer</th><th>Asked</th><th>Correct</th><th>Check</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$opts  = json_decode( $row->options, true );
		$asked = (int) $row->asked;
		$pct   = $asked ? 100 * $row->right_count / $asked : null;
		$flag  = '';
		if ( $asked >= 8 && null !== $pct ) {
			if ( $pct >= 95 ) {
				$flag = 'Almost everyone gets it: maybe too easy for this stage';
			} elseif ( $pct <= 30 ) {
				$flag = 'Almost nobody gets it: very hard, or the answer is wrong';
			}
		}
		$edit = admin_url( 'admin.php?page=dq-questions&action=edit&id=' . (int) $row->id );
		$del  = wp_nonce_url( admin_url( 'admin-post.php?action=dq_delete_question&id=' . (int) $row->id ), 'dq_delete_question_' . (int) $row->id );
		printf(
			'<tr><td>%d</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td><a href="%s">Edit</a> | <a href="%s" style="color:#b32d2e" onclick="return confirm(\'Delete this question?\');">Delete</a></td></tr>',
			(int) $row->id,
			esc_html( $row->skill ),
			(int) $row->difficulty,
			esc_html( wp_trim_words( $row->question, 18 ) ),
			esc_html( $opts[ (int) $row->correct_index ] ?? '' ),
			$asked,
			wp_kses_post( dq_pct( (int) $row->right_count, $asked ) ),
			esc_html( $flag ),
			esc_url( $edit ),
			esc_url( $del )
		);
	}
	echo '</tbody></table>';

	// Import and export.
	echo '<div class="dq-box"><h2 style="margin-top:0">Import or export questions (spreadsheet)</h2>';
	echo '<p>Export gives you a CSV file you can open in Excel or Google Sheets. Edit it or add rows, then import it back. '
		. 'Columns, in this order: <code>skill, difficulty, question, option1, option2, option3, option4, correct, hint, explanation</code>. '
		. '<code>difficulty</code> is 1 to 5 and <code>correct</code> is 1 to 4 (which option is right). Importing <strong>adds</strong> questions; it never deletes.</p>';
	echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dq_export' ), 'dq_export' ) ) . '">Download all questions (CSV)</a> ';
	echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-left:12px">';
	wp_nonce_field( 'dq_import' );
	echo '<input type="hidden" name="action" value="dq_import"><input type="file" name="csv" accept=".csv" required> <button class="button button-primary">Import CSV</button></form></div>';
	echo '</div>';
}

function dq_page_question_form( $id ) {
	global $wpdb;
	$row = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . dq_t( 'questions' ) . ' WHERE id = %d', $id ) ) : null;
	if ( $id && ! $row ) {
		wp_die( 'Question not found.' );
	}
	$opts = $row ? json_decode( $row->options, true ) : array( '', '', '', '' );
	$opts = array_pad( $opts, 4, '' );
	$all  = $wpdb->get_col( 'SELECT DISTINCT skill FROM ' . dq_t( 'questions' ) . ' ORDER BY skill' );

	echo '<div class="wrap dq-wrap"><h1>' . ( $id ? 'Edit question' : 'Add a question' ) . '</h1>';
	dq_admin_styles();
	dq_notice();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dq-form">';
	wp_nonce_field( 'dq_save_question' );
	echo '<input type="hidden" name="action" value="dq_save_question"><input type="hidden" name="id" value="' . (int) $id . '"><table>';

	echo '<tr><th>Skill</th><td><input type="text" name="skill" list="dq-skills" maxlength="60" required value="' . esc_attr( $row->skill ?? '' ) . '"><datalist id="dq-skills">';
	foreach ( $all as $s ) {
		echo '<option value="' . esc_attr( $s ) . '">';
	}
	echo '</datalist><br><small>The topic, for example Fractions. Use the same spelling for the same topic.</small></td></tr>';

	echo '<tr><th>Stage (difficulty)</th><td><select name="difficulty">';
	for ( $i = 1; $i <= 5; $i++ ) {
		echo '<option value="' . $i . '"' . selected( (int) ( $row->difficulty ?? 1 ), $i, false ) . '>' . $i . ': ' . esc_html( dq_stage_name( $i ) ) . '</option>';
	}
	echo '</select></td></tr>';

	echo '<tr><th>Question</th><td><textarea name="question" rows="3" required>' . esc_textarea( $row->question ?? '' ) . '</textarea></td></tr>';

	for ( $i = 0; $i < 4; $i++ ) {
		printf(
			'<tr><th>Answer %d</th><td><input type="text" name="options[%d]" value="%s"> <label><input type="radio" name="correct_index" value="%d"%s> this is the correct answer</label></td></tr>',
			$i + 1,
			$i,
			esc_attr( $opts[ $i ] ),
			$i,
			checked( (int) ( $row->correct_index ?? 0 ), $i, false )
		);
	}
	echo '<tr><th></th><td><small>Fill in at least 2 answers. Leave unused ones empty.</small></td></tr>';
	echo '<tr><th>Hint</th><td><textarea name="hint" rows="2">' . esc_textarea( $row->hint ?? '' ) . '</textarea><br><small>Teaches the idea without giving away the answer. Always free for the child.</small></td></tr>';
	echo '<tr><th>Explanation</th><td><textarea name="explanation" rows="2">' . esc_textarea( $row->explanation ?? '' ) . '</textarea><br><small>Shown after answering, so a wrong answer still teaches something.</small></td></tr>';
	echo '</table><p><button class="button button-primary">Save question</button> <a class="button" href="' . esc_url( admin_url( 'admin.php?page=dq-questions' ) ) . '">Cancel</a></p></form></div>';
}

add_action( 'admin_post_dq_save_question', function () {
	dq_require_admin();
	check_admin_referer( 'dq_save_question' );
	$id = (int) ( $_POST['id'] ?? 0 );
	list( $ok, $result ) = dq_validate_question( wp_unslash( $_POST ) );
	if ( ! $ok ) {
		dq_redirect( 'dq-questions', array( 'action' => 'edit', 'id' => $id, 'dq_err' => $result ) );
	}
	dq_save_question( $result, $id );
	dq_redirect( 'dq-questions', array( 'dq_ok' => $id ? 'Question updated.' : 'Question added.' ) );
} );

add_action( 'admin_post_dq_delete_question', function () {
	dq_require_admin();
	$id = (int) ( $_GET['id'] ?? 0 );
	check_admin_referer( 'dq_delete_question_' . $id );
	global $wpdb;
	$wpdb->delete( dq_t( 'questions' ), array( 'id' => $id ) );
	dq_redirect( 'dq-questions', array( 'dq_ok' => 'Question deleted.' ) );
} );

add_action( 'admin_post_dq_export', function () {
	dq_require_admin();
	check_admin_referer( 'dq_export' );
	global $wpdb;
	$rows = $wpdb->get_results( 'SELECT * FROM ' . dq_t( 'questions' ) . ' ORDER BY skill, difficulty, id' );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="boothad-quest-questions.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" );   // lets Excel read accents correctly
	fputcsv( $out, array( 'skill', 'difficulty', 'question', 'option1', 'option2', 'option3', 'option4', 'correct', 'hint', 'explanation' ) );
	foreach ( $rows as $r ) {
		$o = array_pad( json_decode( $r->options, true ), 4, '' );
		fputcsv( $out, array( $r->skill, $r->difficulty, $r->question, $o[0], $o[1], $o[2], $o[3], (int) $r->correct_index + 1, $r->hint, $r->explanation ) );
	}
	fclose( $out );
	exit;
} );

add_action( 'admin_post_dq_import', function () {
	dq_require_admin();
	check_admin_referer( 'dq_import' );

	if ( empty( $_FILES['csv']['tmp_name'] ) || ! is_uploaded_file( $_FILES['csv']['tmp_name'] ) ) {
		dq_redirect( 'dq-questions', array( 'dq_err' => 'No file received.' ) );
	}
	if ( $_FILES['csv']['size'] > 1024 * 1024 ) {
		dq_redirect( 'dq-questions', array( 'dq_err' => 'File too big (1 MB at most).' ) );
	}
	$name = isset( $_FILES['csv']['name'] ) ? sanitize_file_name( wp_unslash( $_FILES['csv']['name'] ) ) : '';
	if ( 'csv' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
		dq_redirect( 'dq-questions', array( 'dq_err' => 'Please upload a .csv file.' ) );
	}

	$h      = fopen( $_FILES['csv']['tmp_name'], 'r' );
	$added  = 0;
	$errors = array();
	$line   = 0;
	while ( ( $cells = fgetcsv( $h ) ) !== false ) {
		$line++;
		if ( 1 === $line ) {
			$cells[0] = preg_replace( '/^\xEF\xBB\xBF/', '', (string) $cells[0] );   // remove the Excel marker
			if ( 'skill' === strtolower( trim( $cells[0] ) ) ) {
				continue;   // header row
			}
		}
		if ( count( array_filter( $cells, 'strlen' ) ) === 0 ) {
			continue;       // empty line
		}
		$cells = array_pad( $cells, 10, '' );
		list( $ok, $result ) = dq_validate_question( array(
			'skill'         => $cells[0],
			'difficulty'    => $cells[1],
			'question'      => $cells[2],
			'options'       => array( $cells[3], $cells[4], $cells[5], $cells[6] ),
			'correct_index' => (int) $cells[7] - 1,
			'hint'          => $cells[8],
			'explanation'   => $cells[9],
		) );
		if ( $ok ) {
			dq_save_question( $result );
			$added++;
		} elseif ( count( $errors ) < 3 ) {
			$errors[] = 'Line ' . $line . ': ' . $result;
		}
	}
	fclose( $h );

	$args = array( 'dq_ok' => $added . ' question(s) imported.' );
	if ( $errors ) {
		$args['dq_err'] = 'Some rows were skipped. ' . implode( ' ', $errors );
	}
	dq_redirect( 'dq-questions', $args );
} );

// ---------------------------------------------------------------------------
// SETTINGS
// ---------------------------------------------------------------------------

function dq_page_settings() {
	dq_require_admin();
	$s = dq_settings();
	echo '<div class="wrap dq-wrap"><h1>Boothad Quest: settings</h1>';
	dq_admin_styles();
	dq_notice();
	echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dq-form">';
	wp_nonce_field( 'dq_save_settings' );
	echo '<input type="hidden" name="action" value="dq_save_settings"><table>';
	echo '<tr><th>Weekly goal (quests)</th><td><input type="number" min="1" max="50" name="weekly_goal" value="' . (int) $s['weekly_goal'] . '" style="width:90px"></td></tr>';
	echo '<tr><th>Ignore answers faster than (milliseconds)</th><td><input type="number" min="0" max="10000" step="100" name="min_think_ms" value="' . (int) $s['min_think_ms'] . '" style="width:90px"><br><small>A very fast answer is probably a guess, so it does not move the child\'s level. 1500 means 1.5 seconds.</small></td></tr>';
	echo '</table><p><button class="button button-primary">Save settings</button></p></form>';
	echo '<div class="dq-box"><h2 style="margin-top:0">How to show the game</h2><p>Create or edit a page and add the shortcode <code>[boothad_quest]</code>. In Elementor, drag the <strong>Shortcode</strong> widget onto the page and type it there.</p></div></div>';
}

add_action( 'admin_post_dq_save_settings', function () {
	dq_require_admin();
	check_admin_referer( 'dq_save_settings' );
	update_option( 'dq_settings', array(
		'weekly_goal'  => max( 1, min( 50, (int) ( $_POST['weekly_goal'] ?? 5 ) ) ),
		'min_think_ms' => max( 0, min( 10000, (int) ( $_POST['min_think_ms'] ?? 1500 ) ) ),
	) );
	dq_redirect( 'dq-settings', array( 'dq_ok' => 'Settings saved.' ) );
} );

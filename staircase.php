<?php
/**
 * staircase.php
 * =============
 * The adaptive "staircase" and nothing else.
 *
 * Every child has a hidden LEVEL for each skill (1.0 to 5.0).
 *   Correct answer: the level goes UP by a small step.
 *   Wrong answer:   the level goes DOWN by a bigger step.
 *
 * Because the two steps differ, the level settles where the child gets a fixed
 * share of answers right:
 *
 *     success rate = STEP_DOWN / ( STEP_UP + STEP_DOWN )
 *
 * With 0.25 and 0.75 that is 0.75 / 1.00 = 75%. This is the "weighted up-down"
 * method (Kaernbach, 1991). About 75% correct stretches a child without
 * discouraging them.
 *
 * The level becomes the STAGE of the castle (1 Gate ... 5 Dragon).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const DQ_MIN_LEVEL   = 1.0;
const DQ_MAX_LEVEL   = 5.0;
const DQ_START_LEVEL = 1.0;
const DQ_STEP_UP     = 0.25;
const DQ_STEP_DOWN   = 0.75;

/** The new level after an answer. */
function dq_update_level( $level, $was_correct ) {
	$level += $was_correct ? DQ_STEP_UP : -DQ_STEP_DOWN;
	return max( DQ_MIN_LEVEL, min( DQ_MAX_LEVEL, $level ) );
}

/** The whole-number stage a child sees (2.75 becomes 3). */
function dq_display_level( $level ) {
	return (int) floor( $level + 0.5 );
}

/** Level 1 to 5 as a percentage for the mastery ring. */
function dq_mastery_percent( $level ) {
	return (int) round( ( $level - DQ_MIN_LEVEL ) / ( DQ_MAX_LEVEL - DQ_MIN_LEVEL ) * 100 );
}

<?php
/**
 * game-template.php
 * =================
 * The HTML of the game. The shortcode prints this onto your page.
 * It holds three screens (login, map, quest) and only one shows at a time.
 * game.js fills in the names, questions and numbers, and scenes.js draws the
 * pictures on the <canvas>.
 *
 * Every id starts with "dq-" so it can never clash with your WordPress theme.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="dq-root" class="dq-root dq-mode-adventure">

	<div class="dq-header">
		<div class="dq-brand">&#128330;&#65039; Boothad Quest</div>
		<label class="dq-mode-picker">Mode
			<select id="dq-mode-select">
				<option value="adventure">Adventure (timer and hearts)</option>
				<option value="calm">Calm (no timer, no hearts lost)</option>
				<option value="exam">Exam (plain, no pictures)</option>
			</select>
		</label>
	</div>

	<!-- 1. LOGIN -->
	<section id="dq-login-screen" class="dq-screen dq-panel">
		<h2>Save the princess!</h2>
		<p>Pick a nickname and a 4-digit PIN. <strong>Please do not use your real name.</strong>
			New nickname? We make you a hero. Old nickname? Type the same PIN to carry on.</p>
		<label>Nickname
			<input id="dq-name-input" type="text" maxlength="20" autocomplete="off" placeholder="e.g. BraveFalcon">
		</label>
		<label>PIN (4 digits)
			<input id="dq-pin-input" type="password" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="&bull;&bull;&bull;&bull;">
		</label>
		<button id="dq-login-btn" type="button" class="dq-btn dq-primary">Start the quest</button>
		<p id="dq-login-error" class="dq-error" role="alert"></p>
	</section>

	<!-- 2. MAP -->
	<section id="dq-map-screen" class="dq-screen dq-hidden">
		<div class="dq-panel">
			<h2 id="dq-greeting"></h2>
			<div class="dq-stats">
				<div class="dq-stat"><span id="dq-total-xp">0</span> XP</div>
				<div class="dq-stat">Weekly goal: <span id="dq-weekly-text"></span>
					<div class="dq-bar"><div id="dq-weekly-fill" class="dq-bar-fill"></div></div>
				</div>
			</div>
		</div>

		<h3 class="dq-light">Choose your quest</h3>
		<div id="dq-skill-list" class="dq-skill-list"></div>

		<div class="dq-panel dq-small">
			<button id="dq-repair-btn" type="button" class="dq-btn dq-secondary">&#128295; Fix my old mistakes</button>
			<label class="dq-preview">Preview a stage (for testing, saves nothing)
				<select id="dq-preview-select">
					<option value="0">No, play for real</option>
					<option value="1">Stage 1: The Gate</option>
					<option value="2">Stage 2: The Courtyard</option>
					<option value="3">Stage 3: The Fire Ramparts</option>
					<option value="4">Stage 4: The Goblin Hall</option>
					<option value="5">Stage 5: The Dragon's Lair</option>
				</select>
			</label>
			<p id="dq-map-message" class="dq-error"></p>
			<button id="dq-logout-btn" type="button" class="dq-btn dq-link">Not you? Switch hero</button>
		</div>
	</section>

	<!-- 3. QUEST -->
	<section id="dq-quiz-screen" class="dq-screen dq-hidden">

		<div id="dq-scene-wrap" class="dq-scene-wrap">
			<canvas id="dq-scene" aria-label="Castle scene. Pick the arrow with the right answer to shoot it."></canvas>
		</div>

		<div id="dq-question-panel" class="dq-panel">
			<div class="dq-quiz-top">
				<span id="dq-quiz-title"></span>
				<span id="dq-quiz-count"></span>
			</div>
			<h3 id="dq-question-text"></h3>
			<p id="dq-goal" class="dq-goal"></p>

			<!-- game.js puts one arrow here for each answer -->
			<div id="dq-options" class="dq-quiver"></div>

			<button id="dq-hint-btn" type="button" class="dq-btn dq-secondary">&#128161; Hint (free)</button>
			<p id="dq-hint-text" class="dq-hint dq-hidden"></p>

			<div id="dq-feedback" class="dq-feedback dq-hidden">
				<p id="dq-feedback-title"></p>
				<p id="dq-feedback-explain"></p>
				<p id="dq-feedback-xp"></p>
			</div>

			<div class="dq-row">
				<button id="dq-next-btn" type="button" class="dq-btn dq-primary dq-hidden">Next</button>
				<button id="dq-rest-btn" type="button" class="dq-btn dq-link">Rest at camp (stop)</button>
			</div>
		</div>

		<div id="dq-end-panel" class="dq-panel dq-hidden">
			<h2 id="dq-end-title"></h2>
			<div id="dq-stars" class="dq-stars"></div>
			<p id="dq-end-text"></p>
			<p id="dq-end-xp"></p>
			<div class="dq-row">
				<button id="dq-again-btn" type="button" class="dq-btn dq-primary">Play again</button>
				<button id="dq-back-btn" type="button" class="dq-btn dq-secondary">Back to the map</button>
			</div>
		</div>

	</section>
</div>

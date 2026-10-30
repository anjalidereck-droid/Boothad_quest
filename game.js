/*
  game.js
  =======
  The game's brain, running in the child's browser. It:
    - shows and hides the three screens (login, map, quest)
    - shows each question as a set of ARROWS and plays the shooting animation
    - runs the danger timer, the hearts and the win or retreat endings
    - sends answers to WordPress and shows the result

  Read it top to bottom: settings, helpers, login, map, quest, game loop.

  HOW THE STAIRCASE BECOMES A GAME
  --------------------------------
  WordPress keeps a hidden "level" for each child and skill (1.0 to 5.0). We turn
  it into the STAGE of the castle:
      1 The Gate -> 2 The Courtyard -> 3 The Fire Ramparts
      -> 4 The Goblin Hall -> 5 The Dragon's Lair -> the princess!
  Right answers move the prince along the track, wrong answers move him back.
  Beat the dragon (3 correct dragon questions) to save the princess.

  Everything is inside one function, so nothing here can clash with your theme.
*/

(function () {
  "use strict";

  const root = document.getElementById("dq-root");
  if (!root) return;                                   // the shortcode is not on this page

  // -------------------------------------------------------------------------
  // 1. SETTINGS AND STATE
  // -------------------------------------------------------------------------

  const ROUND_LENGTH = 6;           // questions in a "fix mistakes" quest or an exam round
  const DRAGON_HITS_NEEDED = 3;     // correct dragon questions needed to win
  const PREVIEW_WINS_NEEDED = 3;    // correct answers to finish a stage preview
  const MAX_HEARTS = 3;

  const $ = (id) => document.getElementById("dq-" + id);       // shortcut: $("login-btn")
  const clamp = (v, lo, hi) => Math.max(lo, Math.min(hi, v));
  const lerp = (a, b, t) => a + (b - a) * t;

  const canvas = $("scene");
  const ctx = canvas.getContext("2d");
  canvas.width = DQScenes.W;
  canvas.height = DQScenes.H;

  // "state" = what the QUEST is doing (questions, score, hearts).
  const state = {
    name: "",
    levels: {},          // skill -> exact staircase level, from the server
    skill: null,
    kind: "normal",      // "normal" (staircase) or "repair" (old mistakes)
    preview: 0,          // 0 = real play, 1 to 5 = demo of that stage
    seen: [],            // question ids asked in this quest
    asked: 0, correctCount: 0, xpThisQuest: 0,
    dragonHits: 0,       // correct dragon answers so far
    previewWins: 0,
    hearts: MAX_HEARTS,
    levelExact: 1,
    current: null,       // the question on screen
    shownAt: 0,          // when it appeared (for thinking time)
    hintUsed: false,
    // phase: idle | loading | question | waiting | shooting | feedback | hit | over
    phase: "idle",
    pendingEnd: null,    // "win" or "done"...: what the NEXT click should end
    lastStage: 0,
  };

  // "world" = what the SCENE is doing (the danger, the arrow, the effects).
  const world = {
    t: 0,
    dangerP: 0,          // 0 = the danger just appeared, 1 = it reaches the prince
    dangerVisible: true,
    shot: null,          // the prince's arrow in flight
    pull: 0,             // bow being drawn
    doorOpen: 0,
    dragon: { hp: DRAGON_HITS_NEEDED, fall: 0, flash: 0 },
    fx: [], popups: [],
    banner: null,
    shake: 0, hitFlash: 0, fade: 0,
    outcome: null,       // null | "win" | "lose"
    seq: null,           // the animation being played (see runSequence)
  };

  // -------------------------------------------------------------------------
  // 2. MODES
  // -------------------------------------------------------------------------
  // adventure : the danger creeps closer and wrong arrows cost a heart
  // calm      : same pictures, but no timer and no hearts (for children who find
  //             a ticking clock stressful)
  // exam      : no pictures, no feedback, no XP, like the real paper
  // Saved in the browser; a later version should save it per child on the server.

  const getMode = () => (root.classList.contains("dq-mode-exam") ? "exam" : root.classList.contains("dq-mode-calm") ? "calm" : "adventure");
  const isExam = () => getMode() === "exam";
  const isCalm = () => getMode() === "calm";

  function applyMode(mode) {
    root.classList.remove("dq-mode-adventure", "dq-mode-calm", "dq-mode-exam");
    root.classList.add("dq-mode-" + mode);
    $("mode-select").value = mode;
    dqStore.set("dq_mode", mode);
    $("scene-wrap").classList.toggle("dq-hidden", mode === "exam");
    if (state.current) updateGoal();
  }
  $("mode-select").addEventListener("change", (e) => applyMode(e.target.value));

  // -------------------------------------------------------------------------
  // 3. SCREENS
  // -------------------------------------------------------------------------

  function showScreen(name) {
    for (const id of ["login-screen", "map-screen", "quiz-screen"]) {
      $(id).classList.toggle("dq-hidden", id !== name);
    }
  }

  // -------------------------------------------------------------------------
  // 4. LOGIN
  // -------------------------------------------------------------------------

  async function login() {
    $("login-error").textContent = "";
    DQApi.token = null;
    const result = await DQApi.call("login", { name: $("name-input").value.trim(), pin: $("pin-input").value });
    if (result.error) { $("login-error").textContent = result.error; return; }

    DQApi.token = result.token;
    state.name = result.name;
    dqStore.set("dq_token", result.token);
    dqStore.set("dq_name", result.name);
    $("pin-input").value = "";
    loadMap();
  }

  function logout(message) {
    DQApi.token = null;
    dqStore.remove("dq_token");
    dqStore.remove("dq_name");
    state.phase = "idle";
    $("login-error").textContent = message || "";
    showScreen("login-screen");
  }

  DQApi.onLoggedOut = () => logout("Please log in again.");
  $("login-btn").addEventListener("click", login);
  $("pin-input").addEventListener("keydown", (e) => { if (e.key === "Enter") login(); });
  $("logout-btn").addEventListener("click", () => logout(""));

  // -------------------------------------------------------------------------
  // 5. THE MAP (choose a quest)
  // -------------------------------------------------------------------------

  async function loadMap() {
    state.phase = "idle";
    const data = await DQApi.call("skills");
    if (data.error) return;

    $("greeting").textContent = "Welcome, " + state.name + "!";
    $("map-message").textContent = "";
    $("total-xp").textContent = data.total_xp;
    $("weekly-text").textContent = data.weekly_rounds + " of " + data.weekly_goal + " quests";
    $("weekly-fill").style.width = Math.min(100, (data.weekly_rounds / data.weekly_goal) * 100) + "%";

    const list = $("skill-list");
    list.innerHTML = "";
    state.levels = {};

    for (const s of data.skills) {
      state.levels[s.skill] = s.level_exact;

      const card = document.createElement("button");
      card.type = "button";
      card.className = "dq-skill-card";

      const ring = document.createElement("div");                // the mastery ring
      ring.className = "dq-ring";
      ring.style.setProperty("--pct", s.mastery);
      const inner = document.createElement("div");
      inner.className = "dq-ring-inner";
      inner.textContent = s.mastery + "%";
      ring.appendChild(inner);

      const text = document.createElement("div");
      const name = document.createElement("div");
      name.className = "dq-skill-name";
      name.textContent = s.skill;                                // textContent keeps text safe
      const meta = document.createElement("div");
      meta.className = "dq-skill-meta";
      meta.textContent = "Stage " + s.level + ": " + DQScenes.STAGES[s.level - 1].name + " · " + s.xp + " XP";
      text.appendChild(name);
      text.appendChild(meta);

      card.appendChild(ring);
      card.appendChild(text);
      card.addEventListener("click", () => startQuest(s.skill, "normal"));
      list.appendChild(card);
    }
    showScreen("map-screen");
  }

  $("repair-btn").addEventListener("click", () => startQuest(null, "repair"));

  // -------------------------------------------------------------------------
  // 6. A QUEST
  // -------------------------------------------------------------------------

  function sceneStage() {
    if (state.preview) return state.preview;
    if (state.kind === "repair") return state.current ? state.current.difficulty : 1;
    return clamp(Math.floor(state.levelExact + 0.5), 1, 5);
  }

  // Called when the stage might have changed: shows a banner and resets the danger.
  function checkStage() {
    const stage = sceneStage();
    if (stage === state.lastStage) return;
    state.lastStage = stage;
    world.dangerP = 0;
    const cfg = DQScenes.STAGES[stage - 1];
    world.banner = {
      title: "Stage " + stage + ": " + cfg.name,
      text: isCalm() || isExam() ? "Calm: take your time" : cfg.intro,
      until: world.t + 2.6,
    };
  }

  function updateGoal() {
    $("goal").textContent = isExam() ? "Choose the correct answer." : DQScenes.STAGES[sceneStage() - 1].goal;
  }

  function startQuest(skill, kind) {
    state.skill = skill;
    state.kind = kind;
    state.preview = kind === "normal" ? parseInt($("preview-select").value, 10) : 0;
    state.seen = [];
    state.asked = 0; state.correctCount = 0; state.xpThisQuest = 0;
    state.dragonHits = 0; state.previewWins = 0;
    state.hearts = MAX_HEARTS;
    state.levelExact = skill ? (state.levels[skill] || 1) : 1;
    state.current = null;
    state.pendingEnd = null;
    state.lastStage = 0;

    world.seq = null; world.shot = null; world.pull = 0; world.doorOpen = 0;
    world.dragon = { hp: DRAGON_HITS_NEEDED, fall: 0, flash: 0 };
    world.fx = []; world.popups = []; world.banner = null;
    world.shake = 0; world.hitFlash = 0; world.outcome = null;
    world.dangerP = 0; world.dangerVisible = true;

    $("end-panel").classList.add("dq-hidden");
    $("question-panel").classList.remove("dq-hidden");
    $("scene-wrap").classList.toggle("dq-hidden", isExam());
    showScreen("quiz-screen");
    loadQuestion();
  }

  async function loadQuestion() {
    state.phase = "loading";                       // the danger timer waits while we fetch
    const params = { kind: state.kind, exclude: state.seen.slice(-2).join(",") };
    if (state.skill) params.skill = state.skill;
    if (state.preview) params.preview_stage = state.preview;

    const q = await DQApi.call("next-question", null, params);
    if (q.error) { state.phase = "idle"; return; }

    if (q.none) {                                  // nothing to ask
      if (state.asked === 0) {
        await loadMap();
        $("map-message").textContent = state.kind === "repair"
          ? "No old mistakes to fix. Well done!" : "No questions available for this skill yet.";
      } else {
        endQuest("done");
      }
      return;
    }

    state.current = q;
    state.seen.push(q.id);
    state.hintUsed = false;
    checkStage();

    // A fresh danger and a closed door for the new question.
    world.dangerP = 0; world.dangerVisible = true; world.doorOpen = 0; world.shot = null;
    world.fade = 1;                                // quick fade in from black

    $("quiz-title").textContent = state.kind === "repair" ? "Fix-it quest" : q.skill;
    const limited = state.kind === "repair" || isExam();
    $("quiz-count").textContent = "Question " + (state.asked + 1) + (limited ? " of " + ROUND_LENGTH : "");
    $("question-text").textContent = q.text;
    updateGoal();

    $("hint-text").classList.add("dq-hidden");
    $("feedback").classList.add("dq-hidden");
    $("next-btn").classList.add("dq-hidden");
    $("hint-btn").classList.remove("dq-hidden");

    // One ARROW for each answer. Click it (or press its number) to shoot it.
    const box = $("options");
    box.innerHTML = "";
    q.options.forEach((optionText, index) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "dq-arrow";
      const num = document.createElement("span");
      num.className = "dq-arrow-num";
      num.textContent = index + 1;
      const label = document.createElement("span");
      label.className = "dq-arrow-label";
      label.textContent = optionText;
      btn.appendChild(num);
      btn.appendChild(label);
      btn.addEventListener("click", () => submitAnswer(index));
      box.appendChild(btn);
    });

    state.shownAt = Date.now();                    // start the thinking-time clock
    state.phase = "question";
  }

  $("hint-btn").addEventListener("click", async () => {
    const result = await DQApi.call("hint/" + state.current.id);
    $("hint-text").textContent = result.hint || "";
    $("hint-text").classList.remove("dq-hidden");
    state.hintUsed = true;
  });

  // -------------------------------------------------------------------------
  // 7. SHOOTING AN ARROW
  // -------------------------------------------------------------------------

  // A tiny animation player. A "sequence" is a list of steps; each step has:
  //   dur    seconds it lasts
  //   start  (optional) runs once at the beginning
  //   update (optional) runs every frame with k going from 0 to 1
  //   end    (optional) runs once at the end
  // When every step is done, the "done" function runs.
  function runSequence(steps, done) {
    world.seq = { steps: steps, i: -1, t: 0, cur: null, done: done };
    nextStep();
  }
  function nextStep() {
    const s = world.seq;
    s.i += 1;
    if (s.i >= s.steps.length) {
      const done = s.done;
      world.seq = null;
      if (done) done();
      return;
    }
    s.cur = s.steps[s.i];
    s.t = 0;
    if (s.cur.start) s.cur.start();
  }
  function tickSequence(dt) {
    const s = world.seq;
    if (!s || !s.cur) return;
    s.t += dt;
    const k = Math.min(1, s.t / s.cur.dur);
    if (s.cur.update) s.cur.update(k);
    if (k >= 1) {
      if (s.cur.end) s.cur.end();
      nextStep();
    }
  }

  // Sparks, shards and confetti. Each is a little object that moves and fades.
  function spray(x, y, colors, count, opts) {
    const o = Object.assign({ speed: 220, size: 3, life: 0.8, shape: "circle", gravity: 500, up: 0 }, opts || {});
    for (let i = 0; i < count; i++) {
      const a = Math.random() * Math.PI * 2, sp = o.speed * (0.4 + Math.random() * 0.8);
      world.fx.push({
        x: x, y: y, vx: Math.cos(a) * sp, vy: Math.sin(a) * sp - o.up,
        r: o.size * (0.6 + Math.random() * 0.8), color: colors[i % colors.length],
        life: o.life * (0.6 + Math.random() * 0.6), maxLife: o.life, shape: o.shape, g: o.gravity,
      });
    }
  }
  function confettiBurst(x, y, count) {
    spray(x, y, ["#ffd54a", "#e0559b", "#2f6fb3", "#5aa04a", "#e0431f"], count, { speed: 320, size: 4, life: 2, shape: "rect", up: 200, gravity: 380 });
  }
  function popup(text, x, y, color) {
    world.popups.push({ text: text, x: x, y: y, color: color || "#ffd54a", t0: world.t });
  }

  // The prince is hit by the danger: lose a heart.
  function applyHit() {
    world.dangerVisible = false;
    world.shake = 0.35;
    world.hitFlash = 0.5;
    const px = DQScenes.PRINCE_X, py = DQScenes.GROUND - 40;
    spray(px, py, ["#e0314b", "#ff8c1a", "#ffd54a"], 18, { speed: 200, size: 3 });
    popup("Ouch!", px + 10, py - 40, "#ff6b6b");
    if (!isCalm()) state.hearts -= 1;
  }

  // What the arrow does when it hits, which depends on the stage.
  function impactSteps(stage, target, hitsDragon) {
    const dangerNow = DQScenes.dangerAt(stage, world.dangerP);
    if (stage === 1) {                             // the gate swings open
      return [{
        dur: 0.9,
        start: () => {
          world.shot = null; world.dangerVisible = false;
          popup("Click!", target.x, target.y - 30);
          spray(target.x, target.y, ["#ffe27a", "#fff3b0", "#f0b830"], 22, { speed: 180 });
          spray(dangerNow.x, dangerNow.y, ["#cfd6dd", "#7a5230"], 8, { speed: 120 });
        },
        update: (k) => { world.doorOpen = k; },
      }];
    }
    if (stage === 2) {                             // the boulder breaks into pieces
      return [{
        dur: 0.7,
        start: () => {
          world.shot = null; world.dangerVisible = false;
          popup("Smash!", target.x, target.y - 30);
          spray(target.x, target.y, ["#6c6f76", "#8a8d94", "#53555b"], 20, { speed: 260, size: 4, shape: "rect", gravity: 700 });
          world.shake = 0.2;
        },
      }];
    }
    if (stage === 3) {                             // the fire arrow is knocked out of the air
      return [{
        dur: 0.6,
        start: () => {
          world.shot = null; world.dangerVisible = false;
          popup("Got it!", target.x, target.y - 30);
          spray(target.x, target.y, ["#ffcc33", "#ff8c1a", "#e0431f", "#555"], 24, { speed: 220 });
        },
      }];
    }
    if (stage === 4) {                             // the goblin is defeated
      return [{
        dur: 0.7,
        start: () => {
          world.shot = null; world.dangerVisible = false;
          popup("Defeated!", target.x, target.y - 40);
          spray(target.x, target.y, ["#63a84a", "#ffd54a", "#fff"], 24, { speed: 240 });
        },
      }];
    }
    // Stage 5: the dragon is struck. After the last hit it falls.
    const steps = [{
      dur: 0.6,
      start: () => {
        world.shot = null; world.dangerVisible = false;
        world.dragon.flash = 0.3;
        world.shake = 0.3;
        popup("Direct hit!", target.x, target.y - 40);
        spray(target.x, target.y, ["#ffd54a", "#ff8c1a", "#fff"], 26, { speed: 260 });
        if (hitsDragon) world.dragon.hp = Math.max(0, DRAGON_HITS_NEEDED - state.dragonHits);
      },
    }];
    if (hitsDragon && state.dragonHits >= DRAGON_HITS_NEEDED) {
      steps.push({
        dur: 1.3,
        start: () => popup("The dragon falls!", 330, 110),
        update: (k) => { world.dragon.fall = k; },
      });
    }
    return steps;
  }

  async function submitAnswer(chosenIndex) {
    if (state.phase !== "question") return;        // ignore double clicks
    state.phase = "waiting";                       // the danger timer waits for the server

    const result = await DQApi.call("answer", {
      question_id: state.current.id,
      chosen: chosenIndex,
      time_ms: Date.now() - state.shownAt,
      hint_used: state.hintUsed,
      kind: state.kind,
      preview: !!state.preview,
    });
    if (result.error) { state.phase = "question"; return; }

    // Count the result.
    state.asked += 1;
    if (result.correct) state.correctCount += 1;
    state.xpThisQuest += result.xp_gained;

    const stage = sceneStage();
    let hitsDragon = false;                        // did this answer wound the dragon?
    if (result.correct && stage === 5) {
      if (state.preview) { hitsDragon = true; }
      else if (state.kind === "normal" && state.current.difficulty === 5) { hitsDragon = true; }
    }
    if (hitsDragon) state.dragonHits += 1;

    if (state.preview) {
      if (result.correct) state.previewWins += 1;
      if (state.previewWins >= PREVIEW_WINS_NEEDED) state.pendingEnd = state.preview === 5 ? "win" : "preview";
    } else if (state.kind === "normal" && !isExam() && state.dragonHits >= DRAGON_HITS_NEEDED) {
      state.pendingEnd = "win";
    }
    if ((state.kind === "repair" || isExam()) && state.asked >= ROUND_LENGTH) state.pendingEnd = "done";

    // Exam mode has no pictures: skip straight to the next step.
    if (isExam()) { finishAnswer(result, chosenIndex); return; }

    // Play the shot.
    state.phase = "shooting";
    const from = DQScenes.BOW;
    const target = DQScenes.targetAt(stage, world.dangerP);     // the danger stops here while we shoot
    const frozenP = world.dangerP;
    const pos = (a, b, k, arc) => ({ x: lerp(a.x, b.x, k), y: lerp(a.y, b.y, k) - Math.sin(Math.PI * k) * arc });
    const aim = (a, b, k, arc) => {
      const p = pos(a, b, k, arc), q = pos(a, b, Math.min(1, k + 0.03), arc);
      return { x: p.x, y: p.y, angle: Math.atan2(q.y - p.y, q.x - p.x) };
    };

    const steps = [{ dur: 0.22, update: (k) => { world.pull = k; }, end: () => { world.pull = 0; } }];

    if (result.correct) {
      steps.push({ dur: 0.42, update: (k) => { world.shot = aim(from, target, k, 16); } });
      impactSteps(stage, target, hitsDragon).forEach((s) => steps.push(s));
    } else {
      // A wrong arrow flies off line and drops to the floor.
      const miss = { x: lerp(from.x, target.x, 0.72), y: lerp(from.y, target.y, 0.72) + 34 };
      steps.push({ dur: 0.36, update: (k) => { world.shot = aim(from, miss, k, 10); } });
      steps.push({
        dur: 0.5,
        start: () => popup("Miss!", miss.x, miss.y - 20, "#ffffff"),
        update: (k) => { world.shot = { x: miss.x + 14 * k, y: miss.y + 70 * k * k, angle: lerp(0.3, 1.5, k), alpha: 1 - k * 0.8 }; },
        end: () => { world.shot = null; },
      });
      if (isCalm()) {                              // calm: the danger just fizzles away
        steps.push({
          dur: 0.5,
          start: () => {
            world.dangerVisible = false;
            const d = DQScenes.dangerAt(stage, frozenP);
            spray(d.x, d.y, ["#999", "#bbb"], 10, { speed: 80, gravity: -40 });
          },
        });
      } else {                                     // adventure: the danger rushes in and hits
        steps.push({ dur: 0.4, update: (k) => { world.dangerP = lerp(frozenP, 1, k); } });
        steps.push({ dur: 0.5, start: applyHit });
      }
    }

    runSequence(steps, () => {
      if (state.hearts <= 0) { endQuest("lose"); return; }
      finishAnswer(result, chosenIndex);
    });
  }

  // After the animation: colour the arrows and show the explanation.
  function finishAnswer(result, chosenIndex) {
    state.phase = "feedback";
    if (result.level_exact !== null && result.level_exact !== undefined) state.levelExact = result.level_exact;

    const arrows = $("options").children;
    for (const b of arrows) b.disabled = true;
    $("hint-btn").classList.add("dq-hidden");

    if (isExam()) {
      arrows[chosenIndex].classList.add("dq-picked");              // exam: no feedback at all
    } else {
      arrows[result.correct_index].classList.add("dq-correct");
      if (!result.correct) arrows[chosenIndex].classList.add("dq-wrong");

      $("feedback-title").className = result.correct ? "dq-good" : "dq-bad";
      $("feedback-title").textContent = result.correct ? "Correct! A perfect shot." : "Not quite. The arrow missed.";
      $("feedback-explain").textContent = result.explanation;

      let line;
      if (!result.counted) line = "That was very quick! Take a moment to think next time. No XP this time.";
      else if (state.preview) line = "Preview: nothing is saved.";
      else if (result.level_change === "up") line = "+" + result.xp_gained + " XP. Stage up! On to " + DQScenes.STAGES[result.level - 1].name + ".";
      else if (result.level_change === "down") line = "+" + result.xp_gained + " XP. Back to " + DQScenes.STAGES[result.level - 1].name + " to build strength.";
      else line = "+" + result.xp_gained + " XP";
      $("feedback-xp").textContent = line;
      $("feedback").classList.remove("dq-hidden");
    }

    checkStage();                                  // the stage may have changed

    const nextBtn = $("next-btn");
    nextBtn.textContent = state.pendingEnd === "win" ? "Go to the princess!" : state.pendingEnd ? "Finish" : "Next";
    nextBtn.classList.remove("dq-hidden");
    nextBtn.focus({ preventScroll: true });
  }

  $("next-btn").addEventListener("click", () => {
    if (state.phase !== "feedback") return;
    if (state.pendingEnd) endQuest(state.pendingEnd); else loadQuestion();
  });
  $("rest-btn").addEventListener("click", () => { if (state.phase !== "over") endQuest("rest"); });

  // The danger ran out the clock: it reaches the prince.
  function timeoutHit() {
    state.phase = "hit";
    const from = world.dangerP;
    runSequence([
      { dur: 0.35, update: (k) => { world.dangerP = lerp(from, 1, k); } },
      { dur: 0.5, start: () => { popup("Too slow!", DQScenes.PRINCE_X + 60, 150, "#ff6b6b"); applyHit(); } },
    ], () => {
      if (state.hearts <= 0) { endQuest("lose"); return; }
      world.dangerP = 0.4; world.dangerVisible = true;     // it comes again, but you keep your place
      state.phase = "question";
    });
  }

  // -------------------------------------------------------------------------
  // 8. THE END OF A QUEST
  // -------------------------------------------------------------------------

  async function endQuest(outcome) {
    world.seq = null;
    state.phase = "over";
    world.shot = null; world.pull = 0;
    world.outcome = outcome === "win" ? "win" : outcome === "lose" ? "lose" : null;

    // Count it for the weekly goal (not demos, and not tiny quests).
    if (!state.preview && (state.asked >= 3 || outcome === "win")) {
      await DQApi.call("round-finish", { skill: state.skill || "repair" });
    }

    const messages = {
      win: ["You saved the princess!", "The dragon is beaten and the princess is free."],
      lose: ["The prince retreated", "Your level is saved, so you can rest and try again."],
      rest: ["Back at camp", "Your progress is saved."],
      done: ["Quest complete!", ""],
      preview: ["Stage preview complete", "That was only a demo, so nothing was saved."],
    };
    $("end-title").textContent = messages[outcome][0];
    $("end-text").textContent = (messages[outcome][1] + " You answered " + state.correctCount + " of " + state.asked + " correctly.").trim();
    $("end-xp").textContent = isExam() || state.preview ? "" : "You earned " + state.xpThisQuest + " XP.";

    // Stars: 3 for 80% or more, 2 for 50% or more, otherwise 1. Winning is always 3.
    const share = state.asked ? state.correctCount / state.asked : 0;
    const starCount = outcome === "win" ? 3 : share >= 0.8 ? 3 : share >= 0.5 ? 2 : 1;
    const stars = $("stars");
    stars.innerHTML = "";
    if (!isExam() && outcome !== "lose" && outcome !== "rest") {
      for (let i = 0; i < 3; i++) {
        const s = document.createElement("span");
        s.textContent = i < starCount ? "⭐" : "☆";
        stars.appendChild(s);
      }
    }

    if (outcome === "win") confettiBurst(DQScenes.W / 2, 110, 120);

    $("question-panel").classList.add("dq-hidden");
    $("end-panel").classList.remove("dq-hidden");
  }

  $("again-btn").addEventListener("click", () => startQuest(state.skill, state.kind));
  $("back-btn").addEventListener("click", loadMap);

  // -------------------------------------------------------------------------
  // 9. THE GAME LOOP (about 60 times a second)
  // -------------------------------------------------------------------------

  function update(dt) {
    world.t += dt;
    if (world.shake > 0) world.shake -= dt;
    if (world.hitFlash > 0) world.hitFlash -= dt;
    if (world.fade > 0) world.fade = Math.max(0, world.fade - dt * 2.5);
    if (world.dragon.flash > 0) world.dragon.flash -= dt;

    // Sparks and confetti.
    for (const f of world.fx) { f.x += f.vx * dt; f.y += f.vy * dt; f.vy += f.g * dt; f.life -= dt; }
    world.fx = world.fx.filter((f) => f.life > 0);
    world.popups = world.popups.filter((p) => world.t - p.t0 < 1.2);
    if (world.outcome === "win" && Math.random() < 0.3) confettiBurst(Math.random() * DQScenes.W, 0, 2);

    tickSequence(dt);

    if (state.phase === "question") {
      if (isCalm()) {
        world.dangerP = 0.12 + 0.015 * Math.sin(world.t * 2);       // the danger just waits, far away
      } else {
        world.dangerP += dt / DQScenes.STAGES[sceneStage() - 1].time;  // the danger creeps closer
        if (world.dangerP >= 1) timeoutHit();
      }
    }
  }

  function render() {
    const stage = sceneStage();
    const usesStage = state.preview || state.kind === "repair";
    DQScenes.draw(ctx, {
      t: world.t, stage: stage, outcome: world.outcome,
      hearts: state.hearts, maxHearts: MAX_HEARTS, calm: isCalm(),
      timeLeft: 1 - world.dangerP, dangerP: world.dangerP, dangerVisible: world.dangerVisible && state.current !== null,
      trackPos: usesStage ? (stage - 1) / 4 : (state.levelExact - 1) / 4,
      shot: world.shot, pull: world.pull, doorOpen: world.doorOpen, dragon: world.dragon,
      fx: world.fx, popups: world.popups, banner: world.banner,
      shake: world.shake, hitFlash: world.hitFlash, fade: world.fade,
    });
  }

  let lastFrame = performance.now();
  function frame(now) {
    const dt = Math.min(0.05, (now - lastFrame) / 1000);        // cap dt so a slow tab does not jump
    lastFrame = now;
    if (!$("quiz-screen").classList.contains("dq-hidden") && !isExam()) {
      update(dt);
      render();
    }
    requestAnimationFrame(frame);
  }
  requestAnimationFrame(frame);

  // -------------------------------------------------------------------------
  // 10. KEYBOARD: press 1 to 4 to shoot that arrow
  // -------------------------------------------------------------------------

  window.addEventListener("keydown", (e) => {
    if ($("quiz-screen").classList.contains("dq-hidden") || state.phase !== "question") return;
    if (["1", "2", "3", "4"].indexOf(e.key) >= 0) {
      const btn = $("options").children[parseInt(e.key, 10) - 1];
      if (btn) btn.click();
    }
  });

  // -------------------------------------------------------------------------
  // 11. START
  // -------------------------------------------------------------------------

  applyMode(dqStore.get("dq_mode") || "adventure");

  const savedToken = dqStore.get("dq_token");
  if (savedToken) {                                  // already logged in on this device
    DQApi.token = savedToken;
    state.name = dqStore.get("dq_name") || "hero";
    loadMap();
  } else {
    showScreen("login-screen");
  }
})();

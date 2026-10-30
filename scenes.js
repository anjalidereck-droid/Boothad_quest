/*
  scenes.js
  =========
  ALL the drawing lives here: the castle, the prince and his bow, the princess,
  the dragon, the enemies and the effects, drawn on the <canvas>.

  This file never talks to the server. game.js decides WHAT is happening and
  calls DQScenes.draw(ctx, view) about 60 times a second. "view" is a plain
  object with everything needed for one picture.

  THE GAME IDEA
  -------------
  The answers to each question are ARROWS. The player shoots the arrow with the
  right answer, and it strikes the danger of that stage:
     Stage 1  the gate         right arrow opens the door
     Stage 2  a boulder        right arrow smashes it
     Stage 3  a fire arrow     right arrow shoots it down
     Stage 4  a goblin         right arrow defeats it
     Stage 5  the dragon       right arrow strikes it (3 hits to win)
  Meanwhile the danger creeps closer. That is the timer. A wrong arrow misses
  and the danger hits the prince.

  The canvas is 640 wide and 360 tall. x goes left to right, y goes top to
  bottom. GROUND is the y of the floor. Everything is drawn with simple shapes,
  so there are no picture files to load.
*/

const DQScenes = (() => {

  const W = 640, H = 360, GROUND = 312;
  const PRINCE_X = 140;                       // the prince always stands here
  const GATE_X = 450;                         // the castle gate (stage 1 and the win scene)
  const BOW = { x: PRINCE_X + 18, y: GROUND - 38 };   // where his arrows start
  const DRAGON_HEAD = { x: 432, y: 86 };      // where an arrow hits the dragon
  const lerp = (a, b, t) => a + (b - a) * t;

  // The five stages.
  //   time  : seconds before the danger reaches the prince (Adventure mode)
  //   goal  : the instruction shown above the arrows
  const STAGES = [
    { name: "The Gate",          intro: "Arrows rain from the walls!",  goal: "Shoot the arrow with the right answer to open the gate!",   time: 40 },
    { name: "The Courtyard",     intro: "A boulder is coming!",         goal: "Shoot the right arrow to smash the boulder!",               time: 38 },
    { name: "The Fire Ramparts", intro: "Fire arrows! Be quick!",       goal: "Shoot the right arrow to knock down the fire arrow!",       time: 36 },
    { name: "The Goblin Hall",   intro: "A goblin is charging!",        goal: "Shoot the right arrow to defeat the goblin!",               time: 34 },
    { name: "The Dragon's Lair", intro: "The dragon guards the princess!", goal: "Shoot the right arrow to strike the dragon!",             time: 32 },
  ];

  // ------------------------------------------------------------------ the danger's path

  // Where is the danger at progress p (0 = just appeared, 1 = reached the prince)?
  function dangerPos(stage, p) {
    const tx = PRINCE_X + 8, ty = GROUND - 44;
    switch (stage) {
      case 1: return { x: lerp(470, tx, p), y: lerp(100, ty, p) };                                  // arrow from the wall
      case 2: return { x: lerp(520, PRINCE_X + 6, p), y: lerp(95, GROUND - 26, p) - Math.sin(Math.PI * p) * 80 };   // boulder in an arc
      case 3: return { x: lerp(500, tx, p), y: lerp(84, ty, p) };                                   // fire arrow
      case 4: return { x: lerp(690, PRINCE_X + 40, p), y: GROUND - 22 - Math.abs(Math.sin(p * 26)) * 12 };          // goblin running and hopping
      default: return { x: lerp(DRAGON_HEAD.x - 20, tx, p), y: lerp(88, ty, p) };                  // fireball from the dragon
    }
  }

  // The same, plus the direction it is travelling (for rotating arrows).
  function dangerAt(stage, p) {
    const a = dangerPos(stage, p), b = dangerPos(stage, Math.min(1, p + 0.01));
    return { x: a.x, y: a.y, angle: Math.atan2(b.y - a.y, b.x - a.x) || 0 };
  }

  // Where does the player's arrow need to land?
  function targetAt(stage, p) {
    if (stage === 1) return { x: GATE_X, y: GROUND - 62 };    // the gate
    if (stage === 5) return { x: DRAGON_HEAD.x, y: DRAGON_HEAD.y };
    const d = dangerPos(stage, p);                            // stages 2 to 4: the danger itself
    return { x: d.x, y: stage === 4 ? d.y - 12 : d.y };
  }

  // ------------------------------------------------------------------ small drawing helpers

  function sky(ctx, top, bottom) {
    const g = ctx.createLinearGradient(0, 0, 0, H);
    g.addColorStop(0, top);
    g.addColorStop(1, bottom);
    ctx.fillStyle = g;
    ctx.fillRect(0, 0, W, H);
  }

  // A stone wall with battlements (the toothed top) and brick lines.
  function wall(ctx, y0, fill, line) {
    ctx.fillStyle = fill;
    ctx.fillRect(0, y0, W, GROUND - y0);
    for (let x = 6; x < W; x += 40) ctx.fillRect(x, y0 - 16, 26, 16);
    ctx.strokeStyle = line;
    ctx.lineWidth = 1;
    for (let row = 0, y = y0 + 20; y < GROUND; y += 24, row++) {
      ctx.beginPath(); ctx.moveTo(0, y); ctx.lineTo(W, y); ctx.stroke();
      for (let x = (row % 2) * 20; x < W; x += 40) {
        ctx.beginPath(); ctx.moveTo(x, y); ctx.lineTo(x, y + 24); ctx.stroke();
      }
    }
  }

  function floor(ctx, fill, line) {
    ctx.fillStyle = fill;
    ctx.fillRect(0, GROUND, W, H - GROUND);
    ctx.strokeStyle = line;
    ctx.lineWidth = 2;
    ctx.beginPath(); ctx.moveTo(0, GROUND); ctx.lineTo(W, GROUND); ctx.stroke();
  }

  // The gate. `open` goes from 0 (shut) to 1 (wide open, golden light inside).
  function gate(ctx, cx, w, h, open) {
    const top = GROUND - h;
    ctx.save();
    ctx.beginPath();                                             // the arched doorway shape
    ctx.moveTo(cx - w / 2, GROUND);
    ctx.lineTo(cx - w / 2, top + w / 2);
    ctx.arc(cx, top + w / 2, w / 2, Math.PI, 0);
    ctx.lineTo(cx + w / 2, GROUND);
    ctx.closePath();
    ctx.fillStyle = "#2a1c10";
    ctx.fill();
    ctx.clip();
    if (open > 0) {                                              // light from inside
      const g = ctx.createRadialGradient(cx, GROUND - h / 2, 4, cx, GROUND - h / 2, h);
      g.addColorStop(0, "#fff6c2"); g.addColorStop(1, "#f0b830");
      ctx.fillStyle = g;
      ctx.globalAlpha = Math.min(1, open * 1.4);
      ctx.fillRect(cx - w / 2, top, w, h);
      ctx.globalAlpha = 1;
    }
    // Two door leaves swing open by shrinking sideways.
    const leaf = (w / 2) * (1 - open * 0.92);
    for (const side of [-1, 1]) {
      const x0 = side < 0 ? cx - w / 2 : cx + w / 2 - leaf;
      ctx.fillStyle = "#5b3d22";
      ctx.fillRect(x0, top, leaf, h);
      ctx.strokeStyle = "#7d5732"; ctx.lineWidth = 3;
      for (let i = 1; i < 3; i++) { ctx.beginPath(); ctx.moveTo(x0 + (leaf * i) / 3, top); ctx.lineTo(x0 + (leaf * i) / 3, GROUND); ctx.stroke(); }
      ctx.fillStyle = "#d9a441";
      ctx.beginPath(); ctx.arc(side < 0 ? x0 + leaf - 6 : x0 + 6, GROUND - h / 2.4, 4, 0, 7); ctx.fill();
    }
    ctx.restore();
    ctx.strokeStyle = "#444"; ctx.lineWidth = 4;                 // stone arch frame
    ctx.beginPath(); ctx.moveTo(cx - w / 2 - 2, GROUND); ctx.lineTo(cx - w / 2 - 2, top + w / 2); ctx.arc(cx, top + w / 2, w / 2 + 2, Math.PI, 0); ctx.lineTo(cx + w / 2 + 2, GROUND); ctx.stroke();
  }

  function archer(ctx, x, y, t, glow) {
    ctx.save();
    ctx.translate(x, y + Math.sin(t * 3 + x) * 1.5);
    ctx.fillStyle = "#26262b";
    ctx.fillRect(-5, -22, 10, 22);
    ctx.beginPath(); ctx.arc(0, -28, 6, 0, 7); ctx.fill();
    ctx.strokeStyle = "#7a5230"; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.arc(-9, -16, 11, Math.PI - 1.2, Math.PI + 1.2); ctx.stroke();   // bow, facing left
    if (glow) {
      ctx.fillStyle = "#ff9a1f";
      ctx.beginPath(); ctx.arc(-14, -16, 3 + Math.sin(t * 20), 0, 7); ctx.fill();
    }
    ctx.restore();
  }

  function torch(ctx, x, y, t) {
    ctx.fillStyle = "#4b3322";
    ctx.fillRect(x - 3, y, 6, 30);
    for (let i = 0; i < 3; i++) {
      const f = Math.sin(t * 14 + i * 2 + x) * 3;
      ctx.fillStyle = ["#ffcc33", "#ff8c1a", "#e0431f"][i];
      ctx.beginPath(); ctx.ellipse(x + f * 0.4, y - 4 - i * 2, 8 - i * 2, 14 - i * 3, 0, 0, 7); ctx.fill();
    }
  }

  // The princess: long dress, golden hair, tiara. y is her feet.
  function princess(ctx, x, y, t) {
    ctx.save();
    ctx.translate(x, y + Math.sin(t * 4) * 1.5);
    ctx.fillStyle = "#e8b64a";
    ctx.beginPath(); ctx.ellipse(0, -44, 12, 20, 0, 0, 7); ctx.fill();
    ctx.fillStyle = "#e0559b";
    ctx.beginPath(); ctx.moveTo(-6, -32); ctx.lineTo(6, -32); ctx.lineTo(17, 0); ctx.lineTo(-17, 0); ctx.closePath(); ctx.fill();
    ctx.fillStyle = "#f2c9a0";
    ctx.beginPath(); ctx.arc(0, -42, 9, 0, 7); ctx.fill();
    ctx.fillStyle = "#333"; ctx.fillRect(-4, -43, 2, 2); ctx.fillRect(2, -43, 2, 2);
    ctx.strokeStyle = "#c0392b"; ctx.lineWidth = 1.5;
    ctx.beginPath(); ctx.arc(0, -39, 3, 0.2, Math.PI - 0.2); ctx.stroke();
    ctx.fillStyle = "#ffd54a";
    for (let i = -1; i <= 1; i++) { ctx.beginPath(); ctx.moveTo(i * 5 - 3, -49); ctx.lineTo(i * 5, -57); ctx.lineTo(i * 5 + 3, -49); ctx.fill(); }
    ctx.restore();
  }

  // The prince with his bow. `pull` (0 to 1) bends the bow just before he shoots.
  function prince(ctx, t, shake, pull) {
    ctx.save();
    ctx.translate(PRINCE_X, GROUND + (shake ? Math.sin(t * 60) * 1.5 : 0));
    ctx.fillStyle = "#4a3b2a";                                   // legs
    ctx.fillRect(-9, -16, 7, 16); ctx.fillRect(2, -16, 7, 16);
    ctx.fillStyle = "#6b4a2a";                                   // quiver on his back
    ctx.fillRect(-14, -38, 6, 22);
    ctx.fillStyle = "#e8e0d0";
    for (let i = 0; i < 3; i++) ctx.fillRect(-13 + i * 2, -44, 1.5, 7);
    ctx.fillStyle = "#2f6fb3";                                   // tunic
    ctx.fillRect(-11, -36, 22, 22);
    ctx.fillStyle = "#d9a441";                                   // belt
    ctx.fillRect(-11, -20, 22, 4);
    ctx.fillStyle = "#c0392b";                                   // red cross on his chest
    ctx.fillRect(-1, -33, 2, 11); ctx.fillRect(-5, -29, 10, 2);
    ctx.fillStyle = "#f2c9a0";                                   // head
    ctx.beginPath(); ctx.arc(0, -44, 9, 0, 7); ctx.fill();
    ctx.fillStyle = "#5b3a1a";
    ctx.beginPath(); ctx.arc(0, -47, 9, Math.PI, 0); ctx.fill();
    ctx.fillStyle = "#333"; ctx.fillRect(3, -45, 2, 2);
    ctx.fillStyle = "#ffd54a";                                   // crown
    for (let i = -1; i <= 1; i++) { ctx.beginPath(); ctx.moveTo(i * 5 - 3, -53); ctx.lineTo(i * 5, -61); ctx.lineTo(i * 5 + 3, -53); ctx.fill(); }
    // The bow, held out in front.
    const bx = 18, by = -38, bend = 3 + (pull || 0) * 7;
    ctx.strokeStyle = "#8a5a2b"; ctx.lineWidth = 3;
    ctx.beginPath(); ctx.moveTo(bx, by - 20); ctx.quadraticCurveTo(bx + 12, by, bx, by + 20); ctx.stroke();
    ctx.strokeStyle = "#f3efe4"; ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(bx, by - 20); ctx.lineTo(bx - bend, by); ctx.lineTo(bx, by + 20); ctx.stroke();
    ctx.strokeStyle = "#f2c9a0"; ctx.lineWidth = 4;              // his arm
    ctx.beginPath(); ctx.moveTo(8, -32); ctx.lineTo(bx - 1, by); ctx.stroke();
    ctx.restore();
  }

  // ------------------------------------------------------------------ the dragon

  function dragon(ctx, x, y, t, white) {
    const c = (col) => (white ? "#ffffff" : col);
    const flap = Math.sin(t * 3) * 16;
    ctx.save();
    ctx.translate(x, y);
    ctx.fillStyle = c("#6e1b1b");                                // wings
    ctx.beginPath(); ctx.moveTo(-20, -20); ctx.lineTo(-95, -90 + flap); ctx.lineTo(-60, -20); ctx.lineTo(-100, -40 + flap); ctx.lineTo(-30, 10); ctx.closePath(); ctx.fill();
    ctx.beginPath(); ctx.moveTo(10, -25); ctx.lineTo(70, -100 + flap); ctx.lineTo(55, -30); ctx.lineTo(95, -50 + flap); ctx.lineTo(30, 5); ctx.closePath(); ctx.fill();
    ctx.strokeStyle = c("#8f2a1a"); ctx.lineWidth = 14; ctx.lineCap = "round";   // tail
    ctx.beginPath(); ctx.moveTo(30, 20); ctx.quadraticCurveTo(100, 70, 60, 120); ctx.stroke();
    ctx.fillStyle = c("#a8321f");                                // body
    ctx.beginPath(); ctx.ellipse(0, 10, 62, 42, 0, 0, 7); ctx.fill();
    ctx.fillStyle = c("#e4b363");                                // belly
    ctx.beginPath(); ctx.ellipse(-4, 24, 38, 24, 0, 0, 7); ctx.fill();
    ctx.strokeStyle = c("#a8321f"); ctx.lineWidth = 22;          // neck
    ctx.beginPath(); ctx.moveTo(-30, -10); ctx.quadraticCurveTo(-60, -40, -62, -70); ctx.stroke();
    ctx.fillStyle = c("#b53a22");                                // head
    ctx.beginPath(); ctx.ellipse(-70, -82, 26, 17, -0.2, 0, 7); ctx.fill();
    ctx.fillStyle = c("#e8d9a0");                                // horns
    ctx.beginPath(); ctx.moveTo(-60, -96); ctx.lineTo(-48, -118); ctx.lineTo(-52, -92); ctx.fill();
    ctx.beginPath(); ctx.moveTo(-72, -98); ctx.lineTo(-66, -122); ctx.lineTo(-62, -96); ctx.fill();
    ctx.fillStyle = c("#ffd23f");                                // eye
    ctx.beginPath(); ctx.arc(-62, -86, 5, 0, 7); ctx.fill();
    ctx.fillStyle = "#111"; ctx.fillRect(-63, -90, 2, 8);
    const glow = 0.5 + 0.5 * Math.sin(t * 4);                    // glowing mouth
    ctx.fillStyle = `rgba(255,150,30,${0.5 + glow * 0.5})`;
    ctx.beginPath(); ctx.ellipse(-92, -78, 8, 5, 0, 0, 7); ctx.fill();
    ctx.restore();
  }

  // ------------------------------------------------------------------ the 5 backgrounds
  // Each takes (ctx, t, v) where v is the view (for the gate and the dragon).

  function bgGate(ctx, t, v) {                 // STAGE 1
    sky(ctx, "#7cc8f5", "#e2f4ff");
    ctx.fillStyle = "rgba(255,255,255,0.9)";
    for (let i = 0; i < 3; i++) {
      const cx = ((t * 8 + i * 230) % (W + 120)) - 60, cy = 40 + i * 22;
      ctx.beginPath(); ctx.arc(cx, cy, 16, 0, 7); ctx.arc(cx + 18, cy + 4, 13, 0, 7); ctx.arc(cx - 16, cy + 5, 11, 0, 7); ctx.fill();
    }
    wall(ctx, 120, "#9a9ca2", "#74767c");
    gate(ctx, GATE_X, 100, 124, v.doorOpen || 0);
    [270, 360, 520, 590].forEach(x => archer(ctx, x, 104, t, false));
    floor(ctx, "#5aa04a", "#3e7a34");
  }

  function bgCourtyard(ctx, t) {               // STAGE 2
    sky(ctx, "#8b98a8", "#d3cbb8");
    wall(ctx, 95, "#7d8087", "#5c5f66");
    ctx.fillStyle = "#6a6d74";
    [70, 190, 330, 440].forEach(x => { for (let i = 0; i < 3; i++) { ctx.beginPath(); ctx.arc(x + i * 11 - 11, 90 - (i % 2) * 8, 8, 0, 7); ctx.fill(); } });
    ctx.save(); ctx.translate(540, 95);                          // catapult
    ctx.fillStyle = "#5b3d22"; ctx.fillRect(-26, -4, 52, 8);
    ctx.rotate(-0.9 + Math.sin(t * 2) * 0.2);
    ctx.fillRect(-4, -40, 8, 44);
    ctx.fillStyle = "#44464c"; ctx.beginPath(); ctx.arc(0, -42, 8, 0, 7); ctx.fill();
    ctx.restore();
    floor(ctx, "#8d8272", "#6b6256");
    ctx.strokeStyle = "rgba(0,0,0,0.12)";
    for (let x = 0; x < W; x += 36) { ctx.beginPath(); ctx.moveTo(x, GROUND); ctx.lineTo(x - 10, H); ctx.stroke(); }
  }

  function bgRamparts(ctx, t) {                // STAGE 3
    sky(ctx, "#ff9a5a", "#3b2350");
    ctx.fillStyle = "#ffe9a8";
    ctx.beginPath(); ctx.arc(330, 60, 28, 0, 7); ctx.fill();
    wall(ctx, 100, "#4a3a47", "#2f2430");
    [110, 300, 420].forEach(x => torch(ctx, x, 190, t));
    [230, 380, 500, 590].forEach(x => archer(ctx, x, 84, t, true));
    floor(ctx, "#43353c", "#2a1f25");
  }

  function bgGoblinHall(ctx, t) {              // STAGE 4
    sky(ctx, "#0e1813", "#1f3a2b");
    ctx.fillStyle = "#2a3f34";
    [60, 230, 410, 580].forEach(x => ctx.fillRect(x - 15, 50, 30, GROUND - 50));
    ctx.strokeStyle = "#2a3f34"; ctx.lineWidth = 14;
    [[60, 230], [230, 410], [410, 580]].forEach(([a, b]) => { ctx.beginPath(); ctx.arc((a + b) / 2, 70, (b - a) / 2, Math.PI, 0); ctx.stroke(); });
    [[150, 160], [330, 190], [500, 150], [120, 230], [560, 230]].forEach(([x, y], i) => {
      const a = 0.35 + 0.35 * Math.sin(t * 2 + i * 1.7);
      ctx.fillStyle = `rgba(255,70,50,${a})`;
      ctx.beginPath(); ctx.arc(x - 6, y, 3, 0, 7); ctx.arc(x + 6, y, 3, 0, 7); ctx.fill();
    });
    floor(ctx, "#25382d", "#14221a");
  }

  function bgDragon(ctx, t, v) {               // STAGE 5
    sky(ctx, "#35090a", "#e0662b");
    ctx.fillStyle = "#2a0c0c";
    ctx.beginPath(); ctx.moveTo(0, 220); ctx.lineTo(120, 130); ctx.lineTo(240, 210); ctx.lineTo(360, 120); ctx.lineTo(500, 215); ctx.lineTo(640, 150); ctx.lineTo(640, 312); ctx.lineTo(0, 312); ctx.fill();
    ctx.fillStyle = "#5b5560"; ctx.fillRect(28, 60, 84, GROUND - 60);       // the princess's tower
    for (let x = 28; x < 112; x += 24) ctx.fillRect(x, 46, 14, 14);
    ctx.fillStyle = "#ffd77a"; ctx.fillRect(56, 96, 28, 38);
    ctx.fillStyle = "#e8b64a"; ctx.beginPath(); ctx.ellipse(70, 118, 9, 12, 0, 0, 7); ctx.fill();
    ctx.fillStyle = "#f2c9a0"; ctx.beginPath(); ctx.arc(70, 116, 6, 0, 7); ctx.fill();
    ctx.fillStyle = "#ffd54a"; ctx.fillRect(65, 107, 10, 2);
    const d = v.dragon || { fall: 0, flash: 0, hp: 3 };
    ctx.save();                                                              // the dragon (it falls when beaten)
    ctx.globalAlpha = Math.max(0, 1 - d.fall * 0.8);
    ctx.translate(505, 160 + d.fall * 230);
    ctx.rotate(d.fall * 0.6);
    dragon(ctx, 0, 0, t, d.flash > 0);
    ctx.restore();
    floor(ctx, "#3a1a14", "#1f0d0a");
    ctx.strokeStyle = `rgba(255,120,30,${0.5 + 0.3 * Math.sin(t * 3)})`; ctx.lineWidth = 2;
    [[40, 330, 90, 345], [200, 340, 260, 326], [380, 332, 450, 350], [540, 326, 600, 344]].forEach(([a, b, c, e]) => { ctx.beginPath(); ctx.moveTo(a, b); ctx.lineTo(c, e); ctx.stroke(); });
    if (d.fall < 0.01) {                                                     // dragon health: 3 orbs
      for (let i = 0; i < 3; i++) {
        ctx.beginPath(); ctx.arc(330 + i * 24, 70, 8, 0, 7);
        ctx.fillStyle = i < d.hp ? "#e0314b" : "rgba(0,0,0,0.4)"; ctx.fill();
        ctx.strokeStyle = "#fff"; ctx.lineWidth = 2; ctx.stroke();
      }
    }
  }

  function bgWin(ctx, t) {                     // VICTORY
    sky(ctx, "#ffd88a", "#fff6dc");
    ctx.save(); ctx.translate(320, 120);
    ctx.fillStyle = "rgba(255,255,255,0.35)";
    for (let i = 0; i < 12; i++) { ctx.rotate(Math.PI / 6); ctx.beginPath(); ctx.moveTo(0, 0); ctx.lineTo(-18, -400); ctx.lineTo(18, -400); ctx.fill(); }
    ctx.restore();
    wall(ctx, 120, "#c8c3b8", "#a39e92");
    gate(ctx, GATE_X, 100, 124, 1);
    floor(ctx, "#5aa04a", "#3e7a34");
  }

  const BACKGROUNDS = [bgGate, bgCourtyard, bgRamparts, bgGoblinHall, bgDragon];

  // ------------------------------------------------------------------ the dangers

  function drawDanger(ctx, stage, th, p, t) {
    ctx.save();
    ctx.translate(th.x, th.y);
    if (stage === 1 || stage === 3) {                            // an arrow, drawn pointing along its path
      ctx.rotate(th.angle);
      ctx.strokeStyle = "#7a5230"; ctx.lineWidth = 3;
      ctx.beginPath(); ctx.moveTo(-36, 0); ctx.lineTo(0, 0); ctx.stroke();
      ctx.fillStyle = "#cfd6dd";
      ctx.beginPath(); ctx.moveTo(0, -5); ctx.lineTo(0, 5); ctx.lineTo(10, 0); ctx.fill();
      ctx.fillStyle = "#e8e0d0";
      ctx.beginPath(); ctx.moveTo(-36, 0); ctx.lineTo(-44, -5); ctx.lineTo(-38, 0); ctx.lineTo(-44, 5); ctx.fill();
      if (stage === 3) {                                         // flames on the arrow
        for (let i = 0; i < 3; i++) {
          ctx.fillStyle = ["#ffcc33", "#ff8c1a", "#e0431f"][i];
          ctx.beginPath(); ctx.ellipse(-34 - i * 5, Math.sin(t * 25 + i) * 2, 12 - i * 3, 7 - i, 0, 0, 7); ctx.fill();
        }
      }
    } else if (stage === 2) {                                    // the boulder, spinning
      ctx.rotate(t * 4);
      ctx.fillStyle = "#6c6f76"; ctx.beginPath(); ctx.arc(0, 0, 16, 0, 7); ctx.fill();
      ctx.fillStyle = "#8a8d94"; ctx.beginPath(); ctx.arc(-5, -5, 6, 0, 7); ctx.fill();
      ctx.fillStyle = "#53555b"; ctx.beginPath(); ctx.arc(5, 5, 4, 0, 7); ctx.arc(6, -6, 3, 0, 7); ctx.fill();
    } else if (stage === 4) {                                    // the goblin, charging left with a club
      ctx.scale(1.5, 1.5);
      ctx.fillStyle = "#4b7f3a"; ctx.fillRect(-7, -6, 14, 14);
      ctx.fillStyle = "#3d6a2e"; ctx.fillRect(-7, 8, 5, 8 + Math.sin(t * 20) * 2); ctx.fillRect(2, 8, 5, 8 - Math.sin(t * 20) * 2);
      ctx.fillStyle = "#63a84a"; ctx.beginPath(); ctx.arc(0, -14, 10, 0, 7); ctx.fill();
      ctx.beginPath(); ctx.moveTo(-9, -16); ctx.lineTo(-19, -22); ctx.lineTo(-8, -10); ctx.fill();
      ctx.beginPath(); ctx.moveTo(9, -16); ctx.lineTo(19, -22); ctx.lineTo(8, -10); ctx.fill();
      ctx.fillStyle = "#ff3b30"; ctx.fillRect(-6, -17, 4, 4); ctx.fillRect(2, -17, 4, 4);
      ctx.fillStyle = "#fff"; ctx.fillRect(-4, -10, 2, 3); ctx.fillRect(2, -10, 2, 3);
      ctx.strokeStyle = "#6b4a2a"; ctx.lineWidth = 3;            // club
      ctx.beginPath(); ctx.moveTo(-8, -2); ctx.lineTo(-18, -16); ctx.stroke();
      ctx.fillStyle = "#6b4a2a"; ctx.beginPath(); ctx.arc(-19, -18, 5, 0, 7); ctx.fill();
    } else {                                                     // the dragon's fireball, growing
      const r = 14 + p * 14;
      const g = ctx.createRadialGradient(0, 0, 2, 0, 0, r + 8);
      g.addColorStop(0, "#fff3a0"); g.addColorStop(0.5, "#ff8c1a"); g.addColorStop(1, "rgba(224,67,31,0)");
      ctx.fillStyle = g; ctx.beginPath(); ctx.arc(0, 0, r + 8, 0, 7); ctx.fill();
    }
    ctx.restore();
  }

  // The prince's arrow in flight: pale shaft, silver tip, blue feathers.
  function drawShot(ctx, s) {
    ctx.save();
    ctx.globalAlpha = s.alpha === undefined ? 1 : s.alpha;
    ctx.translate(s.x, s.y);
    ctx.rotate(s.angle);
    ctx.strokeStyle = "rgba(255,240,170,0.5)"; ctx.lineWidth = 8;       // glow trail
    ctx.beginPath(); ctx.moveTo(-50, 0); ctx.lineTo(-30, 0); ctx.stroke();
    ctx.strokeStyle = "#f1e2b0"; ctx.lineWidth = 3;
    ctx.beginPath(); ctx.moveTo(-32, 0); ctx.lineTo(0, 0); ctx.stroke();
    ctx.fillStyle = "#dfe6ee";
    ctx.beginPath(); ctx.moveTo(0, -5); ctx.lineTo(0, 5); ctx.lineTo(11, 0); ctx.fill();
    ctx.fillStyle = "#2f6fb3";
    ctx.beginPath(); ctx.moveTo(-32, 0); ctx.lineTo(-40, -5); ctx.lineTo(-34, 0); ctx.lineTo(-40, 5); ctx.fill();
    ctx.restore();
  }

  // ------------------------------------------------------------------ HUD

  function hearts(ctx, have, max) {
    ctx.font = "bold 26px sans-serif";
    ctx.textBaseline = "top";
    for (let i = 0; i < max; i++) {
      ctx.fillStyle = i < have ? "#e0314b" : "rgba(0,0,0,0.35)";
      ctx.fillText("♥", 12 + i * 28, 4);
    }
  }

  // How much time before the danger arrives.
  function timerBar(ctx, left) {
    ctx.fillStyle = "rgba(0,0,0,0.4)"; ctx.fillRect(12, 38, 112, 10);
    ctx.fillStyle = left > 0.5 ? "#5fbf5a" : left > 0.25 ? "#f0b830" : "#e0431f";
    ctx.fillRect(14, 40, 108 * Math.max(0, left), 6);
  }

  // The track: 5 dots for the 5 stages and a marker for the prince.
  function track(ctx, pos, stage) {
    const x0 = 400, x1 = 600, y = 22;
    ctx.fillStyle = "rgba(0,0,0,0.35)";
    ctx.fillRect(x0 - 14, y - 12, x1 - x0 + 54, 28);
    ctx.strokeStyle = "#f6e9c9"; ctx.lineWidth = 3;
    ctx.beginPath(); ctx.moveTo(x0, y); ctx.lineTo(x1, y); ctx.stroke();
    for (let i = 0; i < 5; i++) {
      const x = x0 + (x1 - x0) * (i / 4);
      ctx.fillStyle = i + 1 === stage ? "#ffd54a" : "#f6e9c9";
      ctx.beginPath(); ctx.arc(x, y, i === 4 ? 7 : 5, 0, 7); ctx.fill();
      if (i === 4) { ctx.fillStyle = "#c0392b"; ctx.beginPath(); ctx.arc(x, y, 3, 0, 7); ctx.fill(); }
    }
    ctx.fillStyle = "#e0559b";
    ctx.beginPath(); ctx.arc(x1 + 24, y, 6, 0, 7); ctx.fill();
    ctx.fillStyle = "#ffd54a"; ctx.fillRect(x1 + 20, y - 11, 8, 3);
    const mx = x0 + (x1 - x0) * Math.max(0, Math.min(1, pos));
    ctx.fillStyle = "#2f6fb3"; ctx.strokeStyle = "#fff"; ctx.lineWidth = 2;
    ctx.beginPath(); ctx.arc(mx, y, 8, 0, 7); ctx.fill(); ctx.stroke();
  }

  function banner(ctx, b, t) {
    if (!b) return;
    const left = b.until - t;
    if (left <= 0) return;
    ctx.save();
    ctx.globalAlpha = Math.min(1, left * 2);
    ctx.fillStyle = "rgba(20,12,4,0.65)";
    ctx.fillRect(0, 120, W, 80);
    ctx.textAlign = "center";
    ctx.fillStyle = "#ffd54a"; ctx.font = "bold 32px Georgia, serif";
    ctx.fillText(b.title, W / 2, 140);
    ctx.fillStyle = "#fff"; ctx.font = "20px Georgia, serif";
    ctx.fillText(b.text, W / 2, 175);
    ctx.restore();
  }

  // Small floating words such as "Miss!" or "Hit!".
  function popups(ctx, list, t) {
    ctx.save();
    ctx.textAlign = "center"; ctx.font = "bold 26px Georgia, serif";
    for (const p of list) {
      const age = t - p.t0;
      if (age < 0 || age > 1.1) continue;
      ctx.globalAlpha = 1 - age / 1.1;
      ctx.fillStyle = p.color; ctx.strokeStyle = "#000"; ctx.lineWidth = 4;
      ctx.strokeText(p.text, p.x, p.y - age * 40);
      ctx.fillText(p.text, p.x, p.y - age * 40);
    }
    ctx.restore();
  }

  // ------------------------------------------------------------------ the main draw call

  function draw(ctx, v) {
    ctx.save();
    ctx.textAlign = "left";
    if (v.shake > 0) ctx.translate((Math.random() - 0.5) * 8, (Math.random() - 0.5) * 8);

    if (v.outcome === "win") {
      bgWin(ctx, v.t);
      princess(ctx, 372, GROUND, v.t);
      ctx.save(); ctx.translate(300 - PRINCE_X, 0);
      prince(ctx, v.t, false, 0);
      ctx.restore();
    } else {
      BACKGROUNDS[v.stage - 1](ctx, v.t, v);
      if (v.dangerVisible) drawDanger(ctx, v.stage, dangerAt(v.stage, v.dangerP), v.dangerP, v.t);
      prince(ctx, v.t, v.shake > 0, v.pull);
      if (v.shot) drawShot(ctx, v.shot);
    }

    // Sparks, shards and confetti.
    for (const f of v.fx) {
      ctx.globalAlpha = Math.max(0, Math.min(1, f.life / f.maxLife));
      ctx.fillStyle = f.color;
      if (f.shape === "rect") ctx.fillRect(f.x, f.y, f.r * 1.2, f.r * 1.8);
      else { ctx.beginPath(); ctx.arc(f.x, f.y, f.r, 0, 7); ctx.fill(); }
    }
    ctx.globalAlpha = 1;

    if (v.outcome !== "win") {
      if (!v.calm) { hearts(ctx, v.hearts, v.maxHearts); timerBar(ctx, v.timeLeft); }
      track(ctx, v.trackPos, v.stage);
    }
    popups(ctx, v.popups, v.t);
    banner(ctx, v.banner, v.t);

    if (v.hitFlash > 0) {                                        // red flash when the prince is hit
      ctx.fillStyle = `rgba(220,30,30,${Math.min(0.45, v.hitFlash)})`;
      ctx.fillRect(0, 0, W, H);
    }

    if (v.outcome === "lose") {
      ctx.fillStyle = "rgba(10,5,5,0.6)"; ctx.fillRect(0, 0, W, H);
      ctx.textAlign = "center";
      ctx.fillStyle = "#fff"; ctx.font = "bold 34px Georgia, serif";
      ctx.fillText("The prince must retreat!", W / 2, 150);
      ctx.font = "20px Georgia, serif";
      ctx.fillText("Rest, then try again.", W / 2, 190);
    }
    if (v.outcome === "win") {
      ctx.textAlign = "center";
      ctx.fillStyle = "#7a4a00"; ctx.font = "bold 36px Georgia, serif";
      ctx.fillText("The princess is saved!", W / 2, 60);
    }
    if (v.fade > 0) {                                            // fade in from black between questions
      ctx.fillStyle = `rgba(0,0,0,${v.fade})`;
      ctx.fillRect(0, 0, W, H);
    }
    ctx.restore();
  }

  return { W, H, GROUND, PRINCE_X, BOW, STAGES, dangerAt, targetAt, draw };
})();

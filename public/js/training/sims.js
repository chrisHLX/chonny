/* Interactive models used inside lessons. Each figure[data-sim] is initialised by name. */
(() => {
'use strict';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s).replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const css = v => getComputedStyle(document.documentElement).getPropertyValue(v).trim();
const redraws = [];

/* A small SVG line chart drawn to scale, with theme colours read at draw time. */
function lineChart(svg, { series, xMax, yMin, yMax, yTicks, yFmt, xLabel, refs = [], W = 720, H = 240, padL = 46, padR = 16, padT = 14, padB = 30 }) {
  const x = v => padL + v / xMax * (W - padL - padR), y = v => padT + (1 - (v - yMin) / (yMax - yMin)) * (H - padT - padB);
  const clampY = v => Math.max(yMin, Math.min(yMax, v));
  const ink3 = css('--t-ink-3'), rule = css('--t-line');
  let s = '';
  yTicks.forEach(t => { s += `<line x1="${padL}" x2="${W - padR}" y1="${y(t)}" y2="${y(t)}" stroke="${rule}"/><text x="${padL - 6}" y="${y(t) + 3.5}" text-anchor="end" font-size="10" fill="${ink3}">${yFmt(t)}</text>`; });
  for (let i = 0; i <= 4; i++) { const v = Math.round(xMax * i / 4); s += `<text x="${x(v)}" y="${H - padB + 16}" text-anchor="middle" font-size="10" fill="${ink3}">${v}</text>`; }
  s += `<text x="${W - padR}" y="${H - 2}" text-anchor="end" font-size="10" fill="${ink3}">${esc(xLabel)}</text>`;
  refs.forEach(r => { s += `<line x1="${padL}" x2="${W - padR}" y1="${y(r.v)}" y2="${y(r.v)}" stroke="${r.color}" stroke-dasharray="5 4" stroke-width="1.3"/><text x="${W - padR}" y="${y(r.v) - 5}" text-anchor="end" font-size="10" fill="${r.color}">${esc(r.label)}</text>`; });
  series.forEach(se => {
    const d = se.data.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(clampY(v)).toFixed(1)}`).join(' ');
    if (se.area) s += `<path d="${d} L${x(se.data.length - 1)} ${y(yMin)} L${x(0)} ${y(yMin)} Z" fill="${se.area}"/>`;
    s += `<path d="${d}" fill="none" stroke="${se.color}" stroke-width="2" stroke-linejoin="round"/>`;
    const li = se.data.length - 1;
    s += `<circle cx="${x(li)}" cy="${y(clampY(se.data[li]))}" r="3.5" fill="${se.color}"/>`;
  });
  svg.innerHTML = s;
}

/* ---------------- control loop anatomy ---------------- */
function loopDiagram(fig) {
  const ROLES = {
    set: ['Set point', 'The goal: the value the loop is trying to reach or hold. It usually comes from outside the loop.'],
    comp: ['Comparator', 'Subtracts what was measured from the goal. The result, the error, is the only thing the controller acts on.'],
    ctrl: ['Controller', 'Turns the error into a decision: how much to correct, and how fast.'],
    act: ['Actuator', 'The part that changes the world. A controller without one can only watch.'],
    proc: ['Process', 'The thing being steered, with its own dynamics and delays.'],
    sens: ['Sensor', 'Measures the output and feeds it back. The loop can never be better informed than its sensor.'],
    dist: ['Disturbance', 'Anything outside the loop that pushes the process off course. Feedback exists because disturbances can\'t all be predicted.'],
  };
  const EX = {
    'Thermostat': { set: '21°C', comp: 'Thermostat compares reading with 21°C', ctrl: 'On below 20.5°C, off above 21.5°C', act: 'Boiler or heater', proc: 'Air in the room', sens: 'Thermometer in the thermostat', dist: 'Open window, cold night, sunlight' },
    'Cruise control': { set: '100 km/h', comp: 'Engine computer compares wheel speed with 100', ctrl: 'Control algorithm (typically PI or PID)', act: 'Throttle', proc: 'The car on the road', sens: 'Wheel-speed sensor', dist: 'Hills, headwind, load' },
    'Arena games': { set: 'A standard you hold yourself to, e.g. a defensive answer for every enemy burst window', comp: 'Match review against that standard', ctrl: 'You, deciding what to change next session', act: 'Your play: keybinds, positioning, timing', proc: 'The match itself', sens: 'Combat log and recordings', dist: 'Opponents\' choices, teammates, latency, matchups' },
    'Field service': { set: 'First-time fix rate of 90%', comp: 'Monthly operations review', ctrl: 'Operations manager', act: 'Van stock lists, training, scheduling rules', proc: 'Jobs done in the field', sens: 'Job records: was a return visit needed?', dist: 'Equipment age, supplier delays, staff turnover' },
  };
  let ex = 'Thermostat', role = 'sens';
  const exBox = $('[data-examples]', fig);
  exBox.innerHTML = Object.keys(EX).map(k => `<button class="seg-btn" type="button" data-ex="${esc(k)}">${esc(k)}</button>`).join('');
  function paint() {
    $$('.lp-box', fig).forEach(g => { const on = g.dataset.k === role; g.classList.toggle('sel', on); g.setAttribute('aria-pressed', on); g.setAttribute('aria-label', ROLES[g.dataset.k][0]); });
    $$('[data-ex]', fig).forEach(b => { const on = b.dataset.ex === ex; b.classList.toggle('on', on); b.setAttribute('aria-pressed', on); });
    const r = ROLES[role];
    $('[data-def]', fig).innerHTML = `<span class="t-label">${esc(ex)}</span><p class="t-strong" style="margin-top:4px">${esc(r[0])}</p><p style="margin-top:4px">${esc(r[1])}</p><p class="t-strong" style="margin-top:6px;font-weight:400"><b>Here:</b> ${esc(EX[ex][role])}</p>`;
    $('[data-tbl]', fig).innerHTML = Object.keys(ROLES).map(k => `<tr class="${k === role ? 'sel' : ''}" data-k="${k}"><td>${esc(ROLES[k][0])}</td><td>${esc(EX[ex][k])}</td></tr>`).join('');
  }
  $$('.lp-box', fig).forEach(g => {
    g.addEventListener('click', () => { role = g.dataset.k; paint(); });
    g.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); role = g.dataset.k; paint(); } });
  });
  $('[data-tbl]', fig).addEventListener('click', e => { const tr = e.target.closest('tr'); if (tr) { role = tr.dataset.k; paint(); } });
  exBox.addEventListener('click', e => { const b = e.target.closest('[data-ex]'); if (b) { ex = b.dataset.ex; paint(); } });
  paint();
}

/* ---------------- stock and flow ---------------- */
function stockFlow(fig) {
  let mode = 'L';
  const rate = $('[data-in="rate"]', fig), cap = $('[data-in="cap"]', fig);
  function run() {
    const r = +rate.value, K = +cap.value, N = 60; let S = 20; const data = [S];
    for (let t = 1; t <= N; t++) {
      if (mode === 'R') S += r * S; else if (mode === 'B') S += r * (K - S); else S += r * S * (1 - S / K);
      data.push(S);
    }
    $('[data-out="rate"]', fig).textContent = Math.round(r * 100) + '%';
    $('[data-out="cap"]', fig).textContent = K;
    lineChart($('[data-chart]', fig), {
      series: [{ data, color: mode === 'R' ? css('--t-r') : mode === 'B' ? css('--t-b') : css('--t-ink'), area: mode === 'R' ? css('--t-r-wash') : css('--t-b-wash') }],
      xMax: N, yMin: 0, yMax: 1000, yTicks: [0, 250, 500, 750, 1000], yFmt: v => v, xLabel: 'time steps',
      refs: mode === 'R' ? [] : [{ v: K, label: mode === 'B' ? 'goal' : 'capacity', color: css('--t-b') }],
    });
    const over = data.findIndex(v => v >= 1000);
    const txt = mode === 'R'
      ? `Reinforcing: the stock grows by ${Math.round(r * 100)}% of itself each step. ${over > 0 ? `It passes 1,000 at step ${over} and keeps accelerating.` : `It reaches ${Math.round(data[N])} by step ${N}.`} Doubling time is about ${(Math.log(2) / Math.log(1 + r)).toFixed(1)} steps.`
      : mode === 'B'
        ? `Balancing: each step closes ${Math.round(r * 100)}% of the remaining gap to ${K}. Fast at first, then slower; it approaches the goal without passing it.`
        : `Limits to growth: growth looks exponential until about step ${data.findIndex(v => v >= K / 2)}, when the stock reaches half of capacity. Then the balancing loop dominates and growth flattens near ${K}.`;
    $('[data-read]', fig).innerHTML = `<span>${esc(txt)}</span>`;
  }
  $$('[data-mode]', fig).forEach(b => b.addEventListener('click', () => { mode = b.dataset.mode; $$('[data-mode]', fig).forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); }); run(); }));
  [rate, cap].forEach(i => i.addEventListener('input', run));
  run(); redraws.push(run);
}

/* ---------------- loop polarity practice ---------------- */
function polarity(fig) {
  const ITEMS = [
    { t: 'Savings', links: [['Savings', '+', 'Interest earned'], ['Interest earned', '+', 'Savings']], a: 'R', why: 'No minus signs. More savings earn more interest, which adds to savings.' },
    { t: 'Room heating', links: [['Room temperature', '−', 'Heater output'], ['Heater output', '+', 'Room temperature']], a: 'B', why: 'One minus sign. A warmer room means less heating, which pulls it back toward the set point.' },
    { t: 'Arena rating', links: [['Your rating', '+', 'Strength of opponents'], ['Strength of opponents', '−', 'Your win rate'], ['Your win rate', '+', 'Your rating']], a: 'B', why: 'One minus sign. Climbing brings tougher opponents, which slows the climb. Rating settles at the level the player can sustain.' },
    { t: 'Word of mouth', links: [['Users', '+', 'Recommendations'], ['Recommendations', '+', 'New users'], ['New users', '+', 'Users']], a: 'R', why: 'No minus signs. More users tell more people.' },
    { t: 'Overwork', links: [['Workload', '+', 'Overtime'], ['Overtime', '+', 'Fatigue'], ['Fatigue', '+', 'Errors'], ['Errors', '+', 'Rework'], ['Rework', '+', 'Workload']], a: 'R', why: 'No minus signs: a vicious circle. Reinforcing loops can run downhill too.' },
    { t: 'Defensive stock', links: [['Enemy defensives available', '−', 'Damage that lands'], ['Damage that lands', '+', 'Defensives forced out'], ['Defensives forced out', '−', 'Enemy defensives available']], a: 'R', why: 'Two minus signs, which is even, so reinforcing: fewer defensives means more damage lands, which forces out more defensives. This is the loop behind committing to a go.' },
  ];
  const picks = {};
  const host = $('[data-pol]', fig);
  function paint() {
    host.innerHTML = ITEMS.map((p, i) => {
      const pick = picks[i], ok = pick === p.a;
      const chain = p.links.map((l, k) => `${k === 0 ? esc(l[0]) : ''} <span class="lk" title="${l[1] === '+' ? 'same direction' : 'opposite direction'}">${l[1]} →</span> ${esc(l[2])}`).join('');
      return `<div class="pol-item ${pick ? (ok ? 'right' : 'wrong') : ''}"><b>${i + 1}. ${esc(p.t)}</b><div class="chain">${chain} <span class="lk">↺</span></div>
        <div class="t-row" style="margin-top:8px"><button class="seg-btn pick-r ${pick === 'R' ? 'on' : ''}" type="button" data-p="${i}" data-v="R" aria-pressed="${pick === 'R'}">R · reinforcing</button><button class="seg-btn pick-b ${pick === 'B' ? 'on' : ''}" type="button" data-p="${i}" data-v="B" aria-pressed="${pick === 'B'}">B · balancing</button></div>
        ${pick ? `<p class="t-small" style="margin-top:8px"><b style="color:var(${ok ? '--t-ok' : '--t-bad'})">${ok ? 'Right.' : 'Count the minus signs again.'}</b> ${ok ? esc(p.why) : ''}</p>` : ''}</div>`;
    }).join('');
  }
  host.addEventListener('click', e => { const b = e.target.closest('[data-p]'); if (!b) return; picks[b.dataset.p] = b.dataset.v; paint(); });
  paint();
}

/* ---------------- thermostat ---------------- */
function thermostat(fig) {
  const DEF = { kp: 0.3, ki: 0, delay: 1, dist: true };
  const met = {};
  const inp = k => $(`[data-in="${k}"]`, fig);
  function sim(kp, ki, delay, dist, steps = 240) {
    const k = 0.05, set = 21, umax = 2.5; let T = 12, I = 0; const hist = [], temp = [T], heat = [0];
    for (let t = 0; t < steps; t++) {
      const To = dist && t >= 120 && t < 170 ? 0 : 10;
      hist.push(T);
      const meas = hist[Math.max(0, hist.length - 1 - delay)];
      const e = set - meas; let u = kp * e + ki * I;
      if (u > umax) u = umax; else if (u < 0) u = 0; else I += e;   // integrate only when not saturated
      T = T + u - k * (T - To); temp.push(T); heat.push(u);
    }
    return { temp, heat };
  }
  function run() {
    const kp = +inp('kp').value, ki = +inp('ki').value, delay = +inp('delay').value, dist = inp('dist').checked;
    $('[data-out="kp"]', fig).textContent = kp.toFixed(1);
    $('[data-out="ki"]', fig).textContent = ki.toFixed(3);
    $('[data-out="delay"]', fig).textContent = delay + ' min';
    const { temp, heat } = sim(kp, ki, delay, dist);
    const W = 720, padL = 46, padR = 16, top = 14, mainB = 210, yMin = 8, yMax = 32;
    const x = i => padL + i / 240 * (W - padL - padR);
    const y = v => top + (1 - (Math.max(yMin, Math.min(yMax, v)) - yMin) / (yMax - yMin)) * (mainB - top);
    const hy = v => 236 + (1 - v / 2.5) * 40;
    const C = { ink3: css('--t-ink-3'), rule: css('--t-line'), b: css('--t-b'), bad: css('--t-bad'), bw: css('--t-b-wash'), badw: css('--t-bad-wash'), ink: css('--t-ink') };
    let s = '';
    if (dist) s += `<rect x="${x(120)}" y="${top}" width="${x(170) - x(120)}" height="${276 - top}" fill="${C.badw}"/><text x="${x(120) + 4}" y="${top + 12}" font-size="10" fill="${C.bad}">window open, 0°C outside</text>`;
    [10, 15, 20, 25, 30].forEach(t => { s += `<line x1="${padL}" x2="${W - padR}" y1="${y(t)}" y2="${y(t)}" stroke="${C.rule}"/><text x="${padL - 6}" y="${y(t) + 3.5}" text-anchor="end" font-size="10" fill="${C.ink3}">${t}°</text>`; });
    s += `<line x1="${padL}" x2="${W - padR}" y1="${y(21)}" y2="${y(21)}" stroke="${C.b}" stroke-dasharray="5 4" stroke-width="1.3"/><text x="${W - padR}" y="${y(21) - 5}" text-anchor="end" font-size="10" fill="${C.b}">set point 21°C</text>`;
    s += `<path d="${temp.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ')}" fill="none" stroke="${C.ink}" stroke-width="2" stroke-linejoin="round"/><circle cx="${x(240)}" cy="${y(temp[240])}" r="3.5" fill="${C.ink}"/>`;
    const hd = heat.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${hy(v).toFixed(1)}`).join(' ');
    s += `<line x1="${padL}" x2="${W - padR}" y1="276" y2="276" stroke="${C.rule}"/><text x="${padL - 6}" y="240" text-anchor="end" font-size="10" fill="${C.ink3}">heat</text><path d="${hd} L${x(240)} 276 L${x(0)} 276 Z" fill="${C.bw}"/><path d="${hd}" fill="none" stroke="${C.b}" stroke-width="1.4"/>`;
    [0, 60, 120, 180, 240].forEach(v => { s += `<text x="${x(v)}" y="292" text-anchor="middle" font-size="10" fill="${C.ink3}">${v}</text>`; });
    s += `<text x="${W - padR}" y="${mainB + 14}" text-anchor="end" font-size="10" fill="${C.ink3}">room temperature · heater output below · minutes</text>`;
    $('[data-chart]', fig).innerHTML = s;
    const settle = temp.slice(100, 121), peak = Math.max(...temp.slice(0, 120));
    const swing = Math.max(...settle) - Math.min(...settle), endVal = temp[119], gap = 21 - endVal;
    $('[data-read]', fig).innerHTML = `<span>Before the window: <b>${endVal.toFixed(1)}°C</b> (${gap >= 0 ? gap.toFixed(1) + '° below' : (-gap).toFixed(1) + '° above'} target)</span><span>Peak: <b>${peak.toFixed(1)}°C</b></span><span>Swing in minutes 100–120: <b>${swing.toFixed(1)}°</b></span>${dist ? `<span>Lowest with window open: <b>${Math.min(...temp.slice(120, 175)).toFixed(1)}°C</b></span>` : ''}<span>End: <b>${temp[240].toFixed(1)}°C</b></span>`;
    if (ki === 0 && kp >= 0.6 && swing < 0.5 && gap > 0.05) met.sse = true;
    if (delay >= 6 && kp >= 1 && swing > 4) met.osc = true;
    if (Math.abs(gap) <= 0.3 && swing < 0.5 && peak <= 23 && Math.abs(21 - temp[240]) <= 0.3) met.fix = true;
    $$('.tasks li', fig).forEach(li => li.classList.toggle('met', !!met[li.dataset.t]));
  }
  ['kp', 'ki', 'delay'].forEach(k => inp(k).addEventListener('input', run));
  inp('dist').addEventListener('change', run);
  $('[data-reset]', fig).addEventListener('click', () => { inp('kp').value = DEF.kp; inp('ki').value = DEF.ki; inp('delay').value = DEF.delay; inp('dist').checked = DEF.dist; run(); });
  run(); redraws.push(run);
}

/* ---------------- commitment, defensives and the kill ----------------
   One enemy target, a healer, and your team's goes on a fixed timer. Their defensives are a stock:
   used when health falls below 50% (a second one is stacked below 25%), refilled on a recharge timer.
   Commitment adds burst; above 0.5 it also puts crowd control on their healer. Invented numbers. */
function burstModel({ commit, heal = 4, sustain = 2.5, burst = 14, goEvery = 45, goLen = 8, defCd = 90, defCharges = 2, defDur = 8, T = 180 }) {
  let hp = 100; const ready = Array(defCharges).fill(0); const actives = [];
  const out = { hp: [100], stock: [defCharges], used: [], uses: [], goes: [], kill: null };
  let usedThisGo = 0, goN = 0;
  for (let t = 1; t <= T; t++) {
    const phase = t - 10, inGo = phase >= 0 && phase % goEvery < goLen;
    if (inGo && phase % goEvery === 0) { if (goN) out.used.push(usedThisGo); goN++; usedThisGo = 0; out.goes.push(t); }
    const live = actives.filter(a => a > t).length;
    const use = () => { const i = ready.findIndex(r => r <= t); if (i < 0) return; ready[i] = t + defCd; actives.push(t + defDur); usedThisGo++; out.uses.push(t); };
    if ((hp < 50 && live === 0) || (hp < 25 && live === 1)) use();
    const mit = Math.pow(0.4, actives.filter(a => a > t).length);
    const healMul = inGo ? 1 - 0.7 * Math.max(0, (commit - 0.5) / 0.5) : 1;
    hp = Math.min(100, hp - (sustain + (inGo ? burst * commit : 0)) * mit + heal * healMul);
    out.hp.push(Math.max(0, hp)); out.stock.push(ready.filter(r => r <= t).length);
    if (hp <= 0) { out.kill = t; break; }
  }
  if (goN) out.used.push(usedThisGo);
  return out;
}
function burst(fig) {
  const inp = k => $(`[data-in="${k}"]`, fig);
  function params() { return { commit: +inp('commit').value, heal: +inp('heal').value, defCd: +inp('defCd').value, goEvery: +inp('goEvery').value }; }
  function run() {
    const p = params();
    $('[data-out="commit"]', fig).textContent = p.commit.toFixed(2);
    $('[data-out="heal"]', fig).textContent = p.heal.toFixed(1) + '%/s';
    $('[data-out="defCd"]', fig).textContent = p.defCd + ' s';
    $('[data-out="goEvery"]', fig).textContent = p.goEvery + ' s';
    const r = burstModel(p);
    const C = { ink: css('--t-ink'), ink3: css('--t-ink-3'), rule: css('--t-line'), b: css('--t-b'), bw: css('--t-b-wash'), r: css('--t-r'), rw: css('--t-r-wash'), bad: css('--t-bad') };
    const W = 720, padL = 46, padR = 16, top = 16, mainB = 200, T = 180;
    const x = t => padL + t / T * (W - padL - padR), y = v => top + (1 - v / 100) * (mainB - top);
    const sy = v => 268 - v / 2 * 40;   // defensive stock strip, 0 to 2
    let s = '';
    r.goes.forEach(t => { s += `<rect x="${x(t)}" y="${top}" width="${x(t + 8) - x(t)}" height="${mainB - top}" fill="${C.rw}"/>`; });
    if (r.goes.length) s += `<text x="${x(r.goes[0]) + 3}" y="${top + 11}" font-size="10" fill="${C.r}">your go</text>`;
    [0, 25, 50, 75, 100].forEach(v => { s += `<line x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}" stroke="${C.rule}"/><text x="${padL - 6}" y="${y(v) + 3.5}" text-anchor="end" font-size="10" fill="${C.ink3}">${v}%</text>`; });
    s += `<line x1="${padL}" x2="${W - padR}" y1="${y(50)}" y2="${y(50)}" stroke="${C.b}" stroke-dasharray="4 4"/><text x="${W - padR}" y="${y(50) - 4}" text-anchor="end" font-size="10" fill="${C.b}">defensive used below 50%</text>`;
    s += `<path d="${r.hp.map((v, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)} ${y(v).toFixed(1)}`).join(' ')}" fill="none" stroke="${C.ink}" stroke-width="2" stroke-linejoin="round"/>`;
    r.uses.forEach(t => { s += `<line x1="${x(t)}" x2="${x(t)}" y1="${mainB}" y2="${mainB + 8}" stroke="${C.b}" stroke-width="2"/><circle cx="${x(t)}" cy="${y(r.hp[t] ?? 0)}" r="4" fill="${C.b}"/>`; });
    if (r.kill != null) s += `<text x="${x(r.kill) + 6}" y="${y(4)}" font-size="11" fill="${C.bad}">kill at ${r.kill}s</text><circle cx="${x(r.kill)}" cy="${y(0)}" r="5" fill="${C.bad}"/>`;
    // stock strip
    s += `<text x="${padL - 6}" y="${sy(1) + 3}" text-anchor="end" font-size="10" fill="${C.ink3}">stock</text>`;
    [0, 1, 2].forEach(v => { s += `<line x1="${padL}" x2="${W - padR}" y1="${sy(v)}" y2="${sy(v)}" stroke="${C.rule}"/><text x="${W - padR + 2}" y="${sy(v) + 3}" font-size="9" fill="${C.ink3}">${v}</text>`; });
    let sd = ''; r.stock.forEach((v, i) => { sd += i ? `H${x(i).toFixed(1)} V${sy(v).toFixed(1)}` : `M${x(0)} ${sy(v)}`; });
    s += `<path d="${sd}" fill="none" stroke="${C.b}" stroke-width="2"/>`;
    [0, 45, 90, 135, 180].forEach(t => { s += `<text x="${x(t)}" y="290" text-anchor="middle" font-size="10" fill="${C.ink3}">${t}s</text>`; });
    s += `<text x="${W - padR}" y="${mainB + 22}" text-anchor="end" font-size="10" fill="${C.ink3}">target health · their defensive cooldowns available below</text>`;
    $('[data-chart]', fig).innerHTML = s;

    const forced = r.used, any = forced.some(n => n > 0);
    const regime = r.kill != null ? 2 : any ? 1 : 0;
    const label = ['No defensive forced: each go was healed back, and your cooldowns were spent for nothing.',
      'Defensives forced, but their cooldowns kept up with your goes. Stalemate.',
      `Their defensive stock ran out. Kill at ${r.kill}s, on go ${r.goes.filter(t => t <= r.kill).length}.`][regime];
    $('[data-read]', fig).innerHTML = `<span class="regime regime-${regime}">${esc(label)}</span><span>Defensives forced per go: <b>${forced.join(' · ') || '0'}</b></span><span>Lowest health: <b>${Math.round(Math.min(...r.hp))}%</b></span>`;

    // sweep: time to kill at every commitment level
    const levels = []; for (let c = 0.1; c <= 1.0001; c += 0.05) levels.push(+c.toFixed(2));
    const res = levels.map(c => ({ c, kill: burstModel({ ...p, commit: c }).kill }));
    const H2 = 170, pT = 12, pB = 30, sx = c => padL + (c - 0.1) / 0.9 * (W - padL - padR), ky = t => pT + (t / 180) * (H2 - pT - pB - 22);
    let w = '';
    [0, 60, 120, 180].forEach(t => { w += `<line x1="${padL}" x2="${W - padR}" y1="${ky(t)}" y2="${ky(t)}" stroke="${C.rule}"/><text x="${padL - 6}" y="${ky(t) + 3.5}" text-anchor="end" font-size="10" fill="${C.ink3}">${t}s</text>`; });
    w += `<text x="${padL - 6}" y="${H2 - pB + 3}" text-anchor="end" font-size="10" fill="${C.ink3}">none</text>`;
    res.forEach(o => {
      const cur = Math.abs(o.c - p.commit) < 0.001;
      const cy = o.kill == null ? H2 - pB : ky(o.kill);
      w += o.kill == null
        ? `<circle cx="${sx(o.c)}" cy="${cy}" r="${cur ? 6 : 4}" fill="none" stroke="${cur ? C.ink : C.ink3}" stroke-width="1.6"/>`
        : `<circle cx="${sx(o.c)}" cy="${cy}" r="${cur ? 6 : 4}" fill="${cur ? C.ink : C.r}"/>`;
    });
    [0.1, 0.25, 0.5, 0.75, 1].forEach(c => { w += `<text x="${sx(c)}" y="${H2 - 4}" text-anchor="middle" font-size="10" fill="${C.ink3}">${c}</text>`; });
    w += `<text x="${W - padR}" y="${pT + 10}" text-anchor="end" font-size="10" fill="${C.ink3}">earlier kill is higher · hollow = no kill in 180s</text>`;
    const threshold = res.find(o => o.kill != null);
    $('[data-sweep]', fig).innerHTML = w;
    $('[data-sweep]', fig).setAttribute('aria-label', threshold ? `Kills begin at commitment ${threshold.c}` : 'No commitment level produces a kill with these settings');
  }
  ['commit', 'heal', 'defCd', 'goEvery'].forEach(k => inp(k).addEventListener('input', run));
  run(); redraws.push(run);
}

const INIT = { 'loop-diagram': loopDiagram, 'stock-flow': stockFlow, 'polarity': polarity, 'thermostat': thermostat, 'burst': burst };
$$('[data-sim]').forEach(fig => { const f = INIT[fig.dataset.sim]; if (f) f(fig); });
const mq = window.matchMedia('(prefers-color-scheme: dark)');
if (mq.addEventListener) mq.addEventListener('change', () => redraws.forEach(fn => fn()));
})();

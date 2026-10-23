/* Training lesson page: the step-by-step method, choice questions, and notes on selected text.
   Loaded by resources/views/training/lesson.blade.php with window.TRAINING set by the controller. */
(() => {
'use strict';
const L = window.TRAINING;
const body = document.getElementById('lessonBody');
if (!L || !body || body.dataset.ready) return;
body.dataset.ready = '1';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const csrf = $('meta[name="csrf-token"]').content;
const KINDS = Object.fromEntries(L.kinds.map(k => [k.value, k]));
const touch = window.matchMedia('(pointer: coarse)').matches;

async function api(method, url, payload) {
  const res = await fetch(url, {
    method, credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
    body: payload ? JSON.stringify(payload) : undefined,
  });
  if (res.status === 204) return null;
  const data = await res.json().catch(() => ({}));
  if (res.status === 419) throw new Error('Your session expired. Reload the page and try again; your text is still in the box.');
  if (!res.ok) throw new Error(data.message || `Couldn't save (error ${res.status}). Try again.`);
  return data;
}

let toastTimer;
function toast(msg) {
  let t = $('#t-toast');
  if (!t) { t = document.createElement('div'); t.id = 't-toast'; t.className = 't-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
  t.textContent = msg; t.hidden = false;
  clearTimeout(toastTimer); toastTimer = setTimeout(() => { t.hidden = true; }, 3800);
}
const fmtDay = iso => new Date(iso + 'T00:00:00').toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

/* ================= the method: steps unlock as the learner works ================= */
const S = L.state;
S.skipped = false;
const step = n => $(`[data-step="${n}"]`, body);
function reveal(name, scroll) {
  const el = step(name);
  if (!el) return;
  const wasHidden = el.hidden;
  el.hidden = false;
  if (scroll && wasHidden) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
function applyState(scrollTo) {
  const caseTried = S.case.attempts.length > 0, transferTried = S.transfer.attempts.length > 0;
  if (S.interpretation || S.skipped || caseTried) reveal('reveal', scrollTo === 'reveal');
  if (caseTried) { reveal('principle'); reveal('transfer'); }
  if (transferTried) reveal('evidence');
  if (S.evidence) {
    $('[data-model]', step('evidence')).hidden = false;
    reveal('reflection');
    $('[data-first-reading]', body).textContent = S.interpretation || 'You skipped this step at the start, so there is nothing to compare. Next lesson, try writing even one sentence first.';
  }
  if (caseTried && transferTried && S.evidence) reveal('done');
}

$$('[data-written]', body).forEach(box => {
  const ex = box.dataset.written, ta = $('textarea', box), btn = $('[data-save]', box), saved = $('[data-saved]', box);
  const hideSkip = () => { const k = $('[data-skip]', box); if (k) k.hidden = true; };
  if (S[ex]) { ta.value = S[ex]; saved.hidden = false; hideSkip(); }
  ta.addEventListener('input', () => { saved.hidden = true; });
  btn.addEventListener('click', async () => {
    const text = ta.value.trim();
    if (!text) { ta.focus(); toast('Write something first, even a sentence.'); return; }
    btn.disabled = true;
    try {
      await api('POST', L.urls.responses, { exercise: ex, body: text });
      S[ex] = text; saved.hidden = false; hideSkip();
      applyState(ex === 'interpretation' ? 'reveal' : null);
    } catch (e) { toast(e.message); } finally { btn.disabled = false; }
  });
  const skip = $('[data-skip]', box);
  if (skip) skip.addEventListener('click', () => { S.skipped = true; applyState('reveal'); });
});

$$('[data-question]', body).forEach(box => {
  const ex = box.dataset.question, st = S[ex], fb = $('[data-fb]', box), status = $('[data-q-status]', box);
  const say = (correct, text, extra = '') => { fb.hidden = false; fb.innerHTML = `<b>${correct ? 'That fits the evidence.' : 'Not this one.'}</b> ${esc(text)}${extra}`; };
  function paint() {
    $$('.opt', box).forEach(b => {
      const a = st.attempts.find(x => x.choice === +b.dataset.choice);
      b.classList.toggle('right', !!(a && a.correct));
      b.classList.toggle('wrong', !!(a && !a.correct));
    });
    const n = st.attempts.length, firstRight = st.attempts.findIndex(a => a.correct);
    status.textContent = !n ? '' : firstRight === 0 ? 'Right first time' : firstRight > 0 ? `Solved on attempt ${firstRight + 1}` : `${n} ${n === 1 ? 'attempt' : 'attempts'}`;
    const last = st.attempts[n - 1];
    if (last) say(last.correct, last.feedback, !last.correct && !st.solved ? ' <span style="color:var(--t-ink-3)">Try another answer.</span>' : '');
  }
  $$('.opt', box).forEach(b => b.addEventListener('click', async () => {
    const choice = +b.dataset.choice;
    const prior = st.attempts.find(a => a.choice === choice);
    if (prior) { say(prior.correct, prior.feedback); return; }
    b.disabled = true;
    try {
      const r = await api('POST', L.urls.responses, { exercise: ex, choice });
      if (r.alreadySolved) {
        say(r.correct, r.feedback, ' <span style="color:var(--t-ink-3)">(Not counted: you already answered this question.)</span>');
        return;
      }
      st.attempts.push({ choice, correct: r.correct, feedback: r.feedback });
      if (r.correct) st.solved = true;
      paint();
      applyState(); // unlock the next step, but leave the learner reading the feedback
    } catch (e) { toast(e.message); } finally { b.disabled = false; }
  }));
  paint();
});
applyState();

/* ================= notes on selected text ================= */
const notes = new Map();          // id -> { data, marks: [], loc }
const nodeEl = n => (n.nodeType === 1 ? n : n.parentElement);
const NO_WRAP = /^(TABLE|TBODY|THEAD|TFOOT|TR|UL|OL)$/;

function textNodes(el) {
  const w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  const out = []; let n;
  while ((n = w.nextNode())) out.push(n);
  return out;
}
function offsetIn(block, node, offset) {
  const r = document.createRange();
  r.setStart(block, 0); r.setEnd(node, offset);
  return r.toString().length;
}
// Where a note's quote sits now: its own block first, preferring the occurrence whose surrounding
// text matches; then anywhere in the lesson, in case the passage moved.
function locate(a) {
  const own = body.querySelector(`[data-block="${CSS.escape(a.block)}"]`);
  const find = el => {
    const t = el.textContent; let best = -1, bestScore = -Infinity, i = t.indexOf(a.quote);
    while (i !== -1) {
      let score = 0;
      if (a.prefix && t.slice(Math.max(0, i - a.prefix.length), i) === a.prefix) score += 2;
      if (a.suffix && t.slice(i + a.quote.length, i + a.quote.length + a.suffix.length) === a.suffix) score += 2;
      score -= Math.abs(i - a.offset) / 1e5;
      if (score > bestScore) { bestScore = score; best = i; }
      i = t.indexOf(a.quote, i + 1);
    }
    return best;
  };
  if (own) { const i = find(own); if (i >= 0) return { el: own, start: i }; }
  for (const el of $$('[data-block]', body)) {
    if (el === own) continue;
    const i = find(el); if (i >= 0) return { el, start: i };
  }
  return null;
}
function wrap(el, start, end, a) {
  const marks = []; let pos = 0;
  for (const node of textNodes(el)) {
    const len = node.length, nStart = pos; pos += len;
    if (pos <= start || nStart >= end) continue;
    if (NO_WRAP.test(node.parentNode.nodeName)) continue;
    const s = Math.max(start, nStart) - nStart, e = Math.min(end, pos) - nStart;
    let target = node;
    if (e < len) target.splitText(e);
    if (s > 0) target = target.splitText(s);
    const m = document.createElement('mark');
    m.className = `ann ann-${a.kind}`; m.dataset.ann = a.id;
    m.title = a.kindLabel + (a.body ? `: ${a.body}` : '');
    target.parentNode.insertBefore(m, target); m.appendChild(target);
    marks.push(m);
  }
  return marks;
}
function unwrap(marks) {
  marks.forEach(m => { const p = m.parentNode; if (!p) return; while (m.firstChild) p.insertBefore(m.firstChild, m); p.removeChild(m); p.normalize(); });
}
function paint(a) {
  const entry = notes.get(a.id) || { data: a, marks: [] };
  unwrap(entry.marks);
  entry.data = a;
  entry.loc = locate(a);
  entry.marks = entry.loc ? wrap(entry.loc.el, entry.loc.start, entry.loc.start + a.quote.length, a) : [];
  notes.set(a.id, entry);
}
const docOrder = entry => (entry.loc ? $$('[data-block]', body).indexOf(entry.loc.el) * 1e6 + entry.loc.start : Infinity);

/* ---- the notes rail ---- */
const rail = $('#notes');
function metaFor(a) {
  if (a.kind === 'highlight') return 'Highlight';
  if (a.status === 'resolved') return 'Resolved';
  if (!a.revisitOn) return esc(a.status);
  return a.revisitOn <= todayIso() ? `<a href="${L.urls.notebook}">Due for revisit</a>` : `Back on ${fmtDay(a.revisitOn)}`;
}
function renderRail(focusId) {
  const list = [...notes.values()].sort((x, y) => docOrder(x) - docOrder(y));
  $('#noteCount').textContent = list.length ? `${list.length}` : '';
  if (!list.length) { rail.innerHTML = '<p class="t-small">No notes on this lesson yet.</p>'; return; }
  rail.innerHTML = list.map(({ data: a, loc }) => `
    <div class="n-item ${loc ? '' : 'orphan'} ${a.id === focusId ? 'focus' : ''}" data-id="${a.id}">
      <div class="meta"><span class="kind kind-${a.kind}">${esc(a.kindLabel)}</span><span>${metaFor(a)}</span>${a.revisits ? `<span>${a.revisits} ${a.revisits === 1 ? 'revisit' : 'revisits'}</span>` : ''}</div>
      <div class="quote" data-go title="${loc ? 'Show in the lesson' : ''}">${loc ? '' : '<b>This passage has changed since your note.</b> '}“${esc(a.quote)}”</div>
      ${a.body ? `<div class="body">${esc(a.body)}</div>` : ''}
      <div class="acts"><button type="button" data-act="edit">Edit</button><button type="button" data-act="delete">Delete</button></div>
    </div>`).join('');
}
rail.addEventListener('click', async e => {
  const item = e.target.closest('.n-item'); if (!item) return;
  const id = +item.dataset.id, entry = notes.get(id);
  if (e.target.closest('[data-go]') && entry.marks.length) {
    entry.marks[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    entry.marks.forEach(m => { m.classList.add('flash'); setTimeout(() => m.classList.remove('flash'), 1400); });
    return;
  }
  const act = e.target.closest('[data-act]')?.dataset.act;
  if (act === 'delete') {
    const btn = e.target.closest('button');
    if (btn.dataset.armed !== '1') { btn.dataset.armed = '1'; btn.textContent = 'Press again to delete'; setTimeout(() => { btn.dataset.armed = ''; btn.textContent = 'Delete'; }, 3000); return; }
    try { await api('DELETE', `${L.urls.note}/${id}`); unwrap(entry.marks); notes.delete(id); renderRail(); toast('Note deleted.'); } catch (err) { toast(err.message); }
  }
  if (act === 'edit') {
    const a = entry.data;
    item.querySelector('.acts').remove();
    item.querySelector('.body')?.remove();
    item.insertAdjacentHTML('beforeend', `<div data-edit style="display:flex;flex-direction:column;gap:8px">
      <select class="t-field" aria-label="Kind of note">${L.kinds.map(k => `<option value="${k.value}" ${k.value === a.kind ? 'selected' : ''}>${esc(k.label)}</option>`).join('')}</select>
      <textarea class="t-field" aria-label="Note">${esc(a.body || '')}</textarea>
      <div class="t-row"><button class="t-btn" type="button" data-save>Save</button><button class="t-link" type="button" data-cancel>Cancel</button></div></div>`);
    const ed = item.querySelector('[data-edit]');
    ed.querySelector('textarea').focus();
    ed.querySelector('[data-cancel]').addEventListener('click', () => renderRail(id));
    ed.querySelector('[data-save]').addEventListener('click', async () => {
      try {
        const updated = await api('PATCH', `${L.urls.note}/${id}`, { kind: ed.querySelector('select').value, body: ed.querySelector('textarea').value.trim() || null });
        paint(updated); renderRail(id); toast('Note updated.');
      } catch (err) { toast(err.message); }
    });
  }
});
body.addEventListener('click', e => {
  const m = e.target.closest('mark.ann'); if (!m) return;
  const id = +m.dataset.ann;
  renderRail(id);
  rail.querySelector(`[data-id="${id}"]`)?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
});

/* ---- selecting text: a toolbar, then a composer for anything but a plain highlight ---- */
const bar = document.createElement('div');
bar.className = 't-selbar'; bar.hidden = true; bar.setAttribute('role', 'toolbar'); bar.setAttribute('aria-label', 'Mark the selected text');
bar.innerHTML = L.kinds.map(k => `<button type="button" data-kind="${k.value}"><i style="background:var(--k-${k.value})"></i>${esc(k.label)}</button>`).join('');
document.body.appendChild(bar);
const composer = document.createElement('div');
composer.className = 't-composer'; composer.hidden = true;
document.body.appendChild(composer);
let pending = null;

function readSelection() {
  const sel = getSelection();
  if (!sel.rangeCount || sel.isCollapsed) return null;
  const range = sel.getRangeAt(0);
  if (!body.contains(range.commonAncestorContainer)) return null;
  const startEl = nodeEl(range.startContainer)?.closest('[data-block]');
  if (!startEl || !body.contains(startEl) || startEl.closest('[hidden]')) return null;
  const endEl = nodeEl(range.endContainer)?.closest('[data-block]');
  const text = startEl.textContent;
  let start = offsetIn(startEl, range.startContainer, range.startOffset);
  let end = endEl === startEl ? offsetIn(startEl, range.endContainer, range.endOffset) : text.length;
  while (start < end && /\s/.test(text[start])) start++;
  while (end > start && /\s/.test(text[end - 1])) end--;
  if (end - start < 2) return null;
  return { block: startEl.dataset.block, start, end, quote: text.slice(start, end), prefix: text.slice(Math.max(0, start - 32), start), suffix: text.slice(end, end + 32), rect: range.getBoundingClientRect() };
}
// Fixed to the viewport: the site scrolls inside <main>, not the window, so page coordinates would
// drift. On touch screens it goes below the selection, clear of the phone's own copy menu.
function place(el, rect, below) {
  el.hidden = false;
  const w = el.offsetWidth, h = el.offsetHeight, vw = document.documentElement.clientWidth, vh = window.innerHeight;
  const left = Math.max(12, Math.min(rect.left + rect.width / 2 - w / 2, vw - w - 12));
  let top = below ? rect.bottom + 10 : rect.top - h - 8;
  if (!below && top < 64) top = rect.bottom + 10;
  top = Math.max(12, Math.min(top, vh - h - 12));
  el.style.left = `${left}px`; el.style.top = `${top}px`;
}
// Scrolling the page moves the text away from the toolbar: put it away until the next selection.
document.addEventListener('scroll', () => { bar.hidden = true; }, { capture: true, passive: true });
function checkSelection() {
  if (!composer.hidden) return;
  const s = readSelection();
  if (!s) { bar.hidden = true; return; }
  pending = s;
  place(bar, s.rect, touch);
}
let selTimer;
['mouseup', 'keyup'].forEach(ev => body.addEventListener(ev, () => setTimeout(checkSelection, 10)));
document.addEventListener('selectionchange', () => { clearTimeout(selTimer); selTimer = setTimeout(checkSelection, touch ? 350 : 250); });
document.addEventListener('mousedown', e => { if (!bar.contains(e.target) && !composer.contains(e.target)) bar.hidden = true; });
bar.addEventListener('mousedown', e => e.preventDefault()); // keep the selection while choosing

async function save(kind, text) {
  const s = pending;
  if (!s) return;
  try {
    const a = await api('POST', L.urls.notes, { block: s.block, quote: s.quote, prefix: s.prefix, suffix: s.suffix, offset: s.start, kind, body: text || null });
    getSelection().removeAllRanges();
    paint(a); renderRail(a.id);
    toast(a.revisitOn ? `Saved. It comes back on ${fmtDay(a.revisitOn)}.` : 'Highlighted.');
  } catch (e) { toast(e.message); }
  pending = null; bar.hidden = true; composer.hidden = true;
}
bar.addEventListener('click', e => {
  const b = e.target.closest('[data-kind]'); if (!b || !pending) return;
  const k = KINDS[b.dataset.kind];
  if (k.value === 'highlight') { save('highlight'); return; }
  bar.hidden = true;
  composer.innerHTML = `<span class="kind kind-${k.value}">${esc(k.label)}</span>
    <blockquote>“${esc(pending.quote)}”</blockquote>
    <textarea class="t-field" id="t-composer-text" placeholder="${esc(k.prompt)}" aria-label="${esc(k.prompt)}"></textarea>
    <div class="t-row" style="margin-top:8px"><button class="t-btn" type="button" data-save>Save note</button><button class="t-link" type="button" data-cancel>Cancel</button></div>
    <p class="hint">${k.days ? `It comes back for a revisit in ${k.days} ${k.days === 1 ? 'day' : 'days'}.` : ''}${touch ? '' : ' Ctrl+Enter saves, Esc cancels.'}</p>`;
  place(composer, pending.rect, true);
  const ta = $('#t-composer-text'); ta.focus();
  const go = () => save(k.value, ta.value.trim());
  composer.querySelector('[data-save]').addEventListener('click', go);
  composer.querySelector('[data-cancel]').addEventListener('click', () => { composer.hidden = true; pending = null; });
  ta.addEventListener('keydown', ev => { if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) go(); if (ev.key === 'Escape') { composer.hidden = true; pending = null; } });
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') bar.hidden = true; });

// Paint after the steps are revealed, so notes on later steps find their passages.
L.notes.forEach(paint);
renderRail();
})();

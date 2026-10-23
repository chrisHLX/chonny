/* Training reading pages: the field manual's self-checks, module ticks and worksheet, and the
   subject marks on "What to learn". The worksheet and the marks save to the account (exercise
   'worksheet' / 'marks' on the reading's slug); the self-checks are recall checks kept in this
   browser. Notes on passages are lesson.js's job. */
(() => {
'use strict';
const L = window.TRAINING;
if (!L || document.body.dataset.readingReady) return;
document.body.dataset.readingReady = '1';
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const csrf = $('meta[name="csrf-token"]').content;
const store = {
  key: k => `mc-training:${L.user}:${L.slug}:${k}`,
  get(k, d) { try { const v = localStorage.getItem(this.key(k)); return v == null ? d : JSON.parse(v); } catch (e) { return d; } },
  set(k, v) { try { localStorage.setItem(this.key(k), JSON.stringify(v)); } catch (e) {} },
};
async function save(exercise, data) {
  const res = await fetch(L.urls.responses, {
    method: 'POST', credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify({ exercise, data }),
  });
  if (!res.ok) throw new Error(res.status === 419 ? 'Your session expired. Reload the page; your text is still here.' : `Couldn't save (error ${res.status}).`);
}

/* ================= the field manual ================= */
const M = window.TRAINING_MANUAL;
if (M) {
  let quiz = store.get('quiz', {});
  let worksheetDone = false;
  const modDone = n => (n === 9 ? worksheetDone : (M.quiz[n] || []).every((q, i) => (quiz[n] || {})[i] === q.a));
  function paintProgress() {
    let done = 0;
    for (let n = 1; n <= 9; n++) {
      const d = modDone(n); if (d) done++;
      const a = $(`[data-toc="${n}"]`); if (a) a.classList.toggle('done', d);
      const st = $(`[data-mod-status="${n}"]`); if (st) { st.textContent = d ? 'Complete' : ''; st.classList.toggle('done', d); }
    }
    const p = $('[data-toc-progress]'); if (p) p.textContent = `· ${done} of 9 complete`;
  }
  function renderQuiz(el) {
    const n = el.dataset.mquiz, qs = M.quiz[n] || [], st = quiz[n] || {};
    const right = qs.filter((q, i) => st[i] === q.a).length;
    el.innerHTML = `<div class="t-mq-h"><b>Check your understanding</b><span>${right} of ${qs.length} correct</span></div>` + qs.map((q, i) => {
      const picked = st[i];
      return `<div class="t-mq-q"><p class="t-strong">${i + 1}. ${esc(q.q)}</p><div class="opts">${q.o.map((o, j) => {
        let cls = ''; if (picked != null) { if (j === q.a && picked === q.a) cls = 'right'; else if (j === picked) cls = 'wrong'; }
        return `<button type="button" class="opt ${cls}" data-q="${i}" data-o="${j}" aria-pressed="${picked === j}"><span class="k">${'ABCD'[j]}</span><span>${esc(o)}</span></button>`; }).join('')}</div>
        ${picked != null ? `<p class="t-fb"><b>${picked === q.a ? 'Correct.' : 'Not quite. Try another answer.'}</b> ${picked === q.a ? esc(q.why) : ''}</p>` : ''}</div>`;
    }).join('');
  }
  document.addEventListener('click', e => {
    const b = e.target.closest('[data-mquiz] .opt'); if (!b) return;
    const el = b.closest('[data-mquiz]'), n = el.dataset.mquiz;
    quiz[n] = quiz[n] || {}; quiz[n][b.dataset.q] = +b.dataset.o; store.set('quiz', quiz);
    renderQuiz(el); paintProgress();
  });
  $$('[data-mquiz]').forEach(renderQuiz);

  // The worksheet: saved to the account a moment after typing stops. A first visit shows the example.
  const ws = $('[data-worksheet]');
  if (ws) {
    const fields = M.worksheet.fields, example = M.worksheet.example, keys = Object.keys(fields);
    const saved = L.saved && L.saved.worksheet;
    const status = $('[data-ws-status]', ws);
    const input = k => $(`[data-ws="${k}"]`, ws);
    keys.forEach(k => { input(k).value = saved ? (saved[k] || '') : example[k]; });
    status.textContent = saved ? 'Saved to your account' : 'Example loaded. Edit any field to make it yours.';
    const values = () => Object.fromEntries(keys.map(k => [k, input(k).value]));
    // Done once seven fields hold the player's own words: the example doesn't count.
    const checkDone = () => { worksheetDone = keys.filter(k => input(k).value.trim() && input(k).value !== example[k]).length >= 7; paintProgress(); };
    let t;
    ws.addEventListener('input', () => {
      status.textContent = 'Saving…';
      clearTimeout(t);
      t = setTimeout(async () => {
        try { await save('worksheet', values()); status.textContent = 'Saved to your account'; } catch (e) { status.textContent = e.message; }
        checkDone();
      }, 700);
    });
    $('[data-ws-clear]', ws).addEventListener('click', async () => {
      keys.forEach(k => { input(k).value = ''; });
      try { await save('worksheet', values()); status.textContent = 'Cleared'; } catch (e) { status.textContent = e.message; }
      checkDone(); input('sys').focus();
    });
    $('[data-ws-copy]', ws).addEventListener('click', () => {
      const text = keys.map(k => `${fields[k][0]}: ${input(k).value.trim() || '(blank)'}`).join('\n');
      const out = $('[data-ws-out]', ws); out.hidden = false; out.textContent = text;
      const select = () => { const r = document.createRange(); r.selectNodeContents(out); const s = getSelection(); s.removeAllRanges(); s.addRange(r); };
      if (navigator.clipboard?.writeText) navigator.clipboard.writeText(text).then(() => { status.textContent = 'Copied to clipboard'; }, () => { status.textContent = 'Copy was blocked: select the text below.'; select(); });
      else { status.textContent = 'Select the text below and copy it.'; select(); }
    });
    checkDone();
  }
  paintProgress();
}

/* ================= What to learn: subject marks and filters ================= */
if (window.TRAINING_SUBJECTS) {
  let marks = (L.saved && L.saved.marks) || {};
  let filter = 'all';
  const cards = $$('[data-subj]');
  function paint() {
    let shown = 0;
    cards.forEach(c => {
      const i = c.dataset.subj, m = marks[i] || '';
      $$('[data-mark]', c).forEach(b => b.setAttribute('aria-pressed', b.dataset.mark === m));
      c.classList.toggle('t-subj-learning', m === 'learning');
      c.classList.toggle('t-subj-have', m === 'have');
      const show = filter === 'all' ? true : filter === 'mine' ? !!m : c.dataset.group === filter;
      c.hidden = !show; if (show) shown++;
    });
    $('[data-subj-empty]').classList.toggle('hidden', shown > 0);
  }
  document.addEventListener('click', async e => {
    const b = e.target.closest('[data-mark]');
    if (b) {
      const i = b.closest('[data-subj]').dataset.subj;
      marks[i] = marks[i] === b.dataset.mark ? '' : b.dataset.mark;
      if (!marks[i]) delete marks[i];
      paint();
      try { await save('marks', marks); } catch (err) { /* the mark stays on screen; it saves with the next one */ }
      return;
    }
    const f = e.target.closest('[data-subj-filters] [data-g]');
    if (f) { filter = f.dataset.g; $$('[data-subj-filters] [data-g]').forEach(x => x.classList.toggle('on', x === f)); paint(); }
  });
  paint();
}
})();

/* file.lock.js — file-browser front end for the File Lock plugin. */
(function () {
  'use strict';

  const app   = document.getElementById('filelock-app');
  const BASE  = app.dataset.base;
  // Unraid defines a global `csrf_token` on every webGUI page; it is the
  // authoritative token for POSTs. Fall back to the page-embedded value.
  const CSRF  = (typeof csrf_token !== 'undefined' && csrf_token)
                  ? csrf_token
                  : (app.dataset.csrf || '');
  const EP     = '/plugins/file.lock/include/';

  const list   = document.getElementById('fl-list');
  const crumbs = document.getElementById('fl-crumbs');
  const upBtn  = document.getElementById('fl-up');
  const lockBtn   = document.getElementById('fl-lock');
  const unlockBtn = document.getElementById('fl-unlock');
  const allBox = document.getElementById('fl-all');
  const status = document.getElementById('fl-status');

  let current = BASE;     // current fused directory
  let parent  = null;     // parent dir or null at base
  const selected = new Set();

  /* --- helpers --------------------------------------------------------- */

  function post(file, data) {
    const body = new URLSearchParams({ csrf_token: CSRF, ...data });
    return fetch(EP + file, { method: 'POST', body })
      .then(r => r.json());
  }

  function setMsg(text, kind) {
    status.textContent = text || '';
    status.className = 'fl-msg' + (kind ? ' fl-msg-' + kind : '');
  }

  function refreshButtons() {
    const n = selected.size;
    lockBtn.disabled = unlockBtn.disabled = (n === 0);
    lockBtn.textContent   = n ? `Lock (${n})`   : 'Lock';
    unlockBtn.textContent = n ? `Unlock (${n})` : 'Unlock';
  }

  /* --- rendering ------------------------------------------------------- */

  function renderCrumbs() {
    crumbs.innerHTML = '';
    const rel = current.startsWith(BASE) ? current.slice(BASE.length) : current;
    const segs = rel.split('/').filter(Boolean);
    let acc = BASE.replace(/\/$/, '');

    addCrumb(BASE.replace(/\/+$/, '') || '/', BASE);
    segs.forEach(seg => {
      acc += '/' + seg;
      crumbs.appendChild(document.createTextNode(' / '));
      addCrumb(seg, acc);
    });
  }

  function addCrumb(label, path) {
    const a = document.createElement('a');
    a.textContent = label;
    a.href = '#';
    a.onclick = e => { e.preventDefault(); load(path); };
    crumbs.appendChild(a);
  }

  function render(data) {
    if (data.error) { setMsg(data.error, 'err'); return; }
    current = data.path;
    parent  = data.parent;
    selected.clear();
    allBox.checked = false;
    upBtn.disabled = (parent === null);
    renderCrumbs();
    refreshButtons();

    list.innerHTML = '';
    if (!data.entries.length) {
      const tr = list.insertRow();
      tr.innerHTML = '<td colspan="3" class="fl-empty">Empty directory</td>';
      return;
    }

    data.entries.forEach(en => {
      const tr = document.createElement('tr');
      tr.className = en.dir ? 'fl-row fl-dir' : 'fl-row fl-file';

      // checkbox
      const tdC = tr.insertCell();
      tdC.className = 'fl-c-check';
      const cb = document.createElement('input');
      cb.type = 'checkbox';
      cb.onchange = () => {
        cb.checked ? selected.add(en.path) : selected.delete(en.path);
        tr.classList.toggle('fl-selected', cb.checked);
        refreshButtons();
      };
      tdC.appendChild(cb);

      // name (folders navigate on click)
      const tdN = tr.insertCell();
      tdN.className = 'fl-c-name';
      const icon = document.createElement('span');
      icon.className = 'fl-icon';
      icon.textContent = en.dir ? '\u{1F4C1}' : '\u{1F4C4}';
      tdN.appendChild(icon);
      if (en.dir) {
        const a = document.createElement('a');
        a.textContent = en.name;
        a.href = '#';
        a.onclick = e => { e.preventDefault(); load(en.path); };
        tdN.appendChild(a);
      } else {
        const span = document.createElement('span');
        span.textContent = en.name;
        span.className = 'fl-fname';
        span.style.cursor = 'pointer';
        span.onclick = () => { cb.checked = !cb.checked; cb.onchange(); };
        tdN.appendChild(span);
      }

      // status
      const tdS = tr.insertCell();
      tdS.className = 'fl-c-status';
      if (en.dir) {
        tdS.innerHTML = '<span class="fl-badge fl-neutral">folder</span>';
      } else if (en.locked === true) {
        tdS.innerHTML = '<span class="fl-badge fl-locked">locked</span>';
        tr.classList.add('fl-is-locked');
      } else if (en.locked === false) {
        tdS.innerHTML = '<span class="fl-badge fl-unlocked">unlocked</span>';
        tr.classList.add('fl-is-unlocked');
      } else {
        tdS.innerHTML = '<span class="fl-badge fl-neutral">n/a</span>';
      }

      list.appendChild(tr);
    });
  }

  /* --- actions --------------------------------------------------------- */

  function load(path) {
    setMsg('');
    post('Browse.php', { path }).then(render)
      .catch(() => setMsg('Failed to load directory', 'err'));
  }

  function apply(action) {
    if (!selected.size) return;
    const paths = JSON.stringify([...selected]);
    lockBtn.disabled = unlockBtn.disabled = true;
    setMsg('Working\u2026');
    post('Toggle.php', { action, paths }).then(res => {
      if (res.error) { setMsg(res.error, 'err'); return; }
      const fail = res.results.filter(r => !r.ok);
      const ok   = res.results.length - fail.length;
      if (fail.length) {
        setMsg(`${ok} succeeded, ${fail.length} failed (e.g. ${fail[0].msg})`, 'err');
      } else {
        setMsg(`${action === 'lock' ? 'Locked' : 'Unlocked'} ${ok} file(s).`, 'ok');
      }
      load(current); // refresh state/colours
    }).catch(() => setMsg('Operation failed', 'err'));
  }

  /* --- wire up --------------------------------------------------------- */

  upBtn.onclick     = () => { if (parent !== null) load(parent); };
  lockBtn.onclick   = () => apply('lock');
  unlockBtn.onclick = () => apply('unlock');

  allBox.onchange = () => {
    list.querySelectorAll('input[type=checkbox]').forEach(cb => {
      if (cb.checked !== allBox.checked) { cb.checked = allBox.checked; cb.onchange(); }
    });
  };

  document.getElementById('fl-save').onclick = () => {
    const base = document.getElementById('fl-base').value.trim();
    post('Settings.php', { base }).then(res => {
      if (res.ok) {
        setMsg('Base directory saved. Reloading\u2026', 'ok');
        setTimeout(() => location.reload(), 600);
      } else {
        setMsg(res.msg || 'Could not save', 'err');
      }
    });
  };

  load(current);
})();

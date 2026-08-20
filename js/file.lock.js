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
  const searchInput    = document.getElementById('fl-search');
  const searchClearBtn = document.getElementById('fl-search-clear');

  let current = BASE;     // current fused directory
  let parent  = null;     // parent dir or null at base
  let searchActive = false;
  let lastQuery    = '';
  let searchTimer  = null;
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

  /** Build one table row for either a normal directory listing or a search
   *  result. `opts.showPath` adds a small parent-folder line under the name,
   *  since search results (unlike a folder listing) span multiple folders. */
  function buildRow(en, opts) {
    opts = opts || {};
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

    if (opts.showPath) {
      const parentPath = en.path.slice(0, en.path.length - en.name.length - 1);
      const rel = parentPath.startsWith(BASE) ? (parentPath.slice(BASE.length) || '/') : parentPath;
      const hint = document.createElement('div');
      hint.className = 'fl-path-hint';
      hint.textContent = rel;
      hint.title = parentPath;
      hint.onclick = () => load(parentPath);
      tdN.appendChild(hint);
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

    return tr;
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
    data.entries.forEach(en => list.appendChild(buildRow(en)));
  }

  function renderSearch(data) {
    if (data.error) { setMsg(data.error, 'err'); return; }
    selected.clear();
    allBox.checked = false;
    refreshButtons();

    list.innerHTML = '';
    if (!data.results.length) {
      const tr = list.insertRow();
      tr.innerHTML = '<td colspan="3" class="fl-empty">No matches</td>';
    } else {
      data.results.forEach(en => list.appendChild(buildRow(en, { showPath: true })));
    }
    setMsg(
      data.truncated
        ? `Showing first ${data.results.length} match(es) — search stopped early on a very large tree.`
        : `${data.results.length} match(es) for “${data.query}”.`
    );
  }

  /* --- actions --------------------------------------------------------- */

  function load(path) {
    if (searchTimer) { clearTimeout(searchTimer); searchTimer = null; }
    searchActive = false;
    searchClearBtn.hidden = true;
    searchInput.value = '';
    setMsg('');
    post('Browse.php', { path }).then(render)
      .catch(() => setMsg('Failed to load directory', 'err'));
  }

  function runSearch() {
    const q = searchInput.value.trim();
    if (!q) { load(current); return; }
    lastQuery = q;
    searchActive = true;
    searchClearBtn.hidden = false;
    setMsg('Searching…');
    post('Search.php', { q, path: current }).then(renderSearch)
      .catch(() => setMsg('Search failed', 'err'));
  }

  /** Re-run whatever's currently on screen (a search or a folder listing)
   *  after a lock/unlock, so selections and status badges reflect reality. */
  function refresh() {
    if (searchActive && lastQuery) {
      post('Search.php', { q: lastQuery, path: current }).then(renderSearch);
    } else {
      load(current);
    }
  }

  function apply(action) {
    if (!selected.size) return;
    const paths = JSON.stringify([...selected]);
    lockBtn.disabled = unlockBtn.disabled = true;
    setMsg('Working…');
    post('Toggle.php', { action, paths }).then(res => {
      if (res.error) { setMsg(res.error, 'err'); return; }
      const fail = res.results.filter(r => !r.ok);
      const ok   = res.results.length - fail.length;
      if (fail.length) {
        setMsg(`${ok} succeeded, ${fail.length} failed (e.g. ${fail[0].msg})`, 'err');
      } else {
        setMsg(`${action === 'lock' ? 'Locked' : 'Unlocked'} ${ok} file(s).`, 'ok');
      }
      refresh();
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

  searchInput.addEventListener('input', () => {
    if (searchTimer) clearTimeout(searchTimer);
    if (!searchInput.value.trim()) { load(current); return; }
    searchTimer = setTimeout(runSearch, 300);
  });
  searchInput.addEventListener('keydown', e => {
    if (e.key === 'Enter') {
      e.preventDefault();
      if (searchTimer) clearTimeout(searchTimer);
      runSearch();
    }
  });
  searchClearBtn.onclick = () => load(current);

  document.getElementById('fl-save').onclick = () => {
    const base = document.getElementById('fl-base').value.trim();
    post('Settings.php', { base }).then(res => {
      if (res.ok) {
        setMsg('Base directory saved. Reloading…', 'ok');
        setTimeout(() => location.reload(), 600);
      } else {
        setMsg(res.msg || 'Could not save', 'err');
      }
    });
  };

  load(current);
})();

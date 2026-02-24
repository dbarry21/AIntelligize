/* global MYLS_FAQ_EDITOR, wp */
(function(){
  'use strict';

  const CFG = window.MYLS_FAQ_EDITOR || {};
  const $   = (sel, ctx=document) => ctx.querySelector(sel);

  const elPostType = $('#myls-fe-post-type');
  const elSearch   = $('#myls-fe-search');
  const elPosts    = $('#myls-fe-posts');

  const elTitle    = $('#myls-fe-active-title');
  const elMeta     = $('#myls-fe-active-meta');
  const elEditor   = $('#myls-fe-editor');
  const elLog      = $('#myls-fe-log');

  const btnSave    = $('#myls-fe-save');
  const btnExport  = $('#myls-fe-export');

  let ALL_POSTS = [];          // [{id,title,type}]
  let ACTIVE_TYPE = '';
  let LOADED_POSTS = [];       // [{id,title,items}]
  let EDITOR_IDS = [];         // for cleanup

  function log(msg){
    if (!elLog) return;
    const ts = new Date().toLocaleTimeString();
    elLog.textContent = `[${ts}] ${msg}\n` + elLog.textContent;
  }

  async function postAJAX(action, payload){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('nonce', CFG.nonce || '');
    Object.keys(payload||{}).forEach(k => fd.append(k, payload[k]));

    const res = await fetch(CFG.ajax_url, { method: 'POST', credentials: 'same-origin', body: fd });
    const txt = await res.text();

    let json;
    try { json = JSON.parse(txt); }
    catch(e){ throw new Error(`Invalid JSON response (HTTP ${res.status}). Check PHP error logs.`); }

    if (!json || json.success !== true) {
      const msg = (json && json.data && json.data.message) ? json.data.message : 'Request failed.';
      throw new Error(msg);
    }
    return json.data;
  }

  function setControlsEnabled(enabled){
    [btnSave, btnExport].forEach(b => { if (b) b.disabled = !enabled; });
  }

  function renderPostsList(){
    if (!elPosts) return;

    const q = (elSearch && elSearch.value) ? elSearch.value.toLowerCase() : '';
    const filtered = ALL_POSTS.filter(p => {
      if (!q) return true;
      return (p.title || '').toLowerCase().includes(q) || String(p.id).includes(q);
    });

    const prevSelected = new Set(Array.from(elPosts.selectedOptions || []).map(o => o.value));
    elPosts.innerHTML = '';

    if (!filtered.length){
      const opt = document.createElement('option');
      opt.value = '';
      opt.textContent = 'No matches';
      elPosts.appendChild(opt);
      return;
    }

    for (const p of filtered){
      const opt = document.createElement('option');
      opt.value = String(p.id);
      opt.textContent = `${p.title} (#${p.id})`;
      if (prevSelected.has(opt.value)) opt.selected = true;
      elPosts.appendChild(opt);
    }
  }

  async function loadPosts(){
    const pt = elPostType ? elPostType.value : 'page';
    ACTIVE_TYPE = pt;

    if (elPosts) elPosts.innerHTML = '<option value="">Loading…</option>';

    const data = await postAJAX('myls_faq_editor_get_posts_v1', { post_type: pt, search: '' });
    ALL_POSTS = Array.isArray(data.posts) ? data.posts : [];
    renderPostsList();
    log(`Loaded ${ALL_POSTS.length} ${pt} items.`);
  }

  function escapeAttr(s){
    return String(s||'')
      .replaceAll('&','&amp;')
      .replaceAll('"','&quot;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;');
  }

  function escapeText(s){
    return String(s||'')
      .replaceAll('&','&amp;')
      .replaceAll('<','&lt;')
      .replaceAll('>','&gt;');
  }

  function cleanupEditors(){
    if (!EDITOR_IDS.length) return;
    if (window.wp && wp.editor && typeof wp.editor.remove === 'function') {
      EDITOR_IDS.forEach(id => {
        try { wp.editor.remove(id); } catch(e) {}
      });
    }
    // TinyMCE fallback cleanup
    if (window.tinymce) {
      EDITOR_IDS.forEach(id => {
        const inst = tinymce.get(id);
        if (inst) { try { inst.remove(); } catch(e) {} }
      });
    }
    EDITOR_IDS = [];
  }

  function initEditor(textareaId){
    if (!textareaId) return;
    // Prefer wp.editor API (loads TinyMCE + Quicktags)
    if (window.wp && wp.editor && typeof wp.editor.initialize === 'function') {
      try {
        wp.editor.initialize(textareaId, {
          tinymce: true,
          quicktags: true,
          mediaButtons: false
        });
        EDITOR_IDS.push(textareaId);
      } catch(e) {
        // If initialize fails, leave as textarea
      }
      return;
    }
  }

  function getEditorContent(textareaId, fallbackEl){
    if (window.tinymce) {
      const inst = tinymce.get(textareaId);
      if (inst) return inst.getContent();
    }
    if (fallbackEl) return fallbackEl.value || '';
    const el = document.getElementById(textareaId);
    return el ? (el.value || '') : '';
  }

  function rowTemplate(postId, row, idx){
    const q = row && row.q ? row.q : '';
    const a = row && row.a ? row.a : '';

    const textareaId = `myls-fe-a-${postId}-${idx}-${Math.random().toString(16).slice(2)}`;

    const wrap = document.createElement('div');
    wrap.className = 'myls-fe-row';
    wrap.dataset.idx = String(idx);
    wrap.style.border = '1px solid #ececec';
    wrap.style.borderRadius = '10px';
    wrap.style.padding = '12px';
    wrap.style.marginBottom = '12px';

    wrap.innerHTML = `
      <div style="display:flex; justify-content:space-between; gap:.75rem; flex-wrap:wrap; align-items:center;">
        <div style="font-weight:700;">FAQ #${idx+1}</div>
        <label style="display:flex; align-items:center; gap:.5rem; user-select:none;">
          <input type="checkbox" class="myls-fe-delete"> Delete this FAQ on save
        </label>
      </div>

      <div style="margin-top:.75rem;">
        <label class="form-label">Question</label>
        <input type="text" class="form-control myls-fe-q" value="${escapeAttr(q)}" placeholder="Enter the question...">
      </div>

      <div style="margin-top:.75rem;">
        <label class="form-label">Answer</label>
        <textarea id="${escapeAttr(textareaId)}" class="form-control myls-fe-a" rows="6" placeholder="Enter the answer...">${escapeText(a)}</textarea>
      </div>
    `;

    // Store textarea id so we can read content from TinyMCE.
    wrap.dataset.editorId = textareaId;

    return wrap;
  }

  function postCardTemplate(post){
    const card = document.createElement('div');
    card.className = 'myls-fe-post-card';
    card.dataset.postId = String(post.id);
    card.style.border = '1px solid #d9d9d9';
    card.style.borderRadius = '12px';
    card.style.padding = '12px';
    card.style.marginBottom = '14px';

    const head = document.createElement('div');
    head.style.display = 'flex';
    head.style.justifyContent = 'space-between';
    head.style.gap = '.75rem';
    head.style.flexWrap = 'wrap';
    head.style.alignItems = 'center';
    head.innerHTML = `
      <div>
        <div style="font-weight:800;">${escapeText(post.title || '(no title)')}</div>
        <div class="muted" style="font-size:.92em;">Post ID: #${post.id} • Type: ${escapeText(ACTIVE_TYPE)}</div>
      </div>
      <div style="display:flex; gap:.5rem; flex-wrap:wrap;">
        <button type="button" class="btn btn-sm myls-fe-add-row" data-post-id="${post.id}">Add FAQ Row</button>
      </div>
    `;

    const body = document.createElement('div');
    body.className = 'myls-fe-post-body';
    body.style.marginTop = '12px';

    const items = Array.isArray(post.items) ? post.items : [];
    if (!items.length){
      const empty = document.createElement('div');
      empty.className = 'muted';
      empty.textContent = 'No FAQs found. Click “Add FAQ Row” to start.';
      body.appendChild(empty);
    } else {
      items.forEach((row, i) => body.appendChild(rowTemplate(post.id, row, i)));
    }

    card.appendChild(head);
    card.appendChild(body);

    return card;
  }

  function renderEditor(posts){
    if (!elEditor) return;
    cleanupEditors();
    elEditor.innerHTML = '';

    const list = Array.isArray(posts) ? posts : [];
    if (!list.length){
      elEditor.innerHTML = '<div class="muted">No posts loaded.</div>';
      return;
    }

    list.forEach(p => {
      elEditor.appendChild(postCardTemplate(p));
    });

    // Initialize wp editors after DOM is in place.
    const rows = elEditor.querySelectorAll('.myls-fe-row');
    rows.forEach(r => initEditor(r.dataset.editorId));

    // Wire per-post Add Row buttons
    elEditor.querySelectorAll('.myls-fe-add-row').forEach(btn => {
      btn.addEventListener('click', () => addRowToPost(btn.dataset.postId));
    });
  }

  function addRowToPost(postId){
    if (!elEditor) return;
    const card = elEditor.querySelector(`.myls-fe-post-card[data-post-id="${CSS.escape(String(postId))}"]`);
    if (!card) return;

    const body = card.querySelector('.myls-fe-post-body');
    if (!body) return;

    const muted = body.querySelector('.muted');
    if (muted && body.children.length === 1) body.innerHTML = '';

    const idx = body.querySelectorAll('.myls-fe-row').length;
    const rowEl = rowTemplate(postId, {q:'', a:''}, idx);
    body.appendChild(rowEl);
    initEditor(rowEl.dataset.editorId);
  }

  function collectRowsForCard(card){
    const rows = [];
    const blocks = card.querySelectorAll('.myls-fe-row');
    blocks.forEach(b => {
      const q = (b.querySelector('.myls-fe-q') || {}).value || '';
      const textarea = b.querySelector('textarea.myls-fe-a');
      const a = getEditorContent(b.dataset.editorId, textarea);
      const del = !!(b.querySelector('.myls-fe-delete') && b.querySelector('.myls-fe-delete').checked);
      rows.push({ q, a, delete: del ? 1 : 0 });
    });
    return rows;
  }

  function selectedPostIds(){
    if (!elPosts) return [];
    return Array.from(elPosts.selectedOptions || []).map(o => Number(o.value)).filter(n => n > 0);
  }

  async function loadFaqsForSelected(){
    const ids = selectedPostIds();
    LOADED_POSTS = [];

    if (!ids.length){
      if (elTitle) elTitle.textContent = 'Select one or more posts…';
      if (elMeta) elMeta.textContent = '';
      renderEditor([]);
      setControlsEnabled(false);
      return;
    }

    setControlsEnabled(false);
    if (elTitle) elTitle.textContent = 'Loading…';
    if (elMeta) elMeta.textContent = `Selected: ${ids.length} post(s) • Type: ${ACTIVE_TYPE}`;

    const data = await postAJAX('myls_faq_editor_get_faqs_batch_v1', { post_ids: ids.join(',') });

    const posts = Array.isArray(data.posts) ? data.posts : [];
    LOADED_POSTS = posts;

    if (elTitle) elTitle.textContent = `Loaded: ${posts.length} post(s)`;
    if (elMeta) elMeta.textContent = `Selected: ${ids.length} post(s) • Type: ${ACTIVE_TYPE}`;

    renderEditor(posts);
    setControlsEnabled(true);
    log(`Loaded FAQs for ${posts.length} post(s).`);
  }

  async function saveBatch(){
    if (!elEditor) return;
    const cards = elEditor.querySelectorAll('.myls-fe-post-card');
    if (!cards.length) return;

    const payload = {};
    cards.forEach(c => {
      const id = Number(c.dataset.postId || 0);
      if (!id) return;
      payload[id] = collectRowsForCard(c);
    });

    const data = await postAJAX('myls_faq_editor_save_batch_v1', {
      posts: JSON.stringify(payload)
    });

    const saved = data.saved || {};
    const totalPosts = data.posts || 0;
    log(`Batch saved. Updated ${totalPosts} post(s).`);

    // Reload to reflect cleanup on each post.
    await loadFaqsForSelected();
  }

  function exportBatch(){
    const ids = selectedPostIds();
    if (!ids.length) return;
    const url = `${CFG.export_base}?action=myls_faq_editor_export_docx_batch_v1&post_ids=${encodeURIComponent(ids.join(','))}&nonce=${encodeURIComponent(CFG.nonce||'')}`;
    window.open(url, '_blank', 'noopener');
  }

  // Events
  if (elPostType) {
    elPostType.addEventListener('change', async () => {
      setControlsEnabled(false);
      cleanupEditors();
      if (elEditor) elEditor.innerHTML = '<div class="muted">Loading…</div>';
      await loadPosts();
      await loadFaqsForSelected();
    });
  }

  if (elSearch) elSearch.addEventListener('input', () => renderPostsList());
  if (elPosts) elPosts.addEventListener('change', () => loadFaqsForSelected().catch(e => log(`Load error: ${e.message}`)));
  if (btnSave) btnSave.addEventListener('click', () => saveBatch().catch(e => log(`Save error: ${e.message}`)));
  if (btnExport) btnExport.addEventListener('click', exportBatch);

  // Init
  (async function init(){
    try {
      setControlsEnabled(false);
      await loadPosts();
      renderEditor([]);
      log('Ready.');
    } catch(e){
      log(`Init error: ${e.message}`);
    }
  })();

})();

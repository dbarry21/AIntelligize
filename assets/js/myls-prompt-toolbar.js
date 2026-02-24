/**
 * MYLS Prompt History Toolbar — Shared JS
 *
 * Auto-initialises every .myls-prompt-toolbar on the page.
 * Each toolbar reads data-prompt-key and data-textarea-id from its container.
 *
 * Requires: ajaxurl (WP global), myls_prompt_toolbar_nonce (printed inline).
 *
 * @since 6.2.0
 */
(function () {
  'use strict';

  const NONCE = window.myls_prompt_toolbar_nonce || '';

  /* ── Helpers ─────────────────────────────────────────────────────── */

  async function post(action, data) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_wpnonce', NONCE);
    Object.keys(data).forEach(k => fd.append(k, data[k]));
    const res = await fetch(ajaxurl, { method: 'POST', body: fd });
    return res.json();
  }

  /* ── Init one toolbar ────────────────────────────────────────────── */

  function initToolbar(el) {
    const promptKey  = el.dataset.promptKey;
    const textareaId = el.dataset.textareaId;

    const select    = el.querySelector('.myls-pt-history-select');
    const btnLoad   = el.querySelector('.myls-pt-btn-load');
    const btnSave   = el.querySelector('.myls-pt-btn-save');
    const btnDelete = el.querySelector('.myls-pt-btn-delete');
    const btnReload = el.querySelector('.myls-pt-btn-reload-default');

    let historyCache = [];

    /* Get textarea — may be in DOM later (dynamic tabs) */
    function getTA() {
      return document.getElementById(textareaId);
    }

    /* Render dropdown */
    function renderDropdown(items) {
      historyCache = items || [];
      select.innerHTML = '<option value="">— Saved Versions (' + historyCache.length + ') —</option>';
      historyCache.forEach(function (item) {
        const opt = document.createElement('option');
        opt.value = item.slug;
        opt.textContent = item.name + (item.updated ? ' · ' + item.updated.substring(0, 10) : '');
        select.appendChild(opt);
      });
    }

    /* Fetch list */
    async function loadList() {
      try {
        const data = await post('myls_prompt_history_list', { prompt_key: promptKey });
        if (data && data.success) renderDropdown(data.data.history || []);
      } catch (e) { /* silent */ }
    }

    /* Load selected */
    btnLoad.addEventListener('click', function () {
      const slug = select.value;
      if (!slug) { alert('Select a saved version first.'); return; }
      const item = historyCache.find(function (h) { return h.slug === slug; });
      const ta = getTA();
      if (item && ta) ta.value = item.content;
    });

    /* Save current */
    btnSave.addEventListener('click', async function () {
      const ta = getTA();
      if (!ta) return;
      const content = ta.value.trim();
      if (!content) { alert('Prompt is empty — write something first.'); return; }

      const name = prompt('Save prompt version as:', '');
      if (!name) return;

      try {
        const data = await post('myls_prompt_history_save', {
          prompt_key: promptKey,
          entry_name: name,
          content:    content,
        });
        if (data && data.success) {
          renderDropdown(data.data.history || []);
          // Auto-select the one just saved
          const slug = (data.data.history || []).find(function (h) { return h.name === name; });
          if (slug) select.value = slug.slug;
          alert(data.data.message || 'Saved.');
        } else {
          alert(data && data.data && data.data.message ? data.data.message : 'Error saving.');
        }
      } catch (e) { alert('Error: ' + e.message); }
    });

    /* Delete selected */
    btnDelete.addEventListener('click', async function () {
      const slug = select.value;
      if (!slug) { alert('Select a version to delete.'); return; }
      const item = historyCache.find(function (h) { return h.slug === slug; });
      if (!confirm('Delete "' + (item ? item.name : slug) + '"?')) return;

      try {
        const data = await post('myls_prompt_history_delete', {
          prompt_key: promptKey,
          entry_slug: slug,
        });
        if (data && data.success) {
          renderDropdown(data.data.history || []);
        }
      } catch (e) { alert('Error: ' + e.message); }
    });

    /* Reload factory default from text file */
    btnReload.addEventListener('click', async function () {
      if (!confirm('Replace the current prompt with the factory default from file?\n\nThis will NOT affect saved versions.')) return;

      var origText = btnReload.textContent;
      btnReload.textContent = ' Loading...';
      btnReload.disabled = true;

      try {
        const data = await post('myls_prompt_get_default', { prompt_key: promptKey });
        if (data && data.success && data.data && data.data.content) {
          const ta = getTA();
          if (ta) {
            ta.value = data.data.content;
            // Trigger input event so any listeners know the value changed
            ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.dispatchEvent(new Event('change', { bubbles: true }));
            // Visual flash to confirm
            ta.style.transition = 'background-color 0.3s';
            ta.style.backgroundColor = '#d4edda';
            setTimeout(function(){ ta.style.backgroundColor = ''; }, 1200);
          } else {
            alert('Could not find textarea "#' + textareaId + '" in the DOM.');
          }
        } else {
          var msg = (data && data.data && data.data.message) ? data.data.message : 'Unknown error';
          alert('Could not load default prompt.\n' + msg);
          console.error('[MYLS Prompt Toolbar] Reload default failed:', data);
        }
      } catch (e) {
        alert('Error loading default: ' + e.message);
        console.error('[MYLS Prompt Toolbar] Reload default error:', e);
      } finally {
        btnReload.textContent = origText;
        btnReload.disabled = false;
      }
    });

    /* Initial load */
    loadList();
  }

  /* ── Init all toolbars on page ───────────────────────────────────── */

  function initAll() {
    document.querySelectorAll('.myls-prompt-toolbar').forEach(initToolbar);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();

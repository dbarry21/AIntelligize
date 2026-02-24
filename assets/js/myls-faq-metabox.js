/* =====================================================================
 * MYLS – FAQ Metabox JS
 * assets/js/myls-faq-metabox.js
 * ===================================================================== */

(function () {
  'use strict';

  function qs(sel, ctx) {
    return (ctx || document).querySelector(sel);
  }

  function qsa(sel, ctx) {
    return Array.from((ctx || document).querySelectorAll(sel));
  }

  function isRowEmpty(row) {
    const q = qs('.myls-faq-q', row);
    const del = qs('.myls-faq-del', row);
    if (!q || !del) return true;

    const qv = (q.value || '').trim();

    // Answer is a wp_editor textarea. Even if TinyMCE is active, WP keeps a textarea.
    const ta = qs('textarea[name^="myls_faq["]', row);

    // Answer is stored as HTML in the underlying textarea.
    // Treat common editor placeholders as blank so users can intentionally delete rows.
    // Examples we normalize to whitespace:
    //  - &nbsp; (often persists even when editor looks empty)
    //  - non-breaking space char (\u00A0)
    let av = (ta && ta.value ? ta.value : '');
    av = av.replace(/&nbsp;/gi, ' ').replace(/\u00A0/g, ' ');
    av = av.replace(/<[^>]*>/g, ' ');
    av = av.replace(/\s+/g, ' ').trim();

    return (qv === '' && av === '');
  }

  function uncheckDelete(row) {
    const del = qs('.myls-faq-del', row);
    // Only auto-uncheck boxes that were auto-checked due to emptiness.
    // If the user intentionally checks delete on a non-empty row, respect it.
    if (!del || !del.checked) return;
    const auto = (del.getAttribute('data-auto') || '') === '1';
    if (auto) del.checked = false;
  }

  function wireRow(row) {
    if (!row || row.__mylsWired) return;
    row.__mylsWired = true;

    // If user starts typing anywhere in the row, uncheck delete (only if it was auto-checked).
    row.addEventListener('input', function (e) {
      // Don't fight the user when they tick the delete box.
      if (e && e.target && e.target.classList && e.target.classList.contains('myls-faq-del')) return;
      if (!isRowEmpty(row)) uncheckDelete(row);
    });
    row.addEventListener('change', function (e) {
      // Don't immediately uncheck when user checks the delete box.
      if (e && e.target && e.target.classList && e.target.classList.contains('myls-faq-del')) {
        // Once the user interacts with the checkbox, it is no longer "auto".
        try { e.target.removeAttribute('data-auto'); } catch (err) {}
        return;
      }
      if (!isRowEmpty(row)) uncheckDelete(row);
    });

    // TinyMCE doesn't always bubble input events. Poll lightly while the row is visible.
    let last = '';
    const tick = function () {
      if (!document.body.contains(row)) return;
      if (row.style.display === 'none') return;

      const ta = qs('textarea[name^="myls_faq["]', row);
      const now = ta ? String(ta.value || '') : '';
      if (now !== last) {
        last = now;
        if (!isRowEmpty(row)) uncheckDelete(row);
      }
      window.requestAnimationFrame(tick);
    };
    window.requestAnimationFrame(tick);
  }

  function revealNextBlankRow(root) {
    const blanks = qsa('.myls-faq-row-blank', root).filter(function (r) {
      return r.style.display === 'none' || getComputedStyle(r).display === 'none';
    });

    if (!blanks.length) {
      window.alert('No more blank rows are preloaded. Save the post and you will get more blanks.');
      return;
    }

    const row = blanks[0];
    row.style.display = '';
    wireRow(row);

    const q = qs('.myls-faq-q', row);
    if (q) {
      q.focus();
      q.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    const root = qs('#myls-faq-metabox-root');
    if (!root) return;

    // Wire existing visible rows.
    qsa('.myls-faq-row', root).forEach(wireRow);

    const addBtn = qs('#myls-faq-add-row');
    if (addBtn) {
      addBtn.addEventListener('click', function (e) {
        e.preventDefault();
        revealNextBlankRow(root);
      });
    }
  });
})();

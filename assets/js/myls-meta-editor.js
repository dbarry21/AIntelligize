/* AIntelligize – Meta Editor (inline Yoast Title/Description)
 * Path: assets/js/myls-meta-editor.js
 */
(function($){
  'use strict';

  const bootEl = document.getElementById('myls-meta-editor-boot');
  if (!bootEl) return;
  const BOOT = JSON.parse(bootEl.textContent || '{}');

  // DOM
  const $pt     = $('#myls_meta_pt');
  const $search = $('#myls_meta_search');
  const $rows   = $('#myls_meta_rows');
  const $prev   = $('#myls_prev');
  const $next   = $('#myls_next');
  const $pageInfo = $('#myls_page_info');
  const $countInfo= $('#myls_count_info');
  const $saveBtn  = $('#myls_meta_save');
  const $checkAll = $('#myls_check_all');

  // fields mode radios
  let fieldsMode = BOOT.fieldsMode; // 'title' | 'desc' | 'both'
  $(document).on('change', 'input[name="myls_fields_mode"]', function(){
    fieldsMode = this.value;
    fetchPage(1);
    // Toggle column visibility
    $('#myls_col_title').toggle(fieldsMode !== 'desc');
    $('#myls_col_desc').toggle(fieldsMode !== 'title');
  });

  // state
  let page = 1, maxPages = 1, perPage = BOOT.perPage;
  let typingTimer = null;
  let dirtyMap = new Map(); // id -> {yoast_title?, yoast_desc?}

  function rowHtml(item) {
    const statusBadge = item.status === 'publish' ? '' :
      `<span class="myls-table-meta">Status: ${item.status}</span>`;

    const titleInput = `
      <input
        class="myls-yoast-title"
        type="text"
        data-id="${item.id}"
        data-field="yoast_title"
        value="${_.escape(item.yoast_title || '')}"
        placeholder="Yoast SEO Title">
      <div class="myls-table-meta"><span class="myls-count" data-for="yoast_title" data-id="${item.id}">${(item.yoast_title||'').length}</span> chars</div>
    `;

    const descInput = `
      <textarea
        class="myls-yoast-desc"
        data-id="${item.id}"
        data-field="yoast_desc"
        placeholder="Yoast Meta Description">${_.escape(item.yoast_desc || '')}</textarea>
      <div class="myls-table-meta"><span class="myls-count" data-for="yoast_desc" data-id="${item.id}">${(item.yoast_desc||'').length}</span> chars</div>
    `;

    return `
      <tr data-id="${item.id}">
        <td><input type="checkbox" class="myls-row-check"></td>
        <td>
          <strong><a href="${item.edit_link}">${_.escape(item.post_title)}</a></strong><br>
          ${statusBadge}
          <div class="myls-table-meta">ID: ${item.id}</div>
        </td>
        <td class="myls-cell-title" ${fieldsMode==='desc' ? 'style="display:none;"':''}>${titleInput}</td>
        <td class="myls-cell-desc" ${fieldsMode==='title' ? 'style="display:none;"':''}>${descInput}</td>
      </tr>
    `;
  }

  function render(data){
    maxPages = data.max_pages || 1;
    page     = data.page || 1;

    if (!data.rows || !data.rows.length) {
      $rows.html('<tr><td colspan="4">No items found.</td></tr>');
    } else {
      $rows.html(data.rows.map(rowHtml).join(''));
    }

    $pageInfo.text(`Page ${page} of ${maxPages}`);
    $countInfo.text(`${data.found} items`);
    $checkAll.prop('checked', false);
  }

  function fetchPage(p){
    page = p;
    $rows.html('<tr><td colspan="4">Loading…</td></tr>');
    $.post(BOOT.ajaxUrl, {
      action: 'myls_meta_editor_fetch',
      nonce:  BOOT.nonce,
      pt:     $pt.val(),
      search: $search.val(),
      page:   page,
      per_page: perPage,
      fields: fieldsMode
    }).done(resp => {
      if (!resp || !resp.success) {
        $rows.html('<tr><td colspan="4">Error loading items.</td></tr>');
        return;
      }
      render(resp.data);
    }).fail(()=>{
      $rows.html('<tr><td colspan="4">Request failed.</td></tr>');
    });
  }

  // Save handler
  $saveBtn.on('click', function(e){
    e.preventDefault();
    // collect changed rows; if none checked, save all dirty; else only checked dirty
    const checkedIds = new Set();
    $('#myls_meta_rows .myls-row-check:checked').each(function(){
      const id = parseInt($(this).closest('tr').attr('data-id'),10);
      if (id) checkedIds.add(id);
    });

    const payload = [];
    dirtyMap.forEach((val, id)=>{
      if (checkedIds.size === 0 || checkedIds.has(id)) {
        const row = { id };
        if (typeof val.yoast_title !== 'undefined') row.yoast_title = val.yoast_title;
        if (typeof val.yoast_desc  !== 'undefined') row.yoast_desc  = val.yoast_desc;
        payload.push(row);
      }
    });

    if (payload.length === 0) {
      $('#myls_meta_save_status').text('No changes to save.');
      return;
    }

    $('#myls_meta_save_status').text('Saving…');
    $.post(BOOT.ajaxUrl, {
      action: 'myls_meta_editor_save',
      nonce:  BOOT.nonce,
      items:  payload
    }).done(resp=>{
      if (resp && resp.success) {
        $('#myls_meta_save_status').text(`Saved ${resp.data.saved} item(s).`);
        // clear dirty for saved ids
        payload.forEach(r => dirtyMap.delete(r.id));
      } else {
        $('#myls_meta_save_status').text('Save failed.');
      }
    }).fail(()=>{
      $('#myls_meta_save_status').text('Save failed (network).');
    });
  });

  // Input tracking (delegate)
  $(document).on('input', '.myls-yoast-title, .myls-yoast-desc', function(){
    const $el = $(this);
    const id  = parseInt($el.attr('data-id'),10);
    const key = $el.attr('data-field');
    const val = $el.val();

    // update counts
    $(`.myls-count[data-for="${key}"][data-id="${id}"]`).text((val||'').length);

    const row = dirtyMap.get(id) || {};
    row[key] = val;
    dirtyMap.set(id, row);
  });

  // Search debounce
  $search.on('input', function(){
    if (typingTimer) clearTimeout(typingTimer);
    typingTimer = setTimeout(()=> fetchPage(1), 350);
  });

  // PT change
  $pt.on('change', () => fetchPage(1));

  // Pagination
  $prev.on('click', (e)=>{ e.preventDefault(); if (page>1) fetchPage(page-1); });
  $next.on('click', (e)=>{ e.preventDefault(); if (page<maxPages) fetchPage(page+1); });

  // Check all
  $checkAll.on('change', function(){
    const checked = $(this).is(':checked');
    $('#myls_meta_rows .myls-row-check').prop('checked', checked);
  });

  // underscore fallback for escaping (tiny util)
  if (typeof _ === 'undefined') {
    window._ = { escape: s => String(s).replace(/[&<>"']/g, c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])) };
  }

  // Initial fetch + column visibility
  $('#myls_col_title').toggle(fieldsMode !== 'desc');
  $('#myls_col_desc').toggle(fieldsMode !== 'title');
  fetchPage(1);

})(jQuery);

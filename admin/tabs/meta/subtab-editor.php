<?php if ( ! defined('ABSPATH') ) exit;

/**
 * Subtab: Meta Editor (bulk with Focus Keyword)
 * - Saves ALL visible rows
 * - Post column narrower
 */

/* -------------------------------------------------------------------------
 * AJAX: Fetch rows
 * ------------------------------------------------------------------------- */
if ( ! has_action('wp_ajax_myls_meta_editor_fetch') ) {
  add_action('wp_ajax_myls_meta_editor_fetch', function(){
    if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized', 403);
    if ( empty($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_meta_editor') ) {
      wp_send_json_error('Bad nonce', 400);
    }

    $pt       = sanitize_key($_POST['pt'] ?? 'page');
    $search   = sanitize_text_field($_POST['search'] ?? '');
    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = min(100, max(5, intval($_POST['per_page'] ?? 20)));

    $args = [
      'post_type'      => $pt,
      'posts_per_page' => $per_page,
      'paged'          => $page,
      'post_status'    => ['publish','draft','pending','private'],
      'orderby'        => 'date',
      'order'          => 'DESC',
      'no_found_rows'  => false,
    ];
    if ( $search !== '' ) { $args['s'] = $search; }

    $q = new WP_Query($args);
    $rows = [];
    foreach ( $q->posts as $p ) {
      $rows[] = [
        'id'          => (int) $p->ID,
        'post_title'  => get_the_title($p),
        'status'      => $p->post_status,
        'yoast_title' => (string) get_post_meta($p->ID, '_yoast_wpseo_title', true),
        'yoast_desc'  => (string) get_post_meta($p->ID, '_yoast_wpseo_metadesc', true),
        'yoast_focus' => (string) get_post_meta($p->ID, '_yoast_wpseo_focuskw', true),
      ];
    }

    wp_send_json_success([
      'rows'      => $rows,
      'found'     => (int) $q->found_posts,
      'max_pages' => (int) $q->max_num_pages,
      'page'      => $page,
      'per_page'  => $per_page,
    ]);
  });
}

/* -------------------------------------------------------------------------
 * AJAX: Save rows
 * ------------------------------------------------------------------------- */
if ( ! has_action('wp_ajax_myls_meta_editor_save') ) {
  add_action('wp_ajax_myls_meta_editor_save', function(){
    if ( ! current_user_can('edit_posts') ) wp_send_json_error('Unauthorized', 403);
    if ( empty($_POST['nonce']) || ! wp_verify_nonce($_POST['nonce'], 'myls_meta_editor') ) {
      wp_send_json_error('Bad nonce', 400);
    }

    $items = isset($_POST['items']) ? $_POST['items'] : [];
    $saved = 0;

    if ( is_array($items) ) {
      foreach ( $items as $row ) {
        $id = intval($row['id'] ?? 0);
        if ( ! $id ) continue;

        if ( isset($row['yoast_title']) ) {
          update_post_meta($id, '_yoast_wpseo_title', trim(wp_kses_post($row['yoast_title'] ?? '')));
        }
        if ( isset($row['yoast_desc']) ) {
          update_post_meta($id, '_yoast_wpseo_metadesc', trim(wp_strip_all_tags($row['yoast_desc'] ?? '')));
        }
        if ( isset($row['yoast_focus']) ) {
          update_post_meta($id, '_yoast_wpseo_focuskw', sanitize_text_field($row['yoast_focus'] ?? ''));
        }
        $saved++;
      }
    }

    wp_send_json_success(['saved'=>$saved]);
  });
}

/* -------------------------------------------------------------------------
 * Subtab spec
 * ------------------------------------------------------------------------- */
return [
  'id'    => 'editor',
  'label' => 'Editor',
  'render'=> function () {
    $pts = get_post_types(['public'=>true], 'objects');
    $default_pt = isset($pts['page']) ? 'page' : array_key_first($pts);
    $nonce = wp_create_nonce('myls_meta_editor');
    $ajax  = admin_url('admin-ajax.php');
    ?>
    <style>
      .myls-two-col{display:grid;grid-template-columns:1fr 2fr;gap:20px}
      .myls-left,.myls-right{border:1px solid #000;padding:14px;border-radius:10px;background:#fff}
      .myls-section-title{font-weight:600;margin-bottom:8px;font-size:14px}
      .myls-meta-table-wrap{margin-top:16px;border:1px solid #000;padding:12px;border-radius:10px;background:#fff}
      table.myls-list-table{width:100%;border-collapse:collapse;font-size:13px}
      .myls-list-table thead th{border-bottom:1px solid #cfcfcf;text-align:left;padding:6px;background:#f6f7f7}
      .myls-list-table tbody td{border-bottom:1px solid #eee;padding:6px;vertical-align:top}
      .myls-yoast-title,.myls-yoast-focus{width:100%;height:26px;padding:2px 6px;font-size:13px}
      .myls-yoast-desc{width:100%;min-height:56px;padding:3px 6px;font-size:13px}
      .myls-table-meta{color:#646970;font-size:11px;margin-top:2px}
      .myls-actions-row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
      .myls-pagination{display:flex;gap:6px;align-items:center;margin-top:10px}
      .myls-post-title{font-weight:600;font-size:13px;margin-bottom:2px}
      #myls_col_post { width:250px; max-width:275px; }

    </style>

    <div class="myls-two-col" style="margin-bottom:16px;">
      <div class="myls-left">
        <div class="myls-section-title">Select Content</div>
        <label for="myls_meta_pt">Post Type</label>
        <select id="myls_meta_pt" style="max-width:320px;">
          <?php foreach ($pts as $pt): ?>
            <option value="<?php echo esc_attr($pt->name); ?>" <?php selected($pt->name,$default_pt); ?>>
              <?php echo esc_html($pt->labels->singular_name ?: $pt->label); ?>
            </option>
          <?php endforeach; ?>
        </select>
        <div style="margin-top:10px;">
          <label for="myls_meta_search">Search</label>
          <input type="search" id="myls_meta_search" placeholder="Search…" style="max-width:420px;">
        </div>
      </div>

      <div class="myls-right">
        <div class="myls-section-title">Actions</div>
        <div class="myls-actions-row" style="margin-bottom:8px;">
          <button id="myls_meta_save" class="button button-primary">Save Changes</button>
          <span id="myls_meta_save_status" style="color:#2271b1;"></span>
        </div>
        <div>
          <label><input type="checkbox" class="myls_field_toggle" value="title" checked> Title</label><br>
          <label><input type="checkbox" class="myls_field_toggle" value="desc" checked> Description</label><br>
          <label><input type="checkbox" class="myls_field_toggle" value="focus" checked> Focus Keyword</label>
        </div>
      </div>
    </div>

    <div style="display:flex;justify-content:flex-end;margin-bottom:8px;">
      <button id="myls_meta_csv" class="button"><i class="bi bi-filetype-csv"></i> Export to CSV</button>
    </div>

    <div class="myls-meta-table-wrap">
      <table class="myls-list-table striped">
        <thead>
          <tr>
            <th id="myls_col_post">Post</th>
            <th id="myls_col_title">Yoast Title</th>
            <th id="myls_col_desc">Yoast Description</th>
            <th id="myls_col_focus">Focus Keyword</th>
          </tr>
        </thead>
        <tbody id="myls_meta_rows">
          <tr><td colspan="4">Loading…</td></tr>
        </tbody>
      </table>
      <div class="myls-pagination">
        <button class="button" id="myls_prev">« Previous</button>
        <span id="myls_page_info">Page 1</span>
        <button class="button" id="myls_next">Next »</button>
        <span style="margin-left:auto;color:#646970" id="myls_count_info"></span>
      </div>
    </div>

    <script>
    (function(){
      const BOOT={ajaxUrl:<?php echo json_encode($ajax); ?>,nonce:<?php echo json_encode($nonce); ?>,perPage:20};
      const $=(s,c=document)=>c.querySelector(s); const $$=(s,c=document)=>Array.from(c.querySelectorAll(s));
      const pt=$('#myls_meta_pt'),search=$('#myls_meta_search'),rows=$('#myls_meta_rows'),prev=$('#myls_prev'),next=$('#myls_next'),info=$('#myls_page_info'),count=$('#myls_count_info'),save=$('#myls_meta_save'),status=$('#myls_meta_save_status');
      let page=1,maxPages=1,visible=new Set(['title','desc','focus']),typingTimer=null;

      function setCols(){
        $('#myls_col_title').style.display=visible.has('title')?'':'none';
        $('#myls_col_desc').style.display=visible.has('desc')?'':'none';
        $('#myls_col_focus').style.display=visible.has('focus')?'':'none';
        $$('.myls-cell-title').forEach(td=>td.style.display=visible.has('title')?'':'none');
        $$('.myls-cell-desc').forEach(td=>td.style.display=visible.has('desc')?'':'none');
        $$('.myls-cell-focus').forEach(td=>td.style.display=visible.has('focus')?'':'none');
      }

      function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));}
      function rowHtml(x){return `<tr data-id="${x.id}">
        <td><div class="myls-post-title">${esc(x.post_title)}</div><div class="myls-table-meta">ID:${x.id}</div></td>
        <td class="myls-cell-title"><input class="myls-yoast-title" value="${esc(x.yoast_title)}"></td>
        <td class="myls-cell-desc"><textarea class="myls-yoast-desc">${esc(x.yoast_desc)}</textarea></td>
        <td class="myls-cell-focus"><input class="myls-yoast-focus" value="${esc(x.yoast_focus)}"></td></tr>`;}
      function render(d){maxPages=d.max_pages||1;page=d.page||1;rows.innerHTML=(d.rows&&d.rows.length)?d.rows.map(rowHtml).join(''):'<tr><td colspan="4">No items.</td></tr>';info.textContent=`Page ${page} of ${maxPages}`;count.textContent=`${d.found} items`;setCols();}

      async function post(p){const fd=new FormData();for(const[k,v]of Object.entries(p)){if(Array.isArray(v))v.forEach((row,i)=>{for(const[f,val]of Object.entries(row)){fd.append(`items[${i}][${f}]`,val);}});else fd.append(k,v);}const r=await fetch(BOOT.ajaxUrl,{method:'POST',body:fd});const t=await r.text();try{return JSON.parse(t);}catch(e){throw new Error(t||'Bad JSON');}}
      async function fetchPage(p){rows.innerHTML='<tr><td colspan="4">Loading…</td></tr>';try{const r=await post({action:'myls_meta_editor_fetch',nonce:BOOT.nonce,pt:pt.value,search:search.value,page,String:p,per_page:String(BOOT.perPage)});if(!r.success)throw new Error(r.data);render(r.data);}catch(e){rows.innerHTML=`<tr><td colspan="4"><b>Error:</b>${e.message}</td></tr>`;}}
      async function saveAll(){const items=[];$$('#myls_meta_rows tr').forEach(tr=>{const id=parseInt(tr.dataset.id,10);const row={id};if(visible.has('title'))row.yoast_title=tr.querySelector('.myls-yoast-title').value;if(visible.has('desc'))row.yoast_desc=tr.querySelector('.myls-yoast-desc').value;if(visible.has('focus'))row.yoast_focus=tr.querySelector('.myls-yoast-focus').value;items.push(row);});status.textContent='Saving…';try{const r=await post({action:'myls_meta_editor_save',nonce:BOOT.nonce,items});if(!r.success)throw new Error(r.data);status.textContent=`Saved ${r.data.saved} items.`;}catch(e){status.textContent=e.message;}}
      $$('.myls_field_toggle').forEach(cb=>cb.addEventListener('change',()=>{if(cb.checked)visible.add(cb.value);else visible.delete(cb.value);setCols();}));
      search.addEventListener('input',()=>{if(typingTimer)clearTimeout(typingTimer);typingTimer=setTimeout(()=>fetchPage(1),350);});
      pt.addEventListener('change',()=>fetchPage(1));prev.addEventListener('click',e=>{e.preventDefault();if(page>1)fetchPage(page-1);});next.addEventListener('click',e=>{e.preventDefault();if(page<maxPages)fetchPage(page+1);});save.addEventListener('click',e=>{e.preventDefault();saveAll();});

      // CSV Export — exports ALL rows across all pages
      const csvBtn=$('#myls_meta_csv');
      csvBtn.addEventListener('click', async function(e){
        e.preventDefault();
        csvBtn.disabled=true;csvBtn.textContent='Exporting…';
        try {
          let allRows=[], pg=1, maxPg=1;
          do {
            const r=await post({action:'myls_meta_editor_fetch',nonce:BOOT.nonce,pt:pt.value,search:search.value,page:String(pg),per_page:'100'});
            if(!r.success) throw new Error(r.data);
            allRows=allRows.concat(r.data.rows||[]);
            maxPg=r.data.max_pages||1;
            pg++;
          } while(pg<=maxPg);

          // Build CSV
          const csvEsc=v=>{v=String(v??'');return v.includes(',')||v.includes('"')||v.includes('\n')?'"'+v.replace(/"/g,'""')+'"':v;};
          const headers=['ID','Post Title','Yoast Title','Yoast Description','Focus Keyword'];
          const lines=[headers.join(',')];
          allRows.forEach(r=>{
            lines.push([r.id,csvEsc(r.post_title),csvEsc(r.yoast_title),csvEsc(r.yoast_desc),csvEsc(r.yoast_focus)].join(','));
          });

          // Download
          const blob=new Blob(['\uFEFF'+lines.join('\r\n')],{type:'text/csv;charset=utf-8;'});
          const url=URL.createObjectURL(blob);
          const a=document.createElement('a');
          a.href=url;a.download='meta-editor-'+pt.value+'-'+new Date().toISOString().slice(0,10)+'.csv';
          document.body.appendChild(a);a.click();document.body.removeChild(a);
          URL.revokeObjectURL(url);
        } catch(e) {
          alert('CSV export failed: '+e.message);
        } finally {
          csvBtn.disabled=false;csvBtn.innerHTML='<i class="bi bi-filetype-csv"></i> Export to CSV';
        }
      });
      fetchPage(1);
    })();
    </script>
    <?php
  }
];

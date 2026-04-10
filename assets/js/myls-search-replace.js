/**
 * Bulk > Search & Replace — client-side behavior
 * File: assets/js/myls-search-replace.js
 */
(function ($) {
  "use strict";

  /* ── Bootstrap config ─────────────────────────────────────────── */
  var CFG = {};
  try {
    var raw = document.getElementById("mylsSRBootstrap");
    if (raw) CFG = JSON.parse(raw.textContent || "{}");
  } catch (e) {
    /* silent */
  }

  var ajaxurl = CFG.ajaxurl || (window.MYLS && window.MYLS.ajaxurl) || window.ajaxurl || "/wp-admin/admin-ajax.php";
  var nonce = CFG.nonce || (window.MYLS && window.MYLS.bulkNonce) || "";
  var postTypes = CFG.postTypes || [];

  /* ── DOM refs ─────────────────────────────────────────────────── */
  var $search = $("#myls_sr_search");
  var $replace = $("#myls_sr_replace");
  var $caseInsensitive = $("#myls_sr_case_insensitive");
  var $scopeContent = $("#myls_sr_scope_content");
  var $scopeTitle = $("#myls_sr_scope_title");
  var $scopeExcerpt = $("#myls_sr_scope_excerpt");
  var $scopeMeta = $("#myls_sr_scope_meta");
  var $scopeOptions = $("#myls_sr_scope_options");
  var $previewBtn = $("#myls_sr_preview_btn");
  var $executeBtn = $("#myls_sr_execute_btn");
  var $status = $("#myls_sr_status");
  var $previewArea = $("#myls_sr_preview_area");
  var $previewBadges = $("#myls_sr_preview_badges");
  var $logWrap = $("#myls_sr_log_wrap");
  var $log = $("#myls_sr_log");
  var $historyWrap = $("#myls_sr_history_wrap");
  var $ptAll = $("#myls_sr_pt_all");
  var $ptList = $("#myls_sr_pt_list");

  var lastPreviewTotal = 0;

  /* ── Render post-type checkboxes from bootstrap data ──────────── */
  (function () {
    if (!$ptList.length || !postTypes.length) return;
    var html = "";
    postTypes.forEach(function (pt) {
      html += '<div class="form-check mb-1">';
      html += '<input class="form-check-input myls-sr-pt-cb" type="checkbox" '
            + 'id="myls_sr_pt_' + pt.slug + '" value="' + pt.slug + '" checked>';
      html += '<label class="form-check-label" for="myls_sr_pt_' + pt.slug + '">'
            + escapeHtml(pt.label) + ' <code style="font-size:.75rem;">' + pt.slug + '</code></label>';
      html += '</div>';
    });
    $ptList.html(html);
  })();

  /* ── Master "All post types" toggle ──────────────────────────── */
  $ptAll.on("change", function () {
    var checked = $(this).is(":checked");
    $ptList.find(".myls-sr-pt-cb").prop("checked", checked);
    resetExecute();
  });

  $ptList.on("change", ".myls-sr-pt-cb", function () {
    var total = $ptList.find(".myls-sr-pt-cb").length;
    var checkedCount = $ptList.find(".myls-sr-pt-cb:checked").length;
    $ptAll.prop("checked", checkedCount === total);
    resetExecute();
  });

  /* ── Status checkbox change handler ──────────────────────────── */
  $(document).on("change", ".myls-sr-status-cb", function () {
    resetExecute();
  });

  /* ── Helpers ──────────────────────────────────────────────────── */
  function badge(text, color) {
    return '<span class="badge rounded-pill" style="background:' + color + ';font-size:.8rem;padding:.4em .75em;">' + text + "</span>";
  }

  function logLine(msg) {
    $logWrap.show();
    var ts = new Date().toLocaleTimeString();
    $log.text($log.text() + "\n[" + ts + "] " + msg);
    $log[0].scrollTop = $log[0].scrollHeight;
  }

  function resetExecute() {
    $executeBtn.prop("disabled", true);
    $previewArea.hide();
    lastPreviewTotal = 0;
  }

  function renderBreakdown(breakdownObj) {
    if (!breakdownObj || typeof breakdownObj !== "object") return "";
    var parts = [];
    for (var type in breakdownObj) {
      if (breakdownObj.hasOwnProperty(type) && breakdownObj[type] > 0) {
        parts.push(type + ": " + breakdownObj[type]);
      }
    }
    if (!parts.length) return "";
    return ' <span style="font-size:.75rem;color:#6b7280;">(' + parts.join(", ") + ')</span>';
  }

  function buildPayload() {
    // Collect checked post types.
    var checkedTypes = [];
    $ptList.find(".myls-sr-pt-cb:checked").each(function () {
      checkedTypes.push($(this).val());
    });

    // Collect checked post statuses.
    var checkedStatuses = [];
    $(".myls-sr-status-cb:checked").each(function () {
      checkedStatuses.push($(this).val());
    });

    return {
      action: "",
      nonce: nonce,
      search: $search.val(),
      replace: $replace.val(),
      case_insensitive: $caseInsensitive.is(":checked") ? "1" : "",
      scope_post_content: $scopeContent.is(":checked") ? "1" : "",
      scope_post_title: $scopeTitle.is(":checked") ? "1" : "",
      scope_post_excerpt: $scopeExcerpt.is(":checked") ? "1" : "",
      scope_meta_value: $scopeMeta.is(":checked") ? "1" : "",
      scope_options: $scopeOptions.is(":checked") ? "1" : "",
      post_types: checkedTypes.join(","),
      post_statuses: checkedStatuses.join(","),
    };
  }

  /* ── Reset execute button when inputs change ──────────────────── */
  $search.add($replace).add($caseInsensitive).add($scopeContent).add($scopeTitle).add($scopeExcerpt).add($scopeMeta).add($scopeOptions).on("change input", function () {
    resetExecute();
  });

  /* ── Dry Run (Preview) ────────────────────────────────────────── */
  $previewBtn.on("click", function () {
    var val = $.trim($search.val());
    if (!val) {
      $status.text("Enter a search string.");
      return;
    }

    $previewBtn.prop("disabled", true);
    $executeBtn.prop("disabled", true);
    $status.text("Scanning database...");
    $previewArea.hide();

    var data = buildPayload();
    data.action = "myls_sr_preview";

    $.post(ajaxurl, data)
      .done(function (resp) {
        if (!resp.success) {
          $status.text(resp.data && resp.data.message ? resp.data.message : "Preview failed.");
          $previewBtn.prop("disabled", false);
          return;
        }

        var d = resp.data;
        var html = "";

        if (d.post_content !== undefined && d.post_content > 0) {
          html += badge("Post Content: " + d.post_content, "#0ea5e9");
          html += renderBreakdown(d.post_content_breakdown);
        }
        if (d.post_title !== undefined && d.post_title > 0) {
          html += badge("Post Titles: " + d.post_title, "#8b5cf6");
          html += renderBreakdown(d.post_title_breakdown);
        }
        if (d.post_excerpt !== undefined && d.post_excerpt > 0) {
          html += badge("Post Excerpts: " + d.post_excerpt, "#ec4899");
          html += renderBreakdown(d.post_excerpt_breakdown);
        }
        if (d.meta_value !== undefined && d.meta_value > 0) html += badge("Post Meta: " + d.meta_value, "#059669");
        if (d.elementor !== undefined && d.elementor > 0) html += badge("Elementor: " + d.elementor, "#f59e0b");
        if (d.options !== undefined && d.options > 0) html += badge("Options: " + d.options, "#6366f1");

        if (d.total === 0) {
          html = badge("No matches found", "#6b7280");
        } else {
          html += badge("Total: " + d.total + " match(es)", "#dc2626");
        }

        $previewBadges.html(html);
        $previewArea.slideDown(200);

        lastPreviewTotal = d.total || 0;
        $executeBtn.prop("disabled", lastPreviewTotal === 0);
        $status.text(lastPreviewTotal > 0 ? "Preview complete. Ready to execute." : "No matches found.");
        $previewBtn.prop("disabled", false);
      })
      .fail(function () {
        $status.text("Request failed. Please try again.");
        $previewBtn.prop("disabled", false);
      });
  });

  /* ── Execute Replace ──────────────────────────────────────────── */
  $executeBtn.on("click", function () {
    var searchVal = $.trim($search.val());
    var replaceVal = $replace.val();

    if (!searchVal) return;

    var msg = "This will replace \"" + searchVal + "\" with \"" + replaceVal + "\" across " + lastPreviewTotal + " database row(s).\n\nThis cannot be undone. Proceed?";
    if (!confirm(msg)) return;

    $executeBtn.prop("disabled", true).html('<i class="bi bi-hourglass-split"></i> Replacing...');
    $previewBtn.prop("disabled", true);
    $log.text("Starting search & replace...");
    $logWrap.show();

    var data = buildPayload();
    data.action = "myls_sr_execute";

    $.post(ajaxurl, data)
      .done(function (resp) {
        if (!resp.success) {
          logLine("Error: " + (resp.data && resp.data.message ? resp.data.message : "Execution failed."));
          $executeBtn.prop("disabled", false).html('<i class="bi bi-arrow-repeat"></i> Execute Replace');
          $previewBtn.prop("disabled", false);
          return;
        }

        var d = resp.data;
        (d.log || []).forEach(function (line) {
          logLine(line);
        });

        $previewArea.slideUp(200);
        $status.text("Complete. " + (d.total || 0) + " replacement(s) applied.");
        $executeBtn.html('<i class="bi bi-arrow-repeat"></i> Execute Replace');
        $previewBtn.prop("disabled", false);
        // Keep execute disabled until next dry run.
      })
      .fail(function () {
        logLine("Error: Request failed. Please try again.");
        $executeBtn.prop("disabled", false).html('<i class="bi bi-arrow-repeat"></i> Execute Replace');
        $previewBtn.prop("disabled", false);
      })
      .always(function () {
        // Refresh the undo history list so the new snapshot appears.
        loadHistory();
      });
  });

  /* ── Undo History ─────────────────────────────────────────────── */
  function escapeHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function renderHistory(snapshots) {
    if (!$historyWrap.length) return;

    if (!snapshots || !snapshots.length) {
      $historyWrap.html('<p class="text-muted" style="margin:0;">No snapshots yet. Run an execute to create one.</p>');
      return;
    }

    var html = '<div class="table-responsive">';
    html += '<table class="table table-sm table-hover mb-0" style="font-size:.85rem;">';
    html += '<thead class="table-light"><tr>';
    html += '<th>Timestamp</th>';
    html += '<th>Search → Replace</th>';
    html += '<th>Scope</th>';
    html += '<th style="text-align:right;">Rows</th>';
    html += '<th>Status</th>';
    html += '<th style="text-align:right;">Actions</th>';
    html += '</tr></thead><tbody>';

    snapshots.forEach(function (s) {
      var searchDisplay  = s.search === "" ? "<em>(empty)</em>" : escapeHtml(s.search);
      var replaceDisplay = s.replace === "" ? "<em>(empty)</em>" : escapeHtml(s.replace);
      var caseLabel      = s.case_sensitive ? "" : ' <span class="text-muted" style="font-size:.75rem;">(ci)</span>';
      var statusHtml     = s.undone
        ? '<span style="color:#059669;font-weight:600;">&#8617; Undone</span>'
        : '<span style="color:#0ea5e9;font-weight:600;">&#x2713; Active</span>';

      var undoBtn = s.undone
        ? '<button class="btn btn-sm btn-outline-secondary" disabled>Undo</button>'
        : '<button class="btn btn-sm btn-warning myls-sr-undo-btn" data-snapshot-id="' + s.id + '"><i class="bi bi-arrow-counterclockwise"></i> Undo</button>';
      var delBtn = '<button class="btn btn-sm btn-outline-danger myls-sr-delete-btn" data-snapshot-id="' + s.id + '"><i class="bi bi-trash"></i></button>';

      html += '<tr>';
      html += '<td style="white-space:nowrap;">' + escapeHtml(s.created_at) + '</td>';
      html += '<td><code>' + searchDisplay + '</code> → <code>' + replaceDisplay + '</code>' + caseLabel + '</td>';
      html += '<td>' + escapeHtml(s.scope) + '</td>';
      html += '<td style="text-align:right;">' + (s.total_rows || 0) + '</td>';
      html += '<td>' + statusHtml + '</td>';
      html += '<td style="text-align:right;white-space:nowrap;"><div class="btn-group" role="group">' + undoBtn + ' ' + delBtn + '</div></td>';
      html += '</tr>';
    });

    html += '</tbody></table></div>';
    $historyWrap.html(html);
  }

  function loadHistory() {
    if (!$historyWrap.length) return;
    $.post(ajaxurl, { action: "myls_sr_list_snapshots", nonce: nonce })
      .done(function (resp) {
        if (!resp || !resp.success) {
          $historyWrap.html('<p class="text-muted" style="margin:0;">Could not load history.</p>');
          return;
        }
        renderHistory((resp.data && resp.data.snapshots) || []);
      })
      .fail(function () {
        $historyWrap.html('<p class="text-muted" style="margin:0;">Could not load history.</p>');
      });
  }

  $historyWrap.on("click", ".myls-sr-undo-btn", function () {
    var $btn = $(this);
    var id   = $btn.data("snapshot-id");
    if (!id) return;

    if (!confirm("Restore the original values from this snapshot?\n\nThis will overwrite the current values for every row in the snapshot.")) return;

    $btn.prop("disabled", true).html('<i class="bi bi-hourglass-split"></i> Undoing…');

    $.post(ajaxurl, { action: "myls_sr_undo", nonce: nonce, snapshot_id: id })
      .done(function (resp) {
        if (!resp || !resp.success) {
          var m = (resp && resp.data && resp.data.message) ? resp.data.message : "Undo failed.";
          logLine("Undo error: " + m);
          alert("Undo failed: " + m);
          loadHistory();
          return;
        }
        var d = resp.data || {};
        (d.log || []).forEach(function (line) { logLine(line); });
        $status.text("Undo complete. " + (d.restored || 0) + " entries restored.");
        loadHistory();
      })
      .fail(function () {
        logLine("Undo error: request failed.");
        alert("Undo request failed.");
        loadHistory();
      });
  });

  $historyWrap.on("click", ".myls-sr-delete-btn", function () {
    var $btn = $(this);
    var id   = $btn.data("snapshot-id");
    if (!id) return;

    if (!confirm("Delete this snapshot? This cannot be undone.")) return;

    $btn.prop("disabled", true);

    $.post(ajaxurl, { action: "myls_sr_delete_snapshot", nonce: nonce, snapshot_id: id })
      .done(function (resp) {
        if (!resp || !resp.success) {
          var m = (resp && resp.data && resp.data.message) ? resp.data.message : "Delete failed.";
          alert(m);
        }
        loadHistory();
      })
      .fail(function () {
        alert("Delete request failed.");
        loadHistory();
      });
  });

  // Initial load
  loadHistory();
})(jQuery);

/**
 * Bulk > Import / Export — client-side behavior
 * File: assets/js/myls-import-export.js
 *
 * Handles:
 *  - Export all FAQs to CSV (GET download)
 *  - Import CSV: parse & preview, then confirm
 */
(function ($) {
  "use strict";

  /* ── Bootstrap config ─────────────────────────────────────────────── */
  var CFG = {};
  try {
    var raw = document.getElementById("mylsIEBootstrap");
    if (raw) CFG = JSON.parse(raw.textContent || "{}");
  } catch (e) {
    /* silent */
  }

  var ajaxurl = CFG.ajaxurl || (window.MYLS && window.MYLS.ajaxurl) || window.ajaxurl || "/wp-admin/admin-ajax.php";
  var nonce = CFG.nonce || (window.MYLS && window.MYLS.bulkNonce) || "";

  /* ── DOM refs ─────────────────────────────────────────────────────── */
  var $exportBtn = $("#myls_ie_export_btn");
  var $importFile = $("#myls_ie_import_file");
  var $previewBtn = $("#myls_ie_preview_btn");
  var $previewStatus = $("#myls_ie_preview_status");
  var $previewArea = $("#myls_ie_preview_area");
  var $previewBadges = $("#myls_ie_preview_badges");
  var $previewTbody = $("#myls_ie_preview_tbody");
  var $confirmBtn = $("#myls_ie_confirm_btn");
  var $logWrap = $("#myls_ie_log_wrap");
  var $log = $("#myls_ie_log");

  /* ── Helpers ──────────────────────────────────────────────────────── */
  function badge(text, color) {
    return '<span class="badge rounded-pill" style="background:' + color + ';font-size:.8rem;padding:.4em .75em;">' + text + "</span>";
  }

  function statusLabel(status) {
    switch (status) {
      case "changed":
        return '<span style="color:#d97706;font-weight:600;">Changed</span>';
      case "unchanged":
        return '<span style="color:#059669;">Unchanged</span>';
      case "skipped":
        return '<span style="color:#dc2626;">Skipped</span>';
      default:
        return status;
    }
  }

  function logLine(msg) {
    $logWrap.show();
    var ts = new Date().toLocaleTimeString();
    $log.text($log.text() + "\n[" + ts + "] " + msg);
    $log[0].scrollTop = $log[0].scrollHeight;
  }

  /* ── Export ────────────────────────────────────────────────────────── */
  $exportBtn.on("click", function () {
    var url = ajaxurl + "?action=myls_ie_export_csv&nonce=" + encodeURIComponent(nonce);
    window.open(url, "_blank");
  });

  /* ── Import: file picker enables preview button ───────────────────── */
  $importFile.on("change", function () {
    var hasFile = this.files && this.files.length > 0;
    $previewBtn.prop("disabled", !hasFile);
    $previewStatus.text("");
    $previewArea.hide();
    $confirmBtn.prop("disabled", false);
  });

  /* ── Import: Parse & Preview ──────────────────────────────────────── */
  $previewBtn.on("click", function () {
    var file = $importFile[0].files[0];
    if (!file) return;

    $previewBtn.prop("disabled", true);
    $previewStatus.text("Parsing...");
    $previewArea.hide();

    var fd = new FormData();
    fd.append("action", "myls_ie_import_preview");
    fd.append("nonce", nonce);
    fd.append("csv_file", file);

    $.ajax({
      url: ajaxurl,
      method: "POST",
      data: fd,
      processData: false,
      contentType: false,
      dataType: "json",
    })
      .done(function (resp) {
        if (!resp.success) {
          $previewStatus.text(resp.data && resp.data.message ? resp.data.message : "Preview failed.");
          $previewBtn.prop("disabled", false);
          return;
        }

        var d = resp.data;

        // Summary badges.
        var html = "";
        html += badge(d.total_posts + " post(s)", "#6366f1");
        html += badge(d.total_rows + " row(s)", "#0ea5e9");
        if (d.modified > 0) html += badge(d.modified + " modified", "#d97706");
        if (d.added > 0) html += badge(d.added + " added", "#059669");
        if (d.unchanged > 0) html += badge(d.unchanged + " unchanged", "#6b7280");
        if (d.skipped > 0) html += badge(d.skipped + " skipped", "#dc2626");
        $previewBadges.html(html);

        // Per-post table.
        var rows = "";
        (d.posts || []).forEach(function (p) {
          rows += "<tr>";
          rows += "<td>" + p.id + "</td>";
          rows += "<td>" + $("<span>").text(p.title).html() + "</td>";
          rows += "<td>" + statusLabel(p.status) + "</td>";
          rows += '<td style="text-align:center;">' + (p.modified || 0) + "</td>";
          rows += '<td style="text-align:center;">' + (p.added || 0) + "</td>";
          rows += '<td style="text-align:center;">' + (p.removed || 0) + "</td>";
          rows += '<td style="text-align:center;">' + (p.same || 0) + "</td>";
          rows += "</tr>";
        });
        $previewTbody.html(rows);

        $previewStatus.text("Preview ready.");
        $previewArea.slideDown(200);
        $previewBtn.prop("disabled", false);
      })
      .fail(function (xhr) {
        var msg = "Upload failed.";
        try {
          var j = JSON.parse(xhr.responseText);
          if (j.data && j.data.message) msg = j.data.message;
        } catch (e) {
          /* silent */
        }
        $previewStatus.text(msg);
        $previewBtn.prop("disabled", false);
      });
  });

  /* ── Import: Confirm ──────────────────────────────────────────────── */
  $confirmBtn.on("click", function () {
    $confirmBtn.prop("disabled", true).html('<i class="bi bi-hourglass-split"></i> Importing...');
    $log.text("Starting import...");
    $logWrap.show();

    $.post(ajaxurl, {
      action: "myls_ie_import_confirm",
      nonce: nonce,
    })
      .done(function (resp) {
        if (!resp.success) {
          logLine("Error: " + (resp.data && resp.data.message ? resp.data.message : "Import failed."));
          $confirmBtn.prop("disabled", false).html('<i class="bi bi-check-circle"></i> Confirm Import');
          return;
        }

        var d = resp.data;
        (d.log || []).forEach(function (line) {
          logLine(line);
        });

        logLine("---");
        logLine("Done. " + (d.updated || 0) + " post(s) updated, " + (d.skipped || 0) + " skipped.");

        $previewArea.slideUp(200);
        $confirmBtn.html('<i class="bi bi-check-circle"></i> Confirm Import').prop("disabled", false);
      })
      .fail(function () {
        logLine("Error: Request failed. Please try again.");
        $confirmBtn.prop("disabled", false).html('<i class="bi bi-check-circle"></i> Confirm Import');
      });
  });
})(jQuery);

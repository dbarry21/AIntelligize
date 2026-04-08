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

  /* ── DOM refs ─────────────────────────────────────────────────── */
  var $search = $("#myls_sr_search");
  var $replace = $("#myls_sr_replace");
  var $caseInsensitive = $("#myls_sr_case_insensitive");
  var $scopeContent = $("#myls_sr_scope_content");
  var $scopeTitle = $("#myls_sr_scope_title");
  var $scopeMeta = $("#myls_sr_scope_meta");
  var $scopeOptions = $("#myls_sr_scope_options");
  var $previewBtn = $("#myls_sr_preview_btn");
  var $executeBtn = $("#myls_sr_execute_btn");
  var $status = $("#myls_sr_status");
  var $previewArea = $("#myls_sr_preview_area");
  var $previewBadges = $("#myls_sr_preview_badges");
  var $logWrap = $("#myls_sr_log_wrap");
  var $log = $("#myls_sr_log");

  var lastPreviewTotal = 0;

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

  function buildPayload() {
    return {
      action: "",
      nonce: nonce,
      search: $search.val(),
      replace: $replace.val(),
      case_insensitive: $caseInsensitive.is(":checked") ? "1" : "",
      scope_post_content: $scopeContent.is(":checked") ? "1" : "",
      scope_post_title: $scopeTitle.is(":checked") ? "1" : "",
      scope_meta_value: $scopeMeta.is(":checked") ? "1" : "",
      scope_options: $scopeOptions.is(":checked") ? "1" : "",
    };
  }

  /* ── Reset execute button when inputs change ──────────────────── */
  $search.add($replace).add($caseInsensitive).add($scopeContent).add($scopeTitle).add($scopeMeta).add($scopeOptions).on("change input", function () {
    $executeBtn.prop("disabled", true);
    $previewArea.hide();
    lastPreviewTotal = 0;
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

        if (d.post_content !== undefined && d.post_content > 0) html += badge("Post Content: " + d.post_content, "#0ea5e9");
        if (d.post_title !== undefined && d.post_title > 0) html += badge("Post Titles: " + d.post_title, "#8b5cf6");
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
      });
  });
})(jQuery);

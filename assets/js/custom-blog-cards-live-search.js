/**
 * Live AJAX search for [custom_blog_cards]
 * - Shows top 5 results while typing
 * - Debounced input
 * - Click result => navigate to post
 */
(function ($) {
  "use strict";

  function escapeHtml(str) {
    return String(str || "")
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  function debounce(fn, wait) {
    let t;
    return function () {
      const ctx = this;
      const args = arguments;
      clearTimeout(t);
      t = setTimeout(function () {
        fn.apply(ctx, args);
      }, wait);
    };
  }

  function renderResults($box, items) {
    if (!items || !items.length) {
      $box.hide().empty();
      return;
    }

    let html = "";
    items.forEach(function (it) {
      const title = escapeHtml(it.title);
      const url = escapeHtml(it.url);
      const date = escapeHtml(it.date);
      const thumb = it.thumb ? escapeHtml(it.thumb) : "";

      html += '<a href="' + url + '" class="list-group-item list-group-item-action custom-blog-cards-search-item">';
      html += '  <div class="d-flex align-items-center gap-2">';
      if (thumb) {
        html += '    <img src="' + thumb + '" alt="" class="custom-blog-cards-search-thumb" loading="lazy" decoding="async">';
      }
      html += '    <div class="flex-grow-1">';
      html += '      <div class="custom-blog-cards-search-title">' + title + "</div>";
      html += '      <div class="small text-muted">' + date + "</div>";
      html += "    </div>";
      html += "  </div>";
      html += "</a>";
    });

    $box.html(html).show();
  }

  $(document).ready(function () {
    if (typeof CUSTOM_BLOG_CARDS_LIVE_SEARCH === "undefined") return;

    const cfg = CUSTOM_BLOG_CARDS_LIVE_SEARCH;
    const minChars = parseInt(cfg.minChars || 2, 10);
    const debounceMs = parseInt(cfg.debounceMs || 200, 10);

    $(".custom-blog-cards-search").each(function () {
      const $wrap = $(this);
      const $input = $wrap.find(".custom-blog-cards-search-input");
      const $results = $wrap.find(".custom-blog-cards-search-results");

      const doSearch = debounce(function () {
        const term = ($input.val() || "").trim();

        // Hide if too short
        if (term.length < minChars) {
          $results.hide().empty();
          return;
        }

        $.post(cfg.ajaxurl, {
          action: "custom_blog_cards_live_search",
          nonce: cfg.nonce,
          term: term
        })
          .done(function (res) {
            if (!res || !res.success) {
              $results.hide().empty();
              return;
            }

            const items = (res.data && res.data.items) ? res.data.items : [];
            renderResults($results, items.slice(0, 5));
          })
          .fail(function () {
            $results.hide().empty();
          });
      }, debounceMs);

      // Real-time input
      $input.on("input", doSearch);

      // Close results when clicking outside
      $(document).on("click", function (e) {
        if (!$.contains($wrap[0], e.target)) {
          $results.hide();
        }
      });

      // Escape closes results
      $input.on("keydown", function (e) {
        if (e.key === "Escape") {
          $results.hide();
        }
      });
    });
  });
})(jQuery);

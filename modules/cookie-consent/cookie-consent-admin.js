/**
 * AIntelligize – Cookie Consent Admin JS
 *
 * Renders live preview using inline styles so no frontend CSS dependency.
 * Works with the existing tab PHP element IDs (ccb_theme, ccb_position, etc.)
 *
 * @since 7.8.58
 */
(function () {
    'use strict';

    /* ── Theme colour maps ───────────────────────────────────────────── */
    var THEMES = {
        dark:    { bg: '#1a1a2e', text: '#eeeeee', btnBg: '#4f8ef7',  btnText: '#ffffff', declineBorder: '#555555',  declineText: '#aaaaaa' },
        light:   { bg: '#ffffff', text: '#333333', btnBg: '#2563eb',  btnText: '#ffffff', declineBorder: '#cccccc',  declineText: '#666666' },
        glass:   { bg: 'rgba(255,255,255,0.15)', text: '#ffffff', btnBg: 'rgba(100,160,255,0.85)', btnText: '#ffffff', declineBorder: 'rgba(255,255,255,0.4)', declineText: 'rgba(255,255,255,0.8)' },
        minimal: { bg: '#f8f8f8', text: '#444444', btnBg: '#222222',  btnText: '#ffffff', declineBorder: 'transparent', declineText: '#888888' },
    };

    /* ── Tiny helpers ────────────────────────────────────────────────── */
    function byId(id) { return document.getElementById(id); }
    function val(id)  { var el = byId(id); return el ? el.value : ''; }
    function chk(id)  { var el = byId(id); return el ? el.checked : false; }

    function escHtml(s) {
        return String(s)
            .replace(/&/g,'&amp;').replace(/</g,'&lt;')
            .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    /* ── Build colour config for current settings ────────────────────── */
    function getColors() {
        var theme = val('ccb_theme') || 'dark';
        if (theme === 'branded') {
            return {
                bg:            val('ccb_banner_bg')          || '#1a1a2e',
                text:          val('ccb_banner_text_color')  || '#eeeeee',
                btnBg:         val('ccb_button_bg')          || '#4f8ef7',
                btnText:       val('ccb_button_text_color')  || '#ffffff',
                declineBorder: val('ccb_banner_text_color')  || '#eeeeee',
                declineText:   val('ccb_banner_text_color')  || '#eeeeee',
            };
        }
        return THEMES[theme] || THEMES.dark;
    }

    /* ── Button HTML helpers ─────────────────────────────────────────── */
    function btnAccept(c, label, extraStyle) {
        return '<button type="button" style="background:' + c.btnBg + ';color:' + c.btnText + ';' +
               'border:none;border-radius:6px;cursor:pointer;font-weight:600;font-family:inherit;' +
               (extraStyle || '') + '">' + escHtml(label) + '</button>';
    }

    function btnDecline(c, label, extraStyle) {
        return '<button type="button" style="background:transparent;color:' + c.declineText + ';' +
               'border:2px solid ' + c.declineBorder + ';' +
               'border-radius:6px;cursor:pointer;font-family:inherit;' +
               (extraStyle || '') + '">' + escHtml(label) + '</button>';
    }

    /**
     * Privacy link helper.
     * `url` is now the page ID from the <select> (numeric string or "0").
     * Any non-empty, non-"0" value means a page is selected — show the link.
     */
    function privacyLink(c, url, label) {
        if (!url || url === '0') return '';
        return ' <a href="#" style="color:' + c.btnBg + ';text-decoration:underline;font-size:12px;">' +
               escHtml(label || 'Privacy Policy') + '</a>';
    }

    /* ════════════════════════════════════════════════════════════════════
       DESKTOP PREVIEW  — full-width bar, text left / buttons right
    ═════════════════════════════════════════════════════════════════════ */
    function renderDesktop(c, msg, accept, decline, showDecline, pvUrl, pvLbl) {
        var el = byId('ccb-preview-banner-desktop');
        if (!el) return;

        el.setAttribute('style',
            'display:flex;flex-direction:row;flex-wrap:wrap;align-items:center;' +
            'justify-content:space-between;gap:10px;box-sizing:border-box;' +
            'width:100%;padding:12px 18px;border-radius:6px;' +
            'background:' + c.bg + ';color:' + c.text + ';' +
            'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;'
        );

        el.innerHTML =
            '<div style="flex:1;min-width:0;font-size:13px;line-height:1.5;">' +
                escHtml(msg) + privacyLink(c, pvUrl, pvLbl) +
            '</div>' +
            '<div style="display:flex;gap:8px;flex-shrink:0;">' +
                btnAccept(c,  accept,  'padding:8px 18px;font-size:13px;min-height:36px;') +
                (showDecline ? btnDecline(c, decline, 'padding:8px 14px;font-size:13px;min-height:36px;') : '') +
            '</div>';
    }

    /* ════════════════════════════════════════════════════════════════════
       MOBILE PREVIEW  — slim bar: small text row, side-by-side buttons
       Target: compact, unobtrusive in 320px frame
    ═════════════════════════════════════════════════════════════════════ */
    function renderMobile(c, msg, accept, decline, showDecline, pvUrl, pvLbl) {
        var el = byId('ccb-preview-banner');
        if (!el) return;

        el.setAttribute('style',
            'display:flex;flex-direction:column;gap:7px;box-sizing:border-box;' +
            'width:100%;padding:8px 10px 10px;' +
            'background:' + c.bg + ';color:' + c.text + ';' +
            'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;' +
            'position:relative;transform:none;'  // override any frontend positioning
        );

        el.innerHTML =
            // Text row — small, centered
            '<p style="margin:0;font-size:10px;line-height:1.35;text-align:center;color:' + c.text + ';">' +
                escHtml(msg) +
                (pvUrl && pvUrl !== '0' ? ' <a href="#" style="color:' + c.btnBg + ';text-decoration:underline;font-size:10px;">' + escHtml(pvLbl) + '</a>' : '') +
            '</p>' +
            // Button row — side-by-side, each 50%
            '<div style="display:flex;flex-direction:row;gap:6px;width:100%;">' +
                btnAccept(c,  accept,  'flex:1 1 0;padding:6px 4px;font-size:11px;min-height:30px;text-align:center;') +
                (showDecline ? btnDecline(c, decline, 'flex:1 1 0;padding:6px 4px;font-size:11px;min-height:30px;text-align:center;') : '') +
            '</div>';
    }

    /* ── Toggle branded colour input visibility ──────────────────────── */
    function toggleBranded() {
        var fields = byId('ccb-branded-fields');
        if (fields) {
            fields.style.display = (val('ccb_theme') === 'branded') ? '' : 'none';
        }
    }

    /* ── Master update (called on every input change + on load) ─────── */
    function update() {
        var c          = getColors();
        var msg        = val('ccb_message')       || 'We use cookies to improve your experience.';
        var accept     = val('ccb_accept_label')  || 'Accept';
        var decline    = val('ccb_decline_label') || 'Decline';
        var showD      = chk('ccb_decline_button');
        var pvUrl      = val('ccb_privacy_url');
        var pvLbl      = val('ccb_privacy_label') || 'Privacy Policy';

        renderDesktop(c, msg, accept, decline, showD, pvUrl, pvLbl);
        renderMobile( c, msg, accept, decline, showD, pvUrl, pvLbl);
        toggleBranded();
    }

    /* ── Init ────────────────────────────────────────────────────────── */
    document.addEventListener('DOMContentLoaded', function () {
        // Only run when the preview elements exist (Settings subtab)
        if (!byId('ccb-preview-banner') && !byId('ccb-preview-banner-desktop')) return;

        var watchIds = [
            'ccb_theme', 'ccb_position', 'ccb_message',
            'ccb_accept_label', 'ccb_decline_label', 'ccb_decline_button',
            'ccb_privacy_url', 'ccb_privacy_label',
            'ccb_banner_bg', 'ccb_banner_text_color',
            'ccb_button_bg', 'ccb_button_text_color'
        ];

        watchIds.forEach(function (id) {
            var el = byId(id);
            if (el) {
                el.addEventListener('input',  update);
                el.addEventListener('change', update);
            }
        });

        // Initial render on page load
        update();
    });

}());

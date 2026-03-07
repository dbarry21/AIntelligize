/**
 * AIntelligize – Cookie Consent Banner
 * Frontend JavaScript
 *
 * Features:
 *  - Configurable delay before showing
 *  - Cookie/localStorage consent storage
 *  - Accept / Decline support
 *  - GDPR script unblocking:
 *      <script type="text/plain" data-ccb-category="analytics|marketing|functional">
 *  - Re-consent if cookie expired
 */
(function () {
    'use strict';

    var cfg = window.aintelligize_ccb || {};
    var COOKIE_NAME = cfg.cookie_name || 'aintelligize_consent';
    var EXPIRE_DAYS = parseInt(cfg.expire_days, 10) || 180;
    var DELAY_MS    = parseInt(cfg.delay, 10)       || 0;
    var SCRIPT_BLOCKING = cfg.script_blocking === '1' || cfg.script_blocking === true;

    /* ── Helpers ────────────────────────────────────────────────── */
    function setCookie(name, value, days) {
        var d = new Date();
        d.setTime(d.getTime() + days * 24 * 60 * 60 * 1000);
        document.cookie = name + '=' + encodeURIComponent(value)
            + '; expires=' + d.toUTCString()
            + '; path=/; SameSite=Lax';
    }

    function getCookie(name) {
        var match = document.cookie.match(
            new RegExp('(?:^|; )' + name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '=([^;]*)')
        );
        return match ? decodeURIComponent(match[1]) : null;
    }

    function getConsent() {
        return getCookie(COOKIE_NAME);
    }

    /* ── Script Unblocking ──────────────────────────────────────── */
    function activateScripts(categories) {
        // categories: array like ['analytics','marketing'] or ['all']
        var blocked = document.querySelectorAll('script[type="text/plain"][data-ccb-category]');
        blocked.forEach(function (el) {
            var cat = el.getAttribute('data-ccb-category') || 'analytics';
            if (categories.indexOf('all') > -1 || categories.indexOf(cat) > -1) {
                var newScript = document.createElement('script');
                // Copy all attributes except type
                Array.prototype.forEach.call(el.attributes, function (attr) {
                    if (attr.name !== 'type') {
                        newScript.setAttribute(attr.name, attr.value);
                    }
                });
                newScript.type = 'text/javascript';
                if (!el.src && !newScript.src) {
                    newScript.textContent = el.textContent;
                }
                el.parentNode.replaceChild(newScript, el);
            }
        });
    }

    /* ── Banner ─────────────────────────────────────────────────── */
    function hideBanner(banner) {
        banner.style.transition = 'transform 0.35s ease, opacity 0.35s ease';
        banner.classList.remove('ccb-visible');
        setTimeout(function () {
            if (banner.parentNode) {
                banner.parentNode.removeChild(banner);
            }
        }, 400);
    }

    function showBanner() {
        var banner = document.getElementById('aintelligize-ccb');
        if (!banner) return;

        // Show the banner
        banner.style.display = 'flex';
        // Trigger reflow before adding class so transition fires
        void banner.offsetHeight;
        banner.classList.add('ccb-visible');

        /* Accept */
        var acceptBtn = document.getElementById('ccb-accept');
        if (acceptBtn) {
            acceptBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'accepted', EXPIRE_DAYS);
                hideBanner(banner);
                if (SCRIPT_BLOCKING) {
                    activateScripts(['all']);
                }
                // Fire custom event for GTM / other integrations
                document.dispatchEvent(new CustomEvent('ccb:accepted'));
            });
        }

        /* Decline */
        var declineBtn = document.getElementById('ccb-decline');
        if (declineBtn) {
            declineBtn.addEventListener('click', function () {
                setCookie(COOKIE_NAME, 'declined', EXPIRE_DAYS);
                hideBanner(banner);
                // Only activate functional (essential) scripts on decline
                if (SCRIPT_BLOCKING) {
                    activateScripts(['functional']);
                }
                document.dispatchEvent(new CustomEvent('ccb:declined'));
            });
        }
    }

    /* ── Init ───────────────────────────────────────────────────── */
    function init() {
        var existing = getConsent();

        if (existing === 'accepted') {
            // User already accepted — activate scripts immediately
            if (SCRIPT_BLOCKING) {
                activateScripts(['all']);
            }
            return; // don't show banner
        }

        if (existing === 'declined') {
            // Declined — only fire functional
            if (SCRIPT_BLOCKING) {
                activateScripts(['functional']);
            }
            return; // don't show banner
        }

        // No consent on record — show banner after delay
        if (DELAY_MS > 0) {
            setTimeout(showBanner, DELAY_MS);
        } else {
            // Still defer until DOM is ready but no visual delay
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', showBanner);
            } else {
                showBanner();
            }
        }
    }

    // Run after DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();

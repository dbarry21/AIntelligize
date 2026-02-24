/**
 * MYLS Export Log – exports terminal results to a print-friendly HTML window
 *
 * Opens a styled popup window with parsed, color-coded log output and
 * auto-triggers the browser's Print dialog (Save as PDF). No CDN dependencies.
 *
 * Usage:
 *   <button class="myls-btn-export-pdf" data-log-target="myls_ai_about_results">
 *     <i class="bi bi-file-earmark-pdf"></i> PDF
 *   </button>
 *
 * The data-log-target attribute must match the ID of the results <pre>/<div>.
 *
 * @since 6.3.2.1
 */
(function(){
  'use strict';

  function getTimestamp() {
    var d = new Date();
    var pad = function(n){ return n < 10 ? '0'+n : ''+n; };
    return d.getFullYear()+'-'+pad(d.getMonth()+1)+'-'+pad(d.getDate())+' '+
           pad(d.getHours())+':'+pad(d.getMinutes());
  }

  function getTabLabel() {
    var active = document.querySelector('.myls-subtab-btn.active, .nav-link.active');
    if (active) return active.textContent.trim().replace(/[^a-zA-Z0-9 ]/g, '').trim();
    return 'Results';
  }

  /** Classify a line for color-coding */
  function classifyLine(line) {
    var t = line.trim();
    if (/^[━═─]{4,}/.test(t))           return 'sep';
    if (/^━{2,}\s*\[/.test(t))          return 'section-head';
    if (/^─{2,}\s/.test(t))             return 'sub-head';
    if (/^[╔╚║]/.test(t))               return 'banner';
    if (/[✅✔]|saved|SUCCESS|Done\./i.test(t) && !/error/i.test(t)) return 'success';
    if (/❌|ERROR|FAIL/i.test(t))       return 'error';
    if (/⚠|warn/i.test(t))             return 'warn';
    if (/⏭|skip/i.test(t))             return 'skip';
    if (/^\s{2,}/.test(line) && /[🤖📊💰📥📤⚡📝🔄🔍📈📉🧬🔀🛡]/.test(line)) return 'detail';
    if (/^\s{2,}/.test(line) && /[│┃]/.test(line)) return 'detail';
    return '';
  }

  function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }

  /** Parse raw log text into classified HTML lines */
  function parseLog(text) {
    var lines = text.split('\n');
    var html = [];
    for (var i = 0; i < lines.length; i++) {
      var line = lines[i];
      var cls = classifyLine(line);
      var escaped = esc(line);
      if (cls === 'sep') {
        html.push('<div class="line sep" aria-hidden="true"></div>');
      } else if (cls) {
        html.push('<div class="line ' + cls + '">' + escaped + '</div>');
      } else {
        html.push('<div class="line">' + escaped + '</div>');
      }
    }
    return html.join('\n');
  }

  /** Build the full HTML document for the print window */
  function buildDocument(text, tabLabel) {
    var timestamp = getTimestamp();
    var siteName = document.title.replace(/\s*[–—|].*/,'').trim() || 'AIntelligize';
    var logHtml = parseLog(text);

    return '<!DOCTYPE html>\n<html lang="en">\n<head>\n<meta charset="utf-8">\n' +
    '<title>MYLS – ' + esc(tabLabel) + ' Log – ' + esc(timestamp) + '</title>\n' +
    '<style>\n' +
    '@page { size: A4; margin: 15mm 12mm 18mm 12mm; }\n' +
    '* { box-sizing: border-box; margin: 0; padding: 0; }\n' +
    'body {\n' +
    '  font-family: "Cascadia Code", "Fira Code", "JetBrains Mono", "SF Mono", Consolas, "Liberation Mono", monospace;\n' +
    '  font-size: 9px; line-height: 1.55; color: #1d2327; background: #fff; padding: 12px;\n' +
    '}\n' +
    '.header {\n' +
    '  display: flex; justify-content: space-between; align-items: center;\n' +
    '  border-bottom: 2px solid #1d2327; padding-bottom: 10px; margin-bottom: 14px;\n' +
    '}\n' +
    '.header h1 { font-size: 15px; font-weight: 700; }\n' +
    '.header .meta { font-size: 10px; color: #666; text-align: right; }\n' +
    '.header .meta div { margin-bottom: 2px; }\n' +
    '.log-body { white-space: pre-wrap; word-break: break-word; }\n' +
    '.line { padding: 0.5px 0; page-break-inside: avoid; }\n' +
    '.line.sep { border-bottom: 1px solid #ddd; margin: 6px 0; height: 0; overflow: hidden; }\n' +
    '.line.section-head {\n' +
    '  background: #1d2327; color: #fff; font-weight: 700; font-size: 10px;\n' +
    '  padding: 5px 8px; margin: 12px 0 4px 0; border-radius: 3px; page-break-after: avoid;\n' +
    '}\n' +
    '.line.sub-head {\n' +
    '  font-weight: 700; color: #2271b1; font-size: 9.5px;\n' +
    '  margin: 8px 0 2px 0; border-bottom: 1px solid #e8f2fa; padding-bottom: 2px; page-break-after: avoid;\n' +
    '}\n' +
    '.line.banner {\n' +
    '  background: #f8f9fa; border-left: 3px solid #2271b1;\n' +
    '  padding: 2px 8px; font-weight: 600; color: #1d2327;\n' +
    '}\n' +
    '.line.success { color: #007017; font-weight: 600; }\n' +
    '.line.error   { color: #d63638; font-weight: 600; }\n' +
    '.line.warn    { color: #996800; }\n' +
    '.line.skip    { color: #787c82; font-style: italic; }\n' +
    '.line.info    { color: #2271b1; }\n' +
    '.line.detail  { color: #50575e; padding-left: 4px; }\n' +
    '.footer {\n' +
    '  margin-top: 20px; padding-top: 8px; border-top: 1px solid #e0e0e0;\n' +
    '  font-size: 8px; color: #999; display: flex; justify-content: space-between;\n' +
    '}\n' +
    '@media print {\n' +
    '  body { font-size: 8.5px; padding: 0; }\n' +
    '  .no-print { display: none !important; }\n' +
    '  .line.section-head, .line.banner {\n' +
    '    -webkit-print-color-adjust: exact; print-color-adjust: exact;\n' +
    '  }\n' +
    '}\n' +
    '.toolbar {\n' +
    '  position: sticky; top: 0; background: #fff; border-bottom: 1px solid #e0e0e0;\n' +
    '  padding: 8px 0; margin-bottom: 12px; display: flex; gap: 8px; align-items: center; z-index: 10;\n' +
    '  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;\n' +
    '}\n' +
    '.toolbar button {\n' +
    '  padding: 6px 16px; border: 1px solid #2271b1; background: #2271b1;\n' +
    '  color: #fff; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600;\n' +
    '}\n' +
    '.toolbar button:hover { background: #135e96; }\n' +
    '.toolbar button.sec { background: #fff; color: #2271b1; }\n' +
    '.toolbar button.sec:hover { background: #f0f0f1; }\n' +
    '.toolbar .spacer { flex: 1; }\n' +
    '.toolbar .hint { font-size: 12px; color: #787c82; }\n' +
    '</style>\n</head>\n<body>\n' +
    '<div class="toolbar no-print">\n' +
    '  <button onclick="window.print()">🖨 Print / Save as PDF</button>\n' +
    '  <button class="sec" onclick="copyAll()">📋 Copy All</button>\n' +
    '  <div class="spacer"></div>\n' +
    '  <span class="hint">Tip: Choose &ldquo;Save as PDF&rdquo; in the print dialog</span>\n' +
    '</div>\n' +
    '<div class="header">\n' +
    '  <h1>AIntelligize – ' + esc(tabLabel) + ' Log</h1>\n' +
    '  <div class="meta">\n' +
    '    <div><strong>Exported:</strong> ' + esc(timestamp) + '</div>\n' +
    '    <div><strong>Site:</strong> ' + esc(siteName) + '</div>\n' +
    '  </div>\n' +
    '</div>\n' +
    '<div class="log-body">\n' + logHtml + '\n</div>\n' +
    '<div class="footer">\n' +
    '  <span>Generated by AIntelligize Plugin</span>\n' +
    '  <span>' + esc(timestamp) + '</span>\n' +
    '</div>\n' +
    '<script>\n' +
    'function copyAll(){\n' +
    '  var b=document.querySelector(".log-body"),r=document.createRange();\n' +
    '  r.selectNodeContents(b);var s=window.getSelection();s.removeAllRanges();s.addRange(r);\n' +
    '  document.execCommand("copy");s.removeAllRanges();alert("Log copied to clipboard!");\n' +
    '}\n' +
    '</script>\n</body>\n</html>';
  }

  /** Main export function */
  function exportLog(targetId) {
    var el = document.getElementById(targetId);
    if (!el) { alert('Results log not found.'); return; }

    var text = (el.innerText || el.textContent || '').trim();
    if (!text || text === 'Ready.' || text === 'Ready') {
      alert('No results to export yet. Run a generation first.');
      return;
    }

    var tabLabel = getTabLabel();
    var html = buildDocument(text, tabLabel);

    var win = window.open('', '_blank', 'width=900,height=700,scrollbars=yes,resizable=yes');
    if (!win) {
      alert('Popup blocked! Please allow popups for this site and try again.');
      return;
    }

    win.document.write(html);
    win.document.close();

    // Auto-trigger print after brief render delay
    win.setTimeout(function(){ win.print(); }, 400);
  }

  // ── Event delegation ──
  document.addEventListener('click', function(e) {
    var btn = e.target.closest('.myls-btn-export-pdf');
    if (!btn) return;
    e.preventDefault();
    var target = btn.getAttribute('data-log-target');
    if (target) exportLog(target);
  });

})();

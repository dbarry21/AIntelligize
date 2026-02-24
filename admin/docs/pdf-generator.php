<?php
/**
 * Simple PDF Generator for AIntelligize Documentation
 * File: admin/docs/pdf-generator.php
 * 
 * Uses basic PDF generation without external libraries
 */

if (!defined('ABSPATH')) exit;

class MLSEO_PDF_Generator {
    
    /**
     * Generate PDF from HTML content
     * Uses browser's print-to-PDF functionality via data URI
     */
    public static function generate_shortcodes_pdf($shortcodes) {
        // Generate clean HTML suitable for PDF
        $html = self::generate_pdf_html($shortcodes);
        
        // Set headers for PDF download
        $filename = 'aintelligize-shortcodes-' . gmdate('Ymd-His') . '.pdf';
        
        // Since we can't generate actual PDF without a library,
        // we'll use the browser's print-to-PDF capability
        // by sending optimized HTML with print styles
        nocache_headers();
        header('Content-Type: text/html; charset=utf-8');
        
        // Output HTML with auto-print script
        echo self::get_printable_html($html, $filename);
        exit;
    }
    
    /**
     * Generate printable HTML with auto-print functionality
     */
    private static function get_printable_html($content, $filename) {
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>AIntelligize - Shortcodes Reference</title>
    <style>
        /* Print-optimized styles */
        @page {
            size: A4;
            margin: 2cm;
        }
        
        @media print {
            body {
                margin: 0;
                padding: 0;
            }
            
            .no-print {
                display: none !important;
            }
            
            .shortcode {
                page-break-inside: avoid;
            }
            
            h2 {
                page-break-after: avoid;
            }
            
            table {
                page-break-inside: avoid;
            }
        }
        
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            margin: 0;
            padding: 20px;
            background: white;
            color: #333;
        }
        
        .print-header {
            background: #2271b1;
            color: white;
            padding: 30px;
            margin: -20px -20px 30px -20px;
        }
        
        .print-header h1 {
            margin: 0 0 10px 0;
            font-size: 32px;
        }
        
        .print-header p {
            margin: 5px 0;
            opacity: 0.9;
        }
        
        .print-instructions {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 20px;
            margin: 20px 0;
            border-radius: 4px;
        }
        
        .print-instructions h3 {
            margin-top: 0;
            color: #856404;
        }
        
        .print-instructions ol {
            margin: 10px 0;
            padding-left: 20px;
        }
        
        .print-instructions li {
            margin: 5px 0;
        }
        
        button {
            background: #2271b1;
            color: white;
            border: none;
            padding: 12px 24px;
            font-size: 16px;
            border-radius: 4px;
            cursor: pointer;
            margin-right: 10px;
        }
        
        button:hover {
            background: #135e96;
        }
        
        button.secondary {
            background: #6c757d;
        }
        
        button.secondary:hover {
            background: #5a6268;
        }
        
        h2 {
            color: #2271b1;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
            margin-top: 40px;
        }
        
        .shortcode {
            margin-bottom: 40px;
            border-left: 4px solid #e0e0e0;
            padding-left: 20px;
        }
        
        .shortcode-name {
            font-family: 'Courier New', monospace;
            font-size: 20px;
            font-weight: bold;
            color: #2271b1;
            margin-bottom: 10px;
        }
        
        .category {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            color: white;
            margin-bottom: 15px;
            text-transform: uppercase;
        }
        
        .cat-location { background: #4CAF50; }
        .cat-services { background: #2196F3; }
        .cat-content { background: #FF9800; }
        .cat-schema { background: #9C27B0; }
        .cat-social { background: #00BCD4; }
        .cat-utility { background: #607D8B; }
        
        .description {
            margin: 15px 0;
            line-height: 1.6;
        }
        
        .usage {
            background: #f5f5f5;
            padding: 12px;
            font-family: 'Courier New', monospace;
            margin: 15px 0;
            border-left: 3px solid #2271b1;
            font-size: 13px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
            font-size: 13px;
        }
        
        th {
            background: #f0f0f1;
            text-align: left;
            padding: 10px;
            border: 1px solid #ddd;
            font-weight: 600;
        }
        
        td {
            padding: 10px;
            border: 1px solid #ddd;
            vertical-align: top;
        }
        
        .attr-name {
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #d63638;
        }
        
        .attr-default {
            font-family: 'Courier New', monospace;
            background: #f9f9f9;
            padding: 3px 8px;
            border-radius: 3px;
        }
        
        .example {
            background: #f9f9f9;
            padding: 12px;
            margin: 8px 0;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            border-left: 3px solid #6c757d;
        }
        
        .example strong {
            display: block;
            margin-bottom: 5px;
            color: #333;
        }
        
        .tips {
            background: #e7f3ff;
            border-left: 3px solid #2196F3;
            padding: 15px;
            margin: 15px 0;
        }
        
        .tips strong {
            color: #0d47a1;
        }
        
        .tips ul {
            margin: 10px 0 0 0;
            padding-left: 20px;
        }
        
        .tips li {
            margin: 5px 0;
        }
    </style>
</head>
<body>
    <div class="print-header">
        <h1>AIntelligize - Shortcodes Reference</h1>
        <p><strong>Generated:</strong> <?php echo date('F j, Y g:i a'); ?></p>
        <p><strong>Plugin Version:</strong> <?php echo defined('MYLS_VERSION') ? MYLS_VERSION : '4.10.1'; ?></p>
    </div>
    
    <div class="print-instructions no-print">
        <h3>📄 How to Save as PDF</h3>
        <p><strong>Option 1: Auto-Print (Recommended)</strong></p>
        <ol>
            <li>Click the "Print to PDF" button below</li>
            <li>In the print dialog, select "Save as PDF" or "Microsoft Print to PDF"</li>
            <li>Choose save location and click Save</li>
        </ol>
        
        <p><strong>Option 2: Manual Print</strong></p>
        <ol>
            <li>Press Ctrl+P (Windows) or Cmd+P (Mac)</li>
            <li>Select "Save as PDF" as destination</li>
            <li>Click Save</li>
        </ol>
        
        <div style="margin-top: 20px;">
            <button onclick="window.print();">🖨️ Print to PDF</button>
            <button class="secondary" onclick="window.close();">✕ Close Window</button>
        </div>
    </div>
    
    <?php echo $content; ?>
    
    <script>
    // Auto-open print dialog after 1 second
    setTimeout(function() {
        if (confirm('Ready to save as PDF? Click OK to open the print dialog.')) {
            window.print();
        }
    }, 1000);
    </script>
</body>
</html>
        <?php
        return ob_get_clean();
    }
    
    /**
     * Generate PDF content HTML
     */
    private static function generate_pdf_html($shortcodes) {
        $categories = [
            'location' => 'Location & Geography',
            'services' => 'Services & Service Areas',
            'content' => 'Content Display',
            'schema' => 'Schema & SEO',
            'social' => 'Social & Sharing',
            'utility' => 'Utility & Tools'
        ];
        
        ob_start();
        
        foreach ($categories as $cat_key => $cat_label):
            $cat_shortcodes = array_filter($shortcodes, fn($sc) => $sc['category'] === $cat_key);
            if (empty($cat_shortcodes)) continue;
        ?>
        
        <h2><?php echo esc_html($cat_label); ?></h2>
        
        <?php foreach ($cat_shortcodes as $sc): ?>
            <div class="shortcode">
                <div class="shortcode-name">[<?php echo esc_html($sc['name']); ?>]</div>
                <div class="category cat-<?php echo esc_attr($sc['category']); ?>">
                    <?php echo esc_html($cat_label); ?>
                </div>
                
                <div class="description">
                    <?php echo esc_html($sc['description']); ?>
                </div>
                
                <div class="usage">
                    <strong>Basic Usage:</strong> <?php echo esc_html($sc['basic_usage']); ?>
                </div>
                
                <?php if (!empty($sc['attributes'])): ?>
                    <h3 style="font-size: 16px; margin-top: 20px;">Attributes</h3>
                    <table>
                        <thead>
                            <tr>
                                <th width="25%">Attribute</th>
                                <th width="20%">Default</th>
                                <th width="55%">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sc['attributes'] as $attr => $info): ?>
                                <tr>
                                    <td class="attr-name"><?php echo esc_html($attr); ?></td>
                                    <td><span class="attr-default"><?php echo esc_html($info['default'] ?? '—'); ?></span></td>
                                    <td><?php echo esc_html($info['description'] ?? ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
                
                <?php if (!empty($sc['examples'])): ?>
                    <h3 style="font-size: 16px; margin-top: 20px;">Examples</h3>
                    <?php foreach ($sc['examples'] as $ex): ?>
                        <div class="example">
                            <strong><?php echo esc_html($ex['label']); ?>:</strong>
                            <?php echo esc_html($ex['code']); ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                
                <?php if (!empty($sc['tips'])): ?>
                    <div class="tips">
                        <strong>💡 Tips:</strong>
                        <ul>
                            <?php foreach ($sc['tips'] as $tip): ?>
                                <li><?php echo esc_html($tip); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        
        <?php endforeach; ?>
        
        <?php
        return ob_get_clean();
    }
}

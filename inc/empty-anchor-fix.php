<?php
/**
 * Empty Anchor Text Fixer
 *
 * Location: inc/empty-anchor-fix.php
 * @since 4.12.1
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function myls_eaf_is_enabled() {
    return get_option( 'myls_empty_anchor_fix_enabled', '0' ) === '1';
}

function myls_eaf_fix_html( $html ) {
    if ( empty( $html ) ) return $html;
    return preg_replace_callback(
        '/<a\b([^>]*)>(.*?)<\/a>/si',
        'myls_eaf_evaluate_link',
        $html
    );
}

function myls_eaf_evaluate_link( $match ) {
    $full_tag   = $match[0];
    $attributes = $match[1];
    $inner_html = $match[2];

    $visible = trim( wp_strip_all_tags( $inner_html ) );
    if ( $visible !== '' ) return $full_tag;

    if ( preg_match( '/alt=["\']([^"\']+)["\']/i', $inner_html, $am ) ) {
        if ( trim( $am[1] ) !== '' ) return $full_tag;
    }

    if ( preg_match( '/aria-label\s*=/i', $attributes ) ) return $full_tag;

    $label = myls_eaf_make_label( $attributes, $inner_html );
    $safe  = esc_attr( $label );

    $new_attrs = rtrim( $attributes ) . ' aria-label="' . $safe . '"';
    $new_inner = myls_eaf_patch_images( $inner_html, $safe );

    return '<a ' . $new_attrs . '>' . $new_inner . '</a>';
}

function myls_eaf_make_label( $attributes, $inner_html ) {
    if ( preg_match( '/title=["\']([^"\']+)["\']/i', $attributes, $m ) ) {
        $v = trim( $m[1] );
        if ( $v !== '' ) return $v;
    }

    if ( preg_match( '/href=["\']([^"\']+)["\']/i', $attributes, $m ) ) {
        $v = myls_eaf_href_to_label( $m[1] );
        if ( $v !== '' ) return $v;
    }

    if ( preg_match( '/data-label=["\']([^"\']+)["\']/i', $attributes, $m ) ) {
        $v = trim( $m[1] );
        if ( $v !== '' ) return $v;
    }

    return 'Link';
}

function myls_eaf_href_to_label( $href ) {
    $href = trim( $href );
    if ( $href === '' || $href === '#' ) return '';

    if ( stripos( $href, 'tel:' ) === 0 ) {
        $p = str_replace( 'tel:', '', $href );
        $p = preg_replace( '/[^\d\+\-\(\)\s]/', '', $p );
        return 'Call ' . trim( $p );
    }

    if ( stripos( $href, 'mailto:' ) === 0 ) {
        $e = strtok( str_replace( 'mailto:', '', $href ), '?' );
        return 'Email ' . trim( $e );
    }

    if ( preg_match( '#^https?://#i', $href ) ) {
        $parsed = wp_parse_url( $href );
        $path   = isset( $parsed['path'] ) ? $parsed['path'] : '/';
        if ( $path && $path !== '/' ) {
            return myls_eaf_slug_to_text( $path );
        }
        $host = isset( $parsed['host'] ) ? $parsed['host'] : '';
        $host = preg_replace( '/^www\./', '', $host );
        return $host ? ( 'Visit ' . $host ) : '';
    }

    return myls_eaf_slug_to_text( $href );
}

function myls_eaf_slug_to_text( $path ) {
    $path = trim( $path, '/' );
    $parts = explode( '/', $path );
    $slug  = end( $parts );
    if ( empty( $slug ) ) return '';

    $slug = strtok( $slug, '?' );
    $slug = strtok( $slug, '#' );

    $text = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

    $text = str_ireplace(
        array( ' Ac ', ' Hvac ', ' Faq ', ' Usa ' ),
        array( ' AC ', ' HVAC ', ' FAQ ', ' USA ' ),
        ' ' . $text . ' '
    );
    return trim( $text );
}

function myls_eaf_patch_images( $inner, $safe_label ) {
    $inner = preg_replace(
        '/(<img\b[^>]*)\balt=(["\'])\2([^>]*>)/i',
        '$1alt="' . $safe_label . '"$3',
        $inner
    );

    $inner = preg_replace_callback(
        '/<img\b(?![^>]*\balt\s*=)([^>]*)>/i',
        function( $m ) use ( $safe_label ) {
            return '<img alt="' . $safe_label . '"' . $m[1] . '>';
        },
        $inner
    );

    return $inner;
}

/* ─── LAYER 1: Output Buffer ─── */

function myls_eaf_start_buffer() {
    if ( is_admin() ) return;
    if ( ! myls_eaf_is_enabled() ) return;
    if ( wp_doing_ajax() ) return;
    if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return;
    if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;

    ob_start( 'myls_eaf_process_buffer' );
}

function myls_eaf_process_buffer( $html ) {
    if ( empty( $html ) ) return $html;
    if ( strpos( $html, '<html' ) === false && strpos( $html, '<!DOCTYPE' ) === false ) {
        return $html;
    }
    return myls_eaf_fix_html( $html );
}

add_action( 'template_redirect', 'myls_eaf_start_buffer', 0 );

/* ─── LAYER 2: the_content + widget filters ─── */

function myls_eaf_filter_content( $content ) {
    if ( is_admin() ) return $content;
    if ( ! myls_eaf_is_enabled() ) return $content;
    return myls_eaf_fix_html( $content );
}

add_filter( 'the_content', 'myls_eaf_filter_content', 9999 );
add_filter( 'widget_text', 'myls_eaf_filter_content', 9999 );

/* ─── LAYER 3: JavaScript fallback for cached pages ─── */

function myls_eaf_footer_js() {
    if ( is_admin() ) return;
    if ( ! myls_eaf_is_enabled() ) return;
    ?>
    <script id="myls-eaf-js">
    (function(){
    function h(r){
    if(!r||r==="#")return"";
    if(r.indexOf("tel:")===0)return"Call "+r.replace("tel:","").replace(/[^\d+\-() ]/g,"").trim();
    if(r.indexOf("mailto:")===0)return"Email "+r.replace("mailto:","").split("?")[0].trim();
    var p;try{p=new URL(r,location.origin).pathname}catch(e){p=r}
    var s=p.replace(/^\/|\/$/g,"").split("/"),g=s.pop()||"";
    if(!g)return"";g=g.split("?")[0].split("#")[0];
    var l=g.replace(/[-_]/g," ").replace(/\b\w/g,function(c){return c.toUpperCase()});
    return l.replace(/\bAc\b/g,"AC").replace(/\bHvac\b/g,"HVAC").replace(/\bFaq\b/g,"FAQ");}
    function fix(){
    var a=document.querySelectorAll("a"),n=0;
    for(var i=0;i<a.length;i++){var e=a[i];
    if(e.getAttribute("aria-label"))continue;
    if((e.textContent||"").replace(/\s+/g," ").trim())continue;
    var imgs=e.querySelectorAll("img[alt]"),ok=false;
    for(var j=0;j<imgs.length;j++){if((imgs[j].getAttribute("alt")||"").trim()){ok=true;break;}}
    if(ok)continue;
    var lb="",ti=e.getAttribute("title");
    if(ti&&ti.trim())lb=ti.trim();
    if(!lb)lb=h(e.getAttribute("href")||"");
    if(!lb)lb="Link";
    e.setAttribute("aria-label",lb);n++;
    var ai=e.querySelectorAll("img");
    for(var k=0;k<ai.length;k++){if(!(ai[k].getAttribute("alt")||"").trim())ai[k].setAttribute("alt",lb);}}
    if(n)console.log("[MYLS] EAF patched "+n+" link(s)");}
    if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",fix);else fix();
    setTimeout(fix,2000);
    })();
    </script>
    <?php
}

add_action( 'wp_footer', 'myls_eaf_footer_js', 9999 );

/* ─── DEBUG: HTML comment in head ─── */

function myls_eaf_debug_comment() {
    if ( is_admin() ) return;
    if ( ! myls_eaf_is_enabled() ) return;
    echo '<!-- MYLS-EAF-ACTIVE -->' . "\n";
}

add_action( 'wp_head', 'myls_eaf_debug_comment', 1 );

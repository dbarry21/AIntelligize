<?php
/**
 * Person Schema Provider — JSON-LD output
 *
 * Reads myls_person_profiles and emits Person schema on assigned pages.
 * Links to Organization via @id for proper entity graph.
 *
 * @since 4.12.0
 */

if ( ! defined('ABSPATH') ) exit;

add_action( 'wp_head', 'myls_person_schema_output', 5 );

function myls_person_schema_output() {
    if ( is_admin() || ! is_singular() ) return;

    $profiles = get_option( 'myls_person_profiles', [] );
    if ( ! is_array($profiles) || empty($profiles) ) return;

    $post_id = get_queried_object_id();
    if ( ! $post_id ) return;

    // Org info for worksFor
    $org_name = trim( (string) get_option('myls_org_name', get_bloginfo('name')) );
    $org_url  = trim( (string) get_option('myls_org_url', home_url('/')) );

    foreach ( $profiles as $p ) {
        if ( empty($p['name']) ) continue;
        if ( ($p['enabled'] ?? '1') !== '1' ) continue;

        // Check page assignment
        $pages = array_map( 'absint', (array) ($p['pages'] ?? []) );
        if ( empty($pages) || ! in_array($post_id, $pages, true) ) continue;

        $schema = myls_person_build_jsonld( $p, $org_name, $org_url );
        if ( ! empty($schema) ) {
            echo "\n<!-- AIntelligize: Person Schema -->\n";
            echo '<script type="application/ld+json">' . "\n";
            echo wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
            echo "\n</script>\n";
        }
    }
}

/**
 * Build the Person JSON-LD array.
 */
function myls_person_build_jsonld( array $p, string $org_name, string $org_url ) : array {
    $name = trim( $p['name'] ?? '' );
    if ( ! $name ) return [];

    // Stable @id
    $person_slug = sanitize_title( $name );
    $person_id   = home_url( '/#person-' . $person_slug );

    $schema = [
        '@context' => 'https://schema.org',
        '@type'    => 'Person',
        '@id'      => $person_id,
        'name'     => $name,
    ];

    // URL
    $url = trim( $p['url'] ?? '' );
    if ( $url ) $schema['url'] = $url;

    // Image
    $image = '';
    if ( ! empty($p['image_id']) ) {
        $image = wp_get_attachment_image_url( (int) $p['image_id'], 'full' );
    }
    if ( ! $image && ! empty($p['image_url']) ) {
        $image = $p['image_url'];
    }
    if ( $image ) $schema['image'] = $image;

    // Job title
    $job = trim( $p['job_title'] ?? '' );
    if ( $job ) $schema['jobTitle'] = $job;

    // Honorific prefix
    $prefix = trim( $p['honorific_prefix'] ?? '' );
    if ( $prefix ) $schema['honorificPrefix'] = $prefix;

    // Description
    $desc = trim( $p['description'] ?? '' );
    if ( $desc ) $schema['description'] = $desc;

    // Email
    $email = trim( $p['email'] ?? '' );
    if ( $email ) $schema['email'] = $email;

    // Phone
    $phone = trim( $p['phone'] ?? '' );
    if ( $phone ) $schema['telephone'] = $phone;

    // sameAs
    $same_as = array_values( array_filter( array_map('trim', (array) ($p['same_as'] ?? []) ) ) );
    if ( ! empty($same_as) ) {
        $schema['sameAs'] = count($same_as) === 1 ? $same_as[0] : $same_as;
    }

    // worksFor — link to Organization schema
    if ( $org_name ) {
        $works_for = [
            '@type' => 'Organization',
            'name'  => $org_name,
        ];
        if ( $org_url ) $works_for['url'] = $org_url;
        // Use @id to link to existing org schema
        $works_for['@id'] = home_url( '/#organization' );
        $schema['worksFor'] = $works_for;
    }

    // knowsAbout — use Thing with Wikidata/Wikipedia for max KG impact
    $knows = (array) ($p['knows_about'] ?? []);
    $knows_output = [];
    foreach ( $knows as $k ) {
        $kname = trim( $k['name'] ?? '' );
        if ( ! $kname ) continue;

        $thing = [
            '@type' => 'Thing',
            'name'  => $kname,
        ];

        $wikidata  = trim( $k['wikidata'] ?? '' );
        $wikipedia = trim( $k['wikipedia'] ?? '' );

        if ( $wikidata ) {
            $thing['@id'] = $wikidata;
        }
        if ( $wikipedia ) {
            $thing['sameAs'] = $wikipedia;
        }

        $knows_output[] = $thing;
    }
    if ( ! empty($knows_output) ) {
        $schema['knowsAbout'] = count($knows_output) === 1 ? $knows_output[0] : $knows_output;
    }

    // hasCredential
    $creds = (array) ($p['credentials'] ?? []);
    $creds_output = [];
    foreach ( $creds as $c ) {
        $cname = trim( $c['name'] ?? '' );
        if ( ! $cname ) continue;

        $cred = [
            '@type'              => 'EducationalOccupationalCredential',
            'credentialCategory' => $cname,
        ];

        $abbr = trim( $c['abbr'] ?? '' );
        if ( $abbr ) {
            $cred['credentialCategory'] = [
                '@type'    => 'DefinedTerm',
                'name'     => $cname,
                'termCode' => $abbr,
            ];
        }

        $issuer_name = trim( $c['issuer'] ?? '' );
        $issuer_url  = trim( $c['issuer_url'] ?? '' );
        if ( $issuer_name ) {
            $recog = [
                '@type' => 'Organization',
                'name'  => $issuer_name,
            ];
            if ( $issuer_url ) $recog['url'] = $issuer_url;
            $cred['recognizedBy'] = $recog;
        }

        $creds_output[] = $cred;
    }
    if ( ! empty($creds_output) ) {
        $schema['hasCredential'] = count($creds_output) === 1 ? $creds_output[0] : $creds_output;
    }

    // alumniOf
    $alumni = (array) ($p['alumni'] ?? []);
    $alumni_output = [];
    foreach ( $alumni as $a ) {
        $aname = trim( $a['name'] ?? '' );
        if ( ! $aname ) continue;

        $org = [
            '@type' => 'EducationalOrganization',
            'name'  => $aname,
        ];
        $aurl = trim( $a['url'] ?? '' );
        if ( $aurl ) $org['url'] = $aurl;

        $alumni_output[] = $org;
    }
    if ( ! empty($alumni_output) ) {
        $schema['alumniOf'] = count($alumni_output) === 1 ? $alumni_output[0] : $alumni_output;
    }

    // memberOf
    $members = (array) ($p['member_of'] ?? []);
    $member_output = [];
    foreach ( $members as $m ) {
        $mname = trim( $m['name'] ?? '' );
        if ( ! $mname ) continue;

        $org = [
            '@type' => 'Organization',
            'name'  => $mname,
        ];
        $murl = trim( $m['url'] ?? '' );
        if ( $murl ) $org['url'] = $murl;

        $member_output[] = $org;
    }
    if ( ! empty($member_output) ) {
        $schema['memberOf'] = count($member_output) === 1 ? $member_output[0] : $member_output;
    }

    // Awards
    $awards = array_values( array_filter( array_map('trim', (array) ($p['awards'] ?? []) ) ) );
    if ( ! empty($awards) ) {
        $schema['award'] = count($awards) === 1 ? $awards[0] : $awards;
    }

    // Languages
    $langs = array_values( array_filter( array_map('trim', (array) ($p['languages'] ?? []) ) ) );
    if ( ! empty($langs) ) {
        $lang_output = [];
        foreach ( $langs as $l ) {
            $lang_output[] = [
                '@type' => 'Language',
                'name'  => $l,
            ];
        }
        $schema['knowsLanguage'] = count($lang_output) === 1 ? $lang_output[0] : $lang_output;
    }

    return $schema;
}

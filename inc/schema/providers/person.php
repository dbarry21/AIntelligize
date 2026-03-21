<?php
/**
 * Person Schema Provider — unified @graph
 *
 * Reads myls_person_profiles and pushes Person nodes into the schema graph
 * on assigned pages. Links to Organization via @id reference.
 *
 * @since 4.12.0
 * @since 7.8.92 Moved from standalone wp_head emitter to myls_schema_graph filter.
 */

if ( ! defined('ABSPATH') ) exit;

/**
 * Build the Person JSON-LD array (graph node, no @context).
 */
function myls_person_build_jsonld( array $p ) : array {
    $name = trim( $p['name'] ?? '' );
    if ( ! $name ) return [];

    // Stable @id
    $person_slug = sanitize_title( $name );
    $person_id   = home_url( '/#person-' . $person_slug );

    $schema = [
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

    // worksFor — @id reference to Organization (no inline duplicate)
    $schema['worksFor'] = [ '@id' => home_url( '/#organization' ) ];

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

    // Gender
    $gender = trim( $p['gender'] ?? '' );
    if ( $gender ) $schema['gender'] = $gender;

    // Nationality — typed as Country for KG disambiguation
    $nationality = trim( $p['nationality'] ?? '' );
    if ( $nationality ) {
        $schema['nationality'] = [ '@type' => 'Country', 'name' => $nationality ];
    }

    // Identifier — machine-readable license/contractor numbers as PropertyValue
    $identifiers = (array) ($p['identifiers'] ?? []);
    $id_out = [];
    foreach ( $identifiers as $id ) {
        $id_name  = trim( $id['name']  ?? '' );
        $id_value = trim( $id['value'] ?? '' );
        if ( ! $id_name || ! $id_value ) continue;
        $id_out[] = [ '@type' => 'PropertyValue', 'name' => $id_name, 'value' => $id_value ];
    }
    if ( ! empty($id_out) ) {
        $schema['identifier'] = count($id_out) === 1 ? $id_out[0] : $id_out;
    }

    // hasOccupation — Occupation type with structured skills
    $occ_name   = trim( $p['occupation_name'] ?? '' );
    $occ_skills = array_values( array_filter( array_map( 'trim', (array)($p['occupation_skills'] ?? []) ) ) );
    if ( $occ_name ) {
        $occ = [ '@type' => 'Occupation', 'name' => $occ_name ];
        if ( ! empty($occ_skills) ) {
            $occ['skills'] = implode( ', ', $occ_skills );
        }
        $schema['hasOccupation'] = $occ;
    }

    // interactionStatistic — verifiable social proof counters
    $interactions = (array) ($p['interaction_stats'] ?? []);
    $int_out = [];
    foreach ( $interactions as $int ) {
        $int_type  = trim( $int['type']  ?? '' );
        $int_count = (int)  ($int['count'] ?? 0);
        if ( ! $int_type || $int_count <= 0 ) continue;
        $int_out[] = [
            '@type'                => 'InteractionCounter',
            'interactionType'      => 'https://schema.org/' . $int_type,
            'userInteractionCount' => $int_count,
        ];
    }
    if ( ! empty($int_out) ) {
        $schema['interactionStatistic'] = count($int_out) === 1 ? $int_out[0] : $int_out;
    }

    return $schema;
}

/**
 * Person → unified @graph
 *
 * Pushes Person node(s) into myls_schema_graph for assigned pages.
 * Replaces the old standalone wp_head emitter.
 */
add_filter( 'myls_schema_graph', function ( array $graph ) {

	if ( is_admin() || wp_doing_ajax() || ! is_singular() ) return $graph;
	if ( is_feed() || is_preview() ) return $graph;
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) return $graph;

	$profiles = get_option( 'myls_person_profiles', [] );
	if ( ! is_array( $profiles ) || empty( $profiles ) ) return $graph;

	$post_id = (int) get_queried_object_id();
	if ( $post_id <= 0 ) return $graph;

	foreach ( $profiles as $p ) {
		if ( empty( $p['name'] ) ) continue;
		if ( ( $p['enabled'] ?? '1' ) !== '1' ) continue;

		// Check page assignment
		$pages = array_map( 'absint', (array) ( $p['pages'] ?? [] ) );
		if ( empty( $pages ) || ! in_array( $post_id, $pages, true ) ) continue;

		$node = myls_person_build_jsonld( $p );
		if ( ! empty( $node ) ) {
			$graph[] = apply_filters( 'myls_person_schema_node', $node, $post_id );
		}
	}

	return $graph;
}, 6 ); // Priority 6: after WebSite (4), before LB (8) and Org (10)

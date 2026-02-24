<?php
// File: inc/schema/render.php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AIntelligize – Schema Renderer
 *
 * - Provides helper flags so modules can decide when to output schema.
 * - Renders JSON-LD for: FAQPage, Organization, LocalBusiness, Service
 * - Blog / Collection / Video: flags only (modules will render later)
 */

/** Check if a schema toggle is enabled (saved by the Schema admin tab). */
function myls_schema_flag_enabled( string $key ): bool {
	$opt = get_option( "myls_schema_{$key}", ['enabled' => '0'] );
	return isset($opt['enabled']) && (string) $opt['enabled'] === '1';
}

/** Quick utility: are any schema toggles turned on? */
function myls_schema_any_enabled(): bool {
	foreach ( ['faq','localbusiness','organization','service','blog','collection','video'] as $k ) {
		if ( myls_schema_flag_enabled( $k ) ) return true;
	}
	return false;
}

/**
 * Emit JSON-LD in <head> for the types we manage directly (FAQ/Org/LB/Service).
 * Your modules can also hook into wp_head and check the same flags to output
 * Blog / Collection / Video (or further specialized schema) as needed.
 */
add_action( 'wp_head', function () {

	// ---------- FAQPage ----------
	$faq = get_option( 'myls_schema_faq', ['enabled' => '0', 'items' => []] );
	if ( ($faq['enabled'] ?? '0') === '1' && ! empty( $faq['items'] ) ) {
		$main = [
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => array_values( array_map( function( $row ) {
				$q = wp_strip_all_tags( $row['q'] ?? '' );
				$a = wp_kses_post( $row['a'] ?? '' );
				if ( $q === '' || $a === '' ) return null;
				return [
					'@type'          => 'Question',
					'name'           => $q,
					'acceptedAnswer' => [
						'@type' => 'Answer',
						'text'  => $a,
					],
				];
			}, $faq['items'] ?? [] ) )
		];
		$main['mainEntity'] = array_filter( $main['mainEntity'] );
		if ( ! empty( $main['mainEntity'] ) ) {
			echo "\n<script type=\"application/ld+json\">", wp_json_encode( $main, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "</script>\n";
		}
	}

	// ---------- Organization ----------
	$org = get_option( 'myls_schema_organization', ['enabled' => '0'] );
	if ( ($org['enabled'] ?? '0') === '1' && ! empty( $org['name'] ?? '' ) ) {
		$sameAs = array_filter( array_map( 'trim', explode( "\n", (string) ( $org['sameas'] ?? '' ) ) ) );
		$data = [
			'@context' => 'https://schema.org',
			'@type'    => 'Organization',
			'name'     => $org['name'] ?? '',
		];
		if ( ! empty( $org['url'] ) )   $data['url']   = esc_url( $org['url'] );
		if ( ! empty( $org['logo'] ) )  $data['logo']  = esc_url( $org['logo'] );
		if ( ! empty( $org['image'] ) ) $data['image'] = esc_url( $org['image'] );
		if ( $sameAs ) $data['sameAs']  = array_map( 'esc_url_raw', $sameAs );

		echo "\n<script type=\"application/ld+json\">", wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "</script>\n";
	}

	// ---------- LocalBusiness ----------
	$lb = get_option( 'myls_schema_localbusiness', ['enabled' => '0'] );
	if ( ($lb['enabled'] ?? '0') === '1' && ! empty( $lb['name'] ?? '' ) ) {
		$sameAs = array_filter( array_map( 'trim', explode( "\n", (string) ( $lb['sameas'] ?? '' ) ) ) );
		$hours  = array_filter( array_map( 'trim', explode( "\n", (string) ( $lb['hours'] ?? '' ) ) ) );

		$addr = array_filter( [
			'@type'           => 'PostalAddress',
			'streetAddress'   => $lb['street']  ?? '',
			'addressLocality' => $lb['city']    ?? '',
			'addressRegion'   => $lb['region']  ?? '',
			'postalCode'      => $lb['postal']  ?? '',
			'addressCountry'  => $lb['country'] ?? '',
		] );

		$data = [
			'@context' => 'https://schema.org',
			'@type'    => 'LocalBusiness',
			'name'     => $lb['name'] ?? '',
		];
		if ( ! empty( $lb['url'] ) )       $data['url']       = esc_url( $lb['url'] );
		if ( ! empty( $lb['telephone'] ) ) $data['telephone'] = $lb['telephone'];
		if ( ! empty( $lb['logo'] ) )      $data['logo']      = esc_url( $lb['logo'] );
		if ( ! empty( $lb['image'] ) )     $data['image']     = esc_url( $lb['image'] );
		if ( $addr && count( $addr ) > 1 ) $data['address']   = $addr;

		if ( is_numeric( $lb['lat'] ?? null ) && is_numeric( $lb['lng'] ?? null ) ) {
			$data['geo'] = [
				'@type'     => 'GeoCoordinates',
				'latitude'  => (float) $lb['lat'],
				'longitude' => (float) $lb['lng'],
			];
		}
		if ( $hours )   $data['openingHours'] = $hours;
		if ( $sameAs )  $data['sameAs']       = array_map( 'esc_url_raw', $sameAs );

		echo "\n<script type=\"application/ld+json\">", wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "</script>\n";
	}

	// ---------- Service ----------
	$svc = get_option( 'myls_schema_service', ['enabled' => '0'] );
	if ( ($svc['enabled'] ?? '0') === '1' && ! empty( $svc['name'] ?? '' ) ) {
		$data = [
			'@context' => 'https://schema.org',
			'@type'    => 'Service',
			'name'     => $svc['name'] ?? '',
		];
		if ( ! empty( $svc['description'] ) ) $data['description'] = wp_kses_post( $svc['description'] );
		if ( ! empty( $svc['serviceType'] ) ) $data['serviceType'] = $svc['serviceType'];
		if ( ! empty( $svc['areaServed'] ) )  $data['areaServed']  = $svc['areaServed'];

		// Offer (optional)
		if ( ! empty( $svc['price'] ) ) {
			$data['offers'] = [
				'@type'         => 'Offer',
				'price'         => $svc['price'],
				'priceCurrency' => ! empty( $svc['currency'] ) ? strtoupper( $svc['currency'] ) : 'USD',
				'availability'  => 'https://schema.org/InStock',
			];
		}

		// Provider (optional) — light link to Org/LB data if those toggles are on
		$providerType = in_array( ($svc['provider'] ?? 'LocalBusiness'), ['LocalBusiness','Organization'], true ) ? $svc['provider'] : 'LocalBusiness';
		$org = get_option( 'myls_schema_organization', ['enabled' => '0'] );
		$lb  = get_option( 'myls_schema_localbusiness', ['enabled' => '0'] );
		$prov = null;

		if ( $providerType === 'LocalBusiness' && ($lb['enabled'] ?? '0') === '1' && ! empty( $lb['name'] ) ) {
			$prov = ['@type' => 'LocalBusiness', 'name' => $lb['name']];
			if ( ! empty( $lb['url'] ) )  $prov['url']  = esc_url( $lb['url'] );
			if ( ! empty( $lb['logo'] ) ) $prov['logo'] = esc_url( $lb['logo'] );
		} elseif ( $providerType === 'Organization' && ($org['enabled'] ?? '0') === '1' && ! empty( $org['name'] ) ) {
			$prov = ['@type' => 'Organization', 'name' => $org['name']];
			if ( ! empty( $org['url'] ) )  $prov['url']  = esc_url( $org['url'] );
			if ( ! empty( $org['logo'] ) ) $prov['logo'] = esc_url( $org['logo'] );
		}
		if ( $prov ) $data['provider'] = $prov;

		echo "\n<script type=\"application/ld+json\">", wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "</script>\n";
	}

	// Blog / Collection / Video: no direct output here.
	// Your modules can read the flags via myls_schema_flag_enabled('blog'|'collection'|'video')
	// and emit JSON-LD based on post type/content.
}, 5); // early-ish so themes/plugins can still filter later if needed

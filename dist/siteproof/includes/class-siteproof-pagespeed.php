<?php
/**
 * Google PageSpeed Insights (mobile strategy): lab data (Lighthouse) and CrUX field data.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * PageSpeed Insights client.
 */
class Siteproof_PageSpeed {

	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Target URL for the measurement: with a live URL set, its host with the same path.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function target( $url ) {
		$live = trim( (string) Siteproof_Settings::get()['live_url'] );
		if ( '' === $live ) {
			return $url;
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		return untrailingslashit( $live ) . ( '' === $path ? '/' : $path ) . ( $query ? '?' . $query : '' );
	}

	/**
	 * Is the URL publicly reachable (not a local host)?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function public_url( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host || 'localhost' === $host || preg_match( '/\.(test|local|localhost|lan|internal|invalid|example)$/', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		return true;
	}

	/**
	 * Measure one page.
	 *
	 * @param string $url URL (bereits Ziel-URL).
	 * @param string $key API key.
	 * @return array
	 */
	public static function run( $url, $key ) {
		$args = array(
			'url'      => $url,
			'strategy' => 'mobile',
			'category' => 'performance',
			'locale'   => 'de',
		);
		if ( '' !== $key ) {
			$args['key'] = $key;
		}
		$res = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $args ), self::ENDPOINT ), array( 'timeout' => 90 ) );
		if ( is_wp_error( $res ) ) {
			return array(
				'url'   => $url,
				'error' => $res->get_error_message(),
			);
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) || empty( $body['lighthouseResult'] ) ) {
			// Never echo the API key in error messages.
			$msg = is_array( $body ) ? (string) ( $body['error']['message'] ?? 'HTTP ' . $code ) : 'HTTP ' . $code;
			return array(
				'url'   => $url,
				'error' => str_replace( $key, '***', $msg ),
			);
		}
		return self::parse( $body, $url );
	}

	/**
	 * Parse the response.
	 *
	 * @param array  $body Response.
	 * @param string $url  URL.
	 * @return array
	 */
	public static function parse( array $body, $url ) {
		$lh     = $body['lighthouseResult'];
		$a      = $lh['audits'] ?? array();
		$num    = fn( $id ) => isset( $a[ $id ]['numericValue'] ) ? (float) $a[ $id ]['numericValue'] : null;
		$bytes  = array(
			'total'      => 0,
			'document'   => 0,
			'script'     => 0,
			'stylesheet' => 0,
			'font'       => 0,
			'image'      => 0,
			'media'      => 0,
			'other'      => 0,
			'thirdParty' => 0,
		);
		$requests = 0;
		foreach ( $a['resource-summary']['details']['items'] ?? array() as $item ) {
			$type = $item['resourceType'] ?? '';
			$size = (int) ( $item['transferSize'] ?? 0 );
			if ( 'total' === $type ) {
				$bytes['total'] = $size;
				$requests       = (int) ( $item['requestCount'] ?? 0 );
			} elseif ( 'third-party' === $type ) {
				$bytes['thirdParty'] = $size;
			} elseif ( isset( $bytes[ $type ] ) ) {
				$bytes[ $type ] = $size;
			}
		}
		if ( ! $bytes['total'] ) {
			$bytes['total'] = (int) $num( 'total-byte-weight' );
		}
		$metrics = $a['metrics']['details']['items'][0] ?? array();
		$field   = null;
		$le      = $body['loadingExperience'] ?? array();
		if ( ! empty( $le['metrics'] ) && empty( $le['origin_fallback'] ) ) {
			$m     = $le['metrics'];
			$field = array(
				'lcp'      => $m['LARGEST_CONTENTFUL_PAINT_MS']['percentile'] ?? null,
				'inp'      => $m['INTERACTION_TO_NEXT_PAINT']['percentile'] ?? null,
				'cls'      => isset( $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] ) ? $m['CUMULATIVE_LAYOUT_SHIFT_SCORE']['percentile'] / 100 : null,
				'fcp'      => $m['FIRST_CONTENTFUL_PAINT_MS']['percentile'] ?? null,
				'ttfb'     => $m['EXPERIMENTAL_TIME_TO_FIRST_BYTE']['percentile'] ?? null,
				'category' => $le['overall_category'] ?? null,
			);
		}
		return array(
			'source'   => 'psi',
			'url'      => $url,
			'score'    => isset( $lh['categories']['performance']['score'] ) ? (int) round( $lh['categories']['performance']['score'] * 100 ) : null,
			'fcp'      => $num( 'first-contentful-paint' ),
			'lcp'      => $num( 'largest-contentful-paint' ),
			'cls'      => $num( 'cumulative-layout-shift' ),
			'tbt'      => $num( 'total-blocking-time' ),
			'si'       => $num( 'speed-index' ),
			'load'     => isset( $metrics['observedLoad'] ) ? (float) $metrics['observedLoad'] : null,
			'ttfb'     => $num( 'server-response-time' ),
			'bytes'    => $bytes,
			'requests' => $requests,
			'field'    => $field,
		);
	}
}

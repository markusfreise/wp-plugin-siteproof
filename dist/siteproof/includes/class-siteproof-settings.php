<?php
/**
 * Settings: AI provider, models, prices, scope, word list, thresholds, live URL.
 * API keys are stored encrypted in a separate option (not autoloaded);
 * constants in wp-config.php take precedence.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read and write settings.
 */
class Siteproof_Settings {

	const OPTION = 'siteproof_settings';
	const KEYS   = 'siteproof_keys';

	/** Constants in wp-config.php (take precedence over stored keys). */
	const CONSTANTS = array(
		'anthropic'  => 'SITEPROOF_ANTHROPIC_KEY',
		'openai'     => 'SITEPROOF_OPENAI_KEY',
		'openrouter' => 'SITEPROOF_OPENROUTER_KEY',
		'pagespeed'  => 'SITEPROOF_PAGESPEED_KEY',
	);

	/**
	 * AI providers with display names.
	 *
	 * @return string[]
	 */
	public static function providers() {
		$list = array(
			'anthropic'  => __( 'Anthropic (Claude)', 'siteproof' ),
			'openai'     => __( 'OpenAI', 'siteproof' ),
			'openrouter' => __( 'OpenRouter', 'siteproof' ),
		);
		if ( Siteproof_LLM_WordPress::available() ) {
			$list['wordpress'] = __( 'WordPress AI (site connections)', 'siteproof' );
		}
		return $list;
	}

	/**
	 * Thresholds (defaults from the rating table). key => [label, default, unit].
	 *
	 * @return array
	 */
	public static function threshold_fields() {
		return array(
			'lcp_green'    => array( __( 'LCP green up to', 'siteproof' ), 2500, 'ms' ),
			'lcp_yellow'   => array( __( 'LCP yellow up to', 'siteproof' ), 4000, 'ms' ),
			'lcp_crit'     => array( __( 'LCP very critical from', 'siteproof' ), 8000, 'ms' ),
			'total_green'  => array( __( 'Page weight green up to', 'siteproof' ), 1.5, 'MB' ),
			'total_yellow' => array( __( 'Page weight yellow up to', 'siteproof' ), 3, 'MB' ),
			'total_crit'   => array( __( 'Page weight very critical from', 'siteproof' ), 6, 'MB' ),
			'media_crit'   => array( __( 'All images very critical from', 'siteproof' ), 8, 'MB' ),
			'img_yellow'   => array( __( 'Image yellow from', 'siteproof' ), 200, 'KB' ),
			'img_red'      => array( __( 'Image red from', 'siteproof' ), 500, 'KB' ),
			'img_crit'     => array( __( 'Image very critical from', 'siteproof' ), 1024, 'KB' ),
			'ratio_yellow' => array( __( 'Image width yellow from (x needed)', 'siteproof' ), 1.5, '×' ),
			'ratio_red'    => array( __( 'Image width red from (x needed)', 'siteproof' ), 3, '×' ),
			'kbmp_yellow'  => array( __( 'Compression yellow from', 'siteproof' ), 150, 'KB/MP' ),
			'kbmp_red'     => array( __( 'Compression red from', 'siteproof' ), 300, 'KB/MP' ),
			'title_min'    => array( __( 'Meta title at least', 'siteproof' ), 25, __( 'characters', 'siteproof' ) ),
			'title_max'    => array( __( 'Meta title at most', 'siteproof' ), 60, __( 'characters', 'siteproof' ) ),
			'desc_min'     => array( __( 'Meta description at least', 'siteproof' ), 70, __( 'characters', 'siteproof' ) ),
			'desc_max'     => array( __( 'Meta description at most', 'siteproof' ), 160, __( 'characters', 'siteproof' ) ),
			'global_share' => array( __( 'Rule counts as site-wide from', 'siteproof' ), 80, __( '% of pages', 'siteproof' ) ),
		);
	}

	/**
	 * Default values.
	 *
	 * @return array
	 */
	public static function defaults() {
		$th = array();
		foreach ( self::threshold_fields() as $k => $f ) {
			$th[ $k ] = $f[1];
		}
		return array(
			'provider'   => 'anthropic',
			'fallback'   => '',
			'models'     => array(
				'anthropic'  => '',
				'openai'     => '',
				'openrouter' => '',
			),
			'prices'     => '',
			'types'      => array( 'page' ),
			'excluded_urls' => '',
			'wordlist'   => '',
			'live_url'   => '',
			'thresholds' => $th,
		);
	}

	/**
	 * All settings, merged with defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved           = get_option( self::OPTION, array() );
		$d               = self::defaults();
		$s               = array_merge( $d, is_array( $saved ) ? $saved : array() );
		$s['models']     = array_merge( $d['models'], (array) $s['models'] );
		$s['thresholds'] = array_merge( $d['thresholds'], (array) $s['thresholds'] );
		return $s;
	}

	/**
	 * Save settings.
	 *
	 * @param array $s Settings.
	 */
	public static function save( array $s ) {
		update_option( self::OPTION, $s, false );
	}

	/**
	 * API key (constant before stored value).
	 *
	 * @param string $name anthropic|openai|openrouter|pagespeed.
	 * @return string
	 */
	public static function key( $name ) {
		if ( self::key_from_constant( $name ) ) {
			return (string) constant( self::CONSTANTS[ $name ] );
		}
		$keys = get_option( self::KEYS, array() );
		return empty( $keys[ $name ] ) ? '' : Siteproof_Crypto::decrypt( $keys[ $name ] );
	}

	/**
	 * Is the key defined in wp-config.php?
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function key_from_constant( $name ) {
		$const = self::CONSTANTS[ $name ] ?? '';
		return $const && defined( $const ) && constant( $const );
	}

	/**
	 * Store a key ('' deletes it).
	 *
	 * @param string $name  Name.
	 * @param string $value Plain text.
	 */
	public static function set_key( $name, $value ) {
		$keys = get_option( self::KEYS, array() );
		$keys = is_array( $keys ) ? $keys : array();
		if ( '' === $value ) {
			unset( $keys[ $name ] );
		} else {
			$keys[ $name ] = Siteproof_Crypto::encrypt( $value );
		}
		update_option( self::KEYS, $keys, false );
	}

	/**
	 * Masked display of a key.
	 *
	 * @param string $name Name.
	 * @return string
	 */
	public static function masked( $name ) {
		$key = self::key( $name );
		if ( '' === $key ) {
			return '';
		}
		return substr( $key, 0, 4 ) . str_repeat( '•', 8 ) . substr( $key, -4 );
	}

	/**
	 * Prices per model from the text setting: "model | input | output" (USD per million tokens).
	 *
	 * @return array model => [in, out]
	 */
	public static function prices() {
		$out = array();
		foreach ( preg_split( '/\R/', (string) self::get()['prices'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) >= 3 && '' !== $parts[0] && '#' !== $parts[0][0] ) {
				$out[ $parts[0] ] = array( (float) str_replace( ',', '.', $parts[1] ), (float) str_replace( ',', '.', $parts[2] ) );
			}
		}
		return $out;
	}

	/**
	 * Add a price for a model if none is stored yet (OpenRouter reports prices).
	 *
	 * @param string $model Model.
	 * @param float  $in    USD per million input tokens.
	 * @param float  $out   USD per million output tokens.
	 */
	public static function add_price_if_missing( $model, $in, $out ) {
		if ( isset( self::prices()[ $model ] ) ) {
			return;
		}
		$fmt         = fn( $v ) => rtrim( rtrim( number_format( (float) $v, 4, '.', '' ), '0' ), '.' );
		$s           = self::get();
		$s['prices'] = trim( $s['prices'] . "\n" . sprintf( '%s | %s | %s', $model, $fmt( $in ), $fmt( $out ) ) );
		self::save( $s );
	}

	/**
	 * Configured AI provider as object.
	 *
	 * @param string $provider anthropic|openai|openrouter.
	 * @param string $model    Optional model (default: from settings).
	 * @return Siteproof_LLM|null
	 */
	public static function llm( $provider, $model = '' ) {
		if ( 'wordpress' === $provider ) {
			return Siteproof_LLM_WordPress::available() ? new Siteproof_LLM_WordPress( '' ) : null;
		}
		$key = self::key( $provider );
		if ( '' === $key ) {
			return null;
		}
		$model = '' !== $model ? $model : ( self::get()['models'][ $provider ] ?? '' );
		switch ( $provider ) {
			case 'anthropic':
				return new Siteproof_LLM_Anthropic( $key, $model );
			case 'openai':
				return new Siteproof_LLM_OpenAI( $key, $model );
			case 'openrouter':
				return new Siteproof_LLM_OpenRouter( $key, $model );
		}
		return null;
	}

	/**
	 * Is a URL excluded? One pattern per line, * as wildcard, matched against URL and path.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function excluded( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( preg_split( '/\R/', (string) self::get()['excluded_urls'] ) as $pattern ) {
			$pattern = trim( $pattern );
			if ( '' === $pattern || '#' === $pattern[0] ) {
				continue;
			}
			if ( fnmatch( $pattern, $url ) || fnmatch( $pattern, $path ) || fnmatch( rtrim( $pattern, '/' ) . '/', $path ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Word list (names and terms that are never corrected).
	 *
	 * @return string[]
	 */
	public static function wordlist() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) self::get()['wordlist'] ) ), fn( $w ) => '' !== $w && '#' !== $w[0] ) );
	}
}

<?php
/**
 * Link check: every URL once, in parallel batches, without following redirects.
 *
 * Status: ok (2xx) | redirect (301/308) | temp-redirect (302/303/307) | broken (4xx/5xx/network, soft 404)
 *         | blocked (429, social networks block bots) | anchor-missing | skipped (mailto/tel)
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;

/**
 * Link checker.
 */
class Siteproof_Links {

	const UA        = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36';
	const BOT_HOSTS = '/(^|\.)(linkedin|instagram|facebook|fb|twitter|x|tiktok|xing|threads)\.(com|net|de)$/i';
	const BATCH     = 16;

	/** @var string Host der Website */
	private $home_host;
	/** @var array path => anchor IDs of known pages */
	private $ids_by_path;
	/** @var array path => HTML (anchors of other pages) */
	private $html_cache = array();

	/**
	 * Constructor.
	 *
	 * @param array $ids_by_path path => IDs.
	 */
	public function __construct( array $ids_by_path ) {
		$this->home_host   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$this->ids_by_path = $ids_by_path;
	}

	/**
	 * All link URLs of a run (unique).
	 *
	 * @param array $pages Pages with server data.
	 * @return string[]
	 */
	public static function unique_urls( array $pages ) {
		$urls = array();
		foreach ( $pages as $p ) {
			foreach ( $p['server']['links'] ?? array() as $l ) {
				$urls[ $l['href'] ] = true;
			}
		}
		return array_keys( $urls );
	}

	/**
	 * Anchor IDs per path.
	 *
	 * @param array $pages Pages.
	 * @return array
	 */
	public static function ids_by_path( array $pages ) {
		$out = array();
		foreach ( $pages as $p ) {
			if ( ! empty( $p['server']['ids'] ) ) {
				$out[ (string) wp_parse_url( $p['server']['finalUrl'] ?? $p['url'], PHP_URL_PATH ) ] = array_flip( $p['server']['ids'] );
			}
		}
		return $out;
	}

	/**
	 * Internal?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function internal( $url ) {
		return wp_parse_url( $url, PHP_URL_HOST ) === $this->home_host;
	}

	/**
	 * Request options.
	 *
	 * @param string $url      URL.
	 * @param bool   $insecure Skip certificate verification.
	 * @return array
	 */
	private function options( $url, $insecure = false ) {
		return array(
			'timeout'          => 10,
			'connect_timeout'  => 10,
			'follow_redirects' => false,
			'useragent'        => self::UA,
			// Local (.test) certificates are not verified; external hosts use the WordPress CA bundle.
			'verify'           => $insecure || $this->internal( $url ) ? false : ABSPATH . WPINC . '/certificates/ca-bundle.crt',
		);
	}

	/**
	 * Several requests in parallel.
	 *
	 * @param string[] $urls     URLs (any keys).
	 * @param string   $method   HEAD|GET.
	 * @param bool     $insecure Skip certificate verification.
	 * @return array key => ['code'=>int,'location'=>?string] | ['error'=>string]
	 */
	private function multi( array $urls, $method, $insecure = false ) {
		if ( ! $urls ) {
			return array();
		}
		$reqs = array();
		foreach ( $urls as $k => $url ) {
			// request_multiple() ignores the "verify" option in the cURL transport; set it on the handle.
			$no_verify = $insecure || $this->internal( $url );
			$hooks     = new Hooks();
			$hooks->register(
				'curl.before_multi_add',
				function ( &$handle ) use ( $no_verify ) {
					if ( $no_verify ) {
						curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Requests exposes the raw handle here.
						curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
					} else {
						curl_setopt( $handle, CURLOPT_CAINFO, ABSPATH . WPINC . '/certificates/ca-bundle.crt' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- see above.
					}
				}
			);
			$reqs[ $k ] = array(
				'url'     => $url,
				'type'    => 'HEAD' === $method ? Requests::HEAD : Requests::GET,
				'headers' => array(
					'Accept'          => 'text/html,*/*;q=0.8',
					'Accept-Language' => 'de-DE,de;q=0.9',
				),
				'options' => $this->options( $url, $insecure ) + array( 'hooks' => $hooks ),
			);
		}
		$out = array();
		try {
			$res = Requests::request_multiple( $reqs, array( 'timeout' => 10 ) );
		} catch ( Throwable $e ) {
			foreach ( array_keys( $urls ) as $k ) {
				$out[ $k ] = array( 'error' => $e->getMessage() );
			}
			return $out;
		}
		foreach ( $res as $k => $r ) {
			if ( $r instanceof Throwable || ! is_object( $r ) || ! isset( $r->status_code ) ) {
				$msg       = $r instanceof Throwable ? $r->getMessage() : __( 'no response', 'siteproof' );
				$out[ $k ] = array( 'error' => stripos( $msg, 'timed out' ) !== false ? __( 'Timeout', 'siteproof' ) : $msg );
				continue;
			}
			$loc       = $r->headers['location'] ?? null;
			$out[ $k ] = array(
				'code'     => (int) $r->status_code,
				'location' => $loc ? WP_Http::make_absolute_url( is_array( $loc ) ? end( $loc ) : $loc, $urls[ $k ] ) : null,
			);
		}
		return $out;
	}

	/**
	 * Check one batch.
	 *
	 * @param string[] $urls URLs.
	 * @return array url => result
	 */
	public function check( array $urls ) {
		$results = array();
		$http    = array();
		foreach ( $urls as $url ) {
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				$body = rawurldecode( preg_replace( '/^[a-z]+:/i', '', strtok( $url, '?' ) ) );
				$ok   = 'mailto' === $scheme ? (bool) preg_match( '/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i', $body ) : ( 'tel' === $scheme ? (bool) preg_match( '/^\+?[\d\s()\/-]{6,}$/', $body ) : true );
				$results[ $url ] = array(
					'internal' => false,
					'kind'     => $scheme,
					'status'   => $ok ? 'skipped' : 'broken',
					'note'     => $ok ? __( 'Format ok (not requested)', 'siteproof' ) : __( 'Invalid format', 'siteproof' ),
				);
				continue;
			}
			$http[] = $url;
		}

		$req = array();
		foreach ( $http as $url ) {
			$req[ $url ] = strtok( $url, '#' );
		}
		$first = $this->multi( $req, 'HEAD' );
		// Some servers refuse HEAD.
		$retry = array();
		foreach ( $first as $url => $r ) {
			if ( isset( $r['code'] ) && in_array( $r['code'], array( 400, 403, 405, 501 ), true ) ) {
				$retry[ $url ] = $req[ $url ];
			}
		}
		foreach ( $this->multi( $retry, 'GET' ) as $url => $r ) {
			$first[ $url ] = $r;
		}
		// Certificate errors: PHP's CA bundle may lack intermediates that browsers fetch themselves.
		// Retry without verification and report "reachable, certificate not verifiable" instead of "broken".
		$ssl = array();
		foreach ( $first as $url => $r ) {
			if ( isset( $r['error'] ) && ! $this->internal( $url ) && preg_match( '/certificate|SSL/i', $r['error'] ) ) {
				$ssl[ $url ] = $req[ $url ];
			}
		}
		foreach ( $this->multi( $ssl, 'GET', true ) as $url => $r ) {
			if ( isset( $r['code'] ) ) {
				$first[ $url ] = $r + array( 'ssl' => $first[ $url ]['error'] );
			}
		}

		$follow = array();
		foreach ( $http as $url ) {
			$r        = $first[ $url ] ?? array( 'error' => __( 'no response', 'siteproof' ) );
			$host     = (string) wp_parse_url( $url, PHP_URL_HOST );
			$internal = $this->internal( $url );
			$res      = array(
				'internal' => $internal,
				'kind'     => $internal && wp_parse_url( $url, PHP_URL_FRAGMENT ) ? 'anchor' : 'http',
			);
			if ( isset( $r['error'] ) ) {
				$res['status'] = preg_match( self::BOT_HOSTS, $host ) ? 'blocked' : 'broken';
				$res['note']   = $r['error'];
				$results[ $url ] = $res;
				continue;
			}
			$code        = $r['code'];
			$res['code'] = $code;
			$bot         = (bool) preg_match( self::BOT_HOSTS, $host );
			if ( isset( $r['ssl'] ) && $code < 400 ) {
				$res['status'] = 'ssl';
				$res['note']   = sprintf( /* translators: %s: TLS error */ __( 'Reachable, but the certificate could not be verified (%s) - check in a browser', 'siteproof' ), $r['ssl'] );
			} elseif ( 429 === $code ) {
				$res['status'] = 'blocked';
				$res['note']   = __( '429 too many requests (rate limit) - check manually', 'siteproof' );
			} elseif ( 403 === $code && ! $internal ) {
				$res['status'] = 'blocked';
				$res['note']   = __( '403 access denied for automated requests - check manually', 'siteproof' );
			} elseif ( $bot && ( $code < 200 || $code >= 300 ) ) {
				$res['status'] = 'blocked';
				$res['note']   = sprintf( /* translators: %d: HTTP status code */ __( 'Social network answers bots with %d - check manually', 'siteproof' ), $code );
			} elseif ( in_array( $code, array( 301, 308 ), true ) ) {
				$res['status']   = 'redirect';
				$res['location'] = $r['location'];
			} elseif ( in_array( $code, array( 302, 303, 307 ), true ) ) {
				$res['status']   = 'temp-redirect';
				$res['location'] = $r['location'];
			} elseif ( 999 === $code ) {
				$res['status'] = 'blocked';
				$res['note']   = __( 'Site blocks automated requests - check manually', 'siteproof' );
			} elseif ( $code >= 400 ) {
				$res['status'] = 'broken';
			} else {
				$res['status'] = 'ok';
			}
			// Redirect to the front page = hidden 404.
			if ( ! empty( $res['location'] ) ) {
				$loc_path = (string) wp_parse_url( $res['location'], PHP_URL_PATH );
				$loc_host = preg_replace( '/^www\./', '', (string) wp_parse_url( $res['location'], PHP_URL_HOST ) );
				$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
				if ( in_array( $loc_path, array( '', '/' ), true ) && ! in_array( $path, array( '', '/' ), true ) && preg_replace( '/^www\./', '', $host ) === $loc_host ) {
					$res['status'] = 'broken';
					$res['note']   = __( 'Redirects to the front page - target probably does not exist (soft 404)', 'siteproof' );
				} else {
					$follow[ $url ] = $res['location'];
				}
			}
			$results[ $url ] = $res;
		}

		// Check the redirect target (one hop) so that "301 to 404" is caught.
		foreach ( $this->multi( $follow, 'HEAD' ) as $url => $r ) {
			if ( isset( $r['code'] ) ) {
				$results[ $url ]['finalCode'] = $r['code'];
				if ( 403 === $r['code'] && ! $this->internal( $url ) ) {
					$results[ $url ]['status'] = 'blocked';
					$results[ $url ]['note']   = __( '403 access denied for automated requests - check manually', 'siteproof' );
				} elseif ( $r['code'] >= 400 && 405 !== $r['code'] ) {
					$results[ $url ]['status'] = 'broken';
				}
			}
		}

		// Anchors.
		foreach ( $http as $url ) {
			$frag = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
			if ( '' === $frag || 'ok' !== ( $results[ $url ]['status'] ?? '' ) ) {
				continue;
			}
			$id = rawurldecode( $frag );
			if ( ! $this->has_anchor( $url, $id ) ) {
				$results[ $url ]['status'] = 'anchor-missing';
				$results[ $url ]['note']   = sprintf( /* translators: %s: anchor ID */ __( 'Anchor #%s does not exist', 'siteproof' ), $id );
			}
		}
		return $results;
	}

	/**
	 * Does the anchor exist?
	 *
	 * @param string $url URL.
	 * @param string $id  Anchors.
	 * @return bool
	 */
	private function has_anchor( $url, $id ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( $this->internal( $url ) && isset( $this->ids_by_path[ $path ] ) ) {
			return isset( $this->ids_by_path[ $path ][ $id ] );
		}
		$key = strtok( $url, '#' );
		if ( ! isset( $this->html_cache[ $key ] ) ) {
			$r                        = wp_remote_get(
				$key,
				array(
					'timeout'    => 10,
					'sslverify'  => ! $this->internal( $url ),
					'user-agent' => self::UA,
				)
			);
			$this->html_cache[ $key ] = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
		}
		return (bool) preg_match( '/(id|name)=["\']' . preg_quote( $id, '/' ) . '["\']/', $this->html_cache[ $key ] );
	}
}

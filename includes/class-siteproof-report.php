<?php
/**
 * Report: site-wide assessment, overview, global (header/footer), pages as a tree.
 * Everything sorted by severity, sticky filter (red / red and yellow / all), CSS dots,
 * "very critical" as a red bar. Comparison with the previous run via finding keys.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Report builder.
 */
class Siteproof_Report {

	const LEVEL = array(
		'green'  => 0,
		'grey'   => 0,
		'yellow' => 1,
		'red'    => 2,
	);

	/** @var array Run */
	private $run;
	/** @var array Normalised pages */
	private $pages = array();
	/** @var array Pages that could not be loaded */
	private $failed = array();
	/** @var array Site info */
	private $site;
	/** @var array Thresholds */
	private $th;
	/** @var array Link results */
	private $links;
	/** @var array Template items */
	private $tpl = array(
		'headings' => array(),
		'images'   => array(),
		'links'    => array(),
		'path'     => '',
	);
	/** @var array axe rules on almost all pages */
	private $global_axe = array();
	/** @var array axe rule => page count */
	private $axe_count = array();
	/** @var array Finding keys of this run */
	private $keys = array();
	/** @var array|null Keys of the previous run */
	private $prev_keys = null;
	/** @var array Counts per status */
	private $counts = array(
		'red'    => 0,
		'yellow' => 0,
		'green'  => 0,
	);
	/** @var bool Static export (no forms) */
	private $static;
	/** @var array Fixes saved from the report */
	private $fixes;
	/** @var array path => anchor IDs from the browser measurement */
	private $browser_ids = array();
	/** @var string Home host */
	private $home_host;
	/** @var array Level per page and section: post ID => section => level */
	private $levels = array();

	/**
	 * Constructor.
	 *
	 * @param array $run    Run row.
	 * @param array $pages  Page rows.
	 * @param bool  $static Export without interactive parts.
	 */
	public function __construct( array $run, array $pages, $static = false ) {
		$this->run       = $run;
		$this->static    = $static;
		$this->site      = ( $run['data']['site'] ?? array() ) + array(
			'name'           => get_bloginfo( 'name' ),
			'home'           => untrailingslashit( home_url() ),
			'defaultOgImage' => null,
			'public'         => 1,
		);
		$this->th        = ( $run['settings']['thresholds'] ?? array() ) + Siteproof_Settings::defaults()['thresholds'];
		$this->links     = $run['data']['links'] ?? array();
		$this->fixes     = $run['data']['fixes'] ?? array();
		$this->home_host = (string) wp_parse_url( $this->site['home'], PHP_URL_HOST );
		$compare         = $run['summary']['compare'] ?? null;
		if ( $compare ) {
			$this->prev_keys = array_flip( (array) $compare['keys'] );
		}
		$this->normalise( $pages );
	}

	// ------------------------------------------------------------------ Data

	/**
	 * Map page rows to the report structure and split off template items.
	 *
	 * @param array $rows Page rows.
	 */
	private function normalise( array $rows ) {
		foreach ( $rows as $row ) {
			$s = $row['server'] ?? null;
			if ( ! $s || ! empty( $s['error'] ) ) {
				$this->failed[] = array(
					'path'  => (string) wp_parse_url( $row['url'], PHP_URL_PATH ),
					'error' => $s['error'] ?? __( 'not collected', 'siteproof' ),
				);
				continue;
			}
			$b        = $row['browser'] ?? array();
			$headings = $s['headings'] ?? array();
			$visible  = $b['desktop']['headingsVisible'] ?? null;
			if ( is_array( $visible ) && count( $visible ) === count( $headings ) ) {
				foreach ( $headings as $i => &$h ) {
					$h['visible'] = (bool) $visible[ $i ];
				}
				unset( $h );
			}
			if ( ! empty( $b['desktop']['ids'] ) ) {
				$this->browser_ids[ (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ] = array_flip( (array) $b['desktop']['ids'] );
			}
			$psi   = $row['psi'] ?? null;
			$speed = null;
			if ( $psi && empty( $psi['error'] ) ) {
				$speed = $psi;
			} elseif ( ! empty( $b['mobile']['timing'] ) ) {
				$speed           = $b['mobile']['timing'];
				$speed['source'] = 'local';
				if ( $psi && empty( $psi['local'] ) ) {
					$speed['psiError'] = $psi['error'];
				}
			}
			$this->pages[] = array(
				'id'        => $row['post_id'],
				'title'     => $row['title'],
				'url'       => $row['url'],
				'path'      => (string) wp_parse_url( $row['url'], PHP_URL_PATH ),
				'status'    => $s['status'] ?? null,
				'seo'       => $s['seo'] ?? array(),
				'seoStored' => $s['seoStored'] ?? array(),
				'headings'  => $headings,
				'segments'  => $s['segments'] ?? array(),
				'links'     => $s['links'] ?? array(),
				'images'    => $b['mobile']['images'] ?? ( $s['images'] ?? array() ),
				'media'     => $b['mobile']['media'] ?? ( $s['media'] ?? array() ),
				'axe'       => isset( $b['desktop']['axe'] ) ? $b['desktop']['axe'] : null,
				'speed'     => $speed,
				'review'    => $row['review'] ?? null,
				'browser'   => ! empty( $b ) && empty( $b['error'] ),
				'carried'   => $row['carried'] ?? array(),
			);
		}
		// Template items (header/footer/popups) only once, taken from the first page.
		if ( $this->pages ) {
			$tpl             = fn( $x ) => ! empty( $x['region'] ) && 'content' !== $x['region'];
			$first           = $this->pages[0];
			$this->tpl       = array(
				'path'     => $first['path'],
				'headings' => array_values( array_filter( $first['headings'], $tpl ) ),
				'images'   => array_values( array_filter( $first['images'], $tpl ) ),
				'links'    => array_values( array_filter( $first['links'], $tpl ) ),
			);
			foreach ( $this->pages as &$p ) {
				$p['headings'] = array_values( array_filter( $p['headings'], fn( $x ) => ! $tpl( $x ) ) );
				$p['links']    = array_values( array_filter( $p['links'], fn( $x ) => ! $tpl( $x ) ) );
				$p['images']   = array_values( array_filter( $p['images'], fn( $x ) => ! $tpl( $x ) ) );
			}
			unset( $p );
		}
		// axe rules on >= x % of the pages are template issues.
		$with_axe = array_filter( $this->pages, fn( $p ) => is_array( $p['axe'] ) );
		foreach ( $with_axe as $p ) {
			foreach ( $p['axe'] as $v ) {
				$this->axe_count[ $v['id'] ] = ( $this->axe_count[ $v['id'] ] ?? 0 ) + 1;
			}
		}
		$n = count( $with_axe );
		foreach ( $this->axe_count as $id => $c ) {
			if ( $n >= 3 && $c >= $n * ( (float) $this->th['global_share'] / 100 ) ) {
				$this->global_axe[ $id ] = true;
			}
		}
	}

	// ------------------------------------------------------------------ Helpers

	/**
	 * Escape.
	 *
	 * @param mixed $s Value.
	 * @return string
	 */
	private static function e( $s ) {
		return esc_html( (string) $s );
	}

	/**
	 * Format bytes as KB/MB.
	 *
	 * @param int|null $b Bytes.
	 * @return string
	 */
	private static function kb( $b ) {
		if ( null === $b ) {
			return '–';
		}
		return $b >= 1048576 ? number_format_i18n( $b / 1048576, 2 ) . ' MB' : number_format_i18n( round( $b / 1024 ) ) . ' KB';
	}

	/**
	 * Format bytes as MB.
	 *
	 * @param int|null $b Bytes.
	 * @return string
	 */
	private static function mb( $b ) {
		return null === $b ? '–' : number_format_i18n( $b / 1048576, 2 ) . ' MB';
	}

	/**
	 * Format milliseconds as seconds.
	 *
	 * @param float|null $ms Milliseconds.
	 * @return string
	 */
	private static function sec( $ms ) {
		return null === $ms ? '–' : number_format_i18n( $ms / 1000, 1 ) . ' s';
	}

	/**
	 * Status labels.
	 *
	 * @param string $level Level.
	 * @return string
	 */
	public static function label( $level ) {
		$l = array(
			'green'  => __( 'OK', 'siteproof' ),
			'yellow' => __( 'check', 'siteproof' ),
			'red'    => __( 'critical', 'siteproof' ),
			'grey'   => __( 'not checkable', 'siteproof' ),
		);
		return $l[ $level ] ?? $level;
	}

	/**
	 * Worst status of a list.
	 *
	 * @param array $list Items with level/blink.
	 * @return array
	 */
	private static function worst( array $list ) {
		$w = array(
			'level' => 'green',
			'blink' => false,
		);
		foreach ( $list as $x ) {
			$x += array( 'blink' => false );
			if ( self::LEVEL[ $x['level'] ] > self::LEVEL[ $w['level'] ] || ( self::LEVEL[ $x['level'] ] === self::LEVEL[ $w['level'] ] && $x['blink'] && ! $w['blink'] ) ) {
				$w = array(
					'level' => $x['level'],
					'blink' => (bool) $x['blink'],
				);
			}
		}
		return $w;
	}

	/**
	 * Severity for sorting: very critical > red > yellow > grey > green.
	 *
	 * @param array $x Item.
	 * @return int
	 */
	private static function sev( array $x ) {
		if ( ! empty( $x['blink'] ) && 'red' === $x['level'] ) {
			return 4;
		}
		return array(
			'red'    => 3,
			'yellow' => 2,
			'grey'   => 1,
			'green'  => 0,
		)[ $x['level'] ] ?? 0;
	}

	/**
	 * Sort by severity (stable, descending).
	 *
	 * @param array    $list List.
	 * @param callable $get  Item -> status.
	 * @return array
	 */
	private static function by_sev( array $list, callable $get ) {
		$i = 0;
		foreach ( $list as &$x ) {
			$x = array( $x, $i++ );
		}
		unset( $x );
		usort( $list, fn( $a, $b ) => self::sev( $get( $b[0] ) ) <=> self::sev( $get( $a[0] ) ) ?: $a[1] <=> $b[1] );
		return array_column( $list, 0 );
	}

	/**
	 * data-level attribute for the filter.
	 *
	 * @param array $x Status.
	 * @return string
	 */
	private static function dl( array $x ) {
		return ' data-level="' . ( ! empty( $x['blink'] ) && 'red' === $x['level'] ? 'crit' : esc_attr( $x['level'] ) ) . '"';
	}

	/**
	 * Status dot.
	 *
	 * @param string $level  Level.
	 * @param bool   $blink  Very critical (bar).
	 * @param bool   $square External link.
	 * @param string $title  Title.
	 * @return string
	 */
	private static function dot( $level, $blink = false, $square = false, $title = '' ) {
		$crit = $blink && 'red' === $level;
		$t    = '' !== $title ? $title : ( $crit ? __( 'very critical', 'siteproof' ) : self::label( $level ) );
		return sprintf( '<span class="sp-dot sp-dot--%s%s%s" role="img" aria-label="%s" title="%s"></span>', esc_attr( $level ), $square ? ' sp-dot--sq' : '', $crit ? ' sp-dot--crit' : '', esc_attr( $t ), esc_attr( $t ) );
	}

	/**
	 * Register a finding key; is it new compared with the previous run?
	 *
	 * @param string $k Key.
	 * @return bool
	 */
	private function is_new( $k ) {
		$this->keys[ $k ] = true;
		return null !== $this->prev_keys && ! isset( $this->prev_keys[ $k ] );
	}

	/**
	 * "new" badge.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	private function new_badge( $k ) {
		return $this->is_new( $k ) ? ' <span class="sp-new">' . esc_html__( 'new', 'siteproof' ) . '</span>' : '';
	}

	/**
	 * Local file name.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	private static function file( $src ) {
		return Siteproof_Collector::file_name( $src );
	}

	/**
	 * Edit link of a post.
	 *
	 * @param int $id Post ID.
	 * @return string
	 */
	private static function edit( $id ) {
		return admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
	}

	/**
	 * Internal URL?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function internal( $url ) {
		return wp_parse_url( $url, PHP_URL_HOST ) === $this->home_host;
	}

	/**
	 * Absolute URL of the default og:image.
	 *
	 * @return string|null
	 */
	private function default_og() {
		return empty( $this->site['defaultOgImage'] ) ? null : WP_Http::make_absolute_url( $this->site['defaultOgImage'], $this->site['home'] . '/' );
	}

	/**
	 * Threshold rating.
	 *
	 * @param float|null $v    Value.
	 * @param float      $g    Green up to.
	 * @param float      $y    Yellow up to.
	 * @param float|null $crit Very critical from.
	 * @return array
	 */
	private static function th( $v, $g, $y, $crit = null ) {
		if ( null === $v ) {
			return array(
				'level' => 'grey',
				'blink' => false,
			);
		}
		return array(
			'level' => $v <= $g ? 'green' : ( $v <= $y ? 'yellow' : 'red' ),
			'blink' => null !== $crit && $v > $crit,
		);
	}

	// ------------------------------------------------------------------ Ratings

	/**
	 * Rate an image (bytes, delivered vs needed width, KB per megapixel).
	 *
	 * @param array $img Image.
	 * @return array
	 */
	private function rate_image( array $img ) {
		$t       = $this->th;
		$bytes   = $img['bytes'] ?? ( $img['file']['bytes'] ?? null );
		$mime    = (string) ( $img['mime'] ?? '' ) !== '' ? (string) $img['mime'] : (string) ( $img['file']['mime'] ?? '' );
		$ext     = strtolower( (string) pathinfo( (string) wp_parse_url( $img['src'], PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		$type    = '' !== $mime ? str_replace( 'image/', '', $mime ) : substr( $ext, 0, 5 );
		$reasons = array();
		$level   = 'green';
		$blink   = false;
		$up      = function ( $l ) use ( &$level ) {
			if ( self::LEVEL[ $l ] > self::LEVEL[ $level ] ) {
				$level = $l;
			}
		};
		if ( ! $bytes && empty( $img['naturalWidth'] ) && empty( $img['background'] ) && ! empty( $img['renderedWidth'] ) ) {
			return array(
				'level'   => 'grey',
				'blink'   => false,
				'reasons' => array( __( 'not loaded (slider/lazy loading) - size unknown', 'siteproof' ) ),
				'bytes'   => null,
				'mime'    => $type,
			);
		}
		if ( str_contains( $mime, 'svg' ) || 'svg' === $ext ) {
			if ( $bytes > 100 * 1024 ) {
				$up( 'yellow' );
				$reasons[] = __( 'SVG over 100 KB', 'siteproof' );
			}
			return compact( 'level', 'blink', 'reasons', 'bytes' ) + array( 'mime' => 'svg' );
		}
		if ( $bytes > $t['img_crit'] * 1024 ) {
			$up( 'red' );
			$blink     = true;
			$reasons[] = sprintf( /* translators: 1: size, 2: limit */ __( '%1$s - over %2$s', 'siteproof' ), self::kb( $bytes ), self::kb( $t['img_crit'] * 1024 ) );
		} elseif ( $bytes > $t['img_red'] * 1024 ) {
			$up( 'red' );
			$reasons[] = sprintf( /* translators: 1: size, 2: limit */ __( '%1$s - over %2$s', 'siteproof' ), self::kb( $bytes ), self::kb( $t['img_red'] * 1024 ) );
		} elseif ( $bytes > $t['img_yellow'] * 1024 ) {
			$up( 'yellow' );
			$reasons[] = sprintf( /* translators: 1: size, 2: limit */ __( '%1$s - over %2$s', 'siteproof' ), self::kb( $bytes ), self::kb( $t['img_yellow'] * 1024 ) );
		}
		if ( empty( $img['background'] ) && ! empty( $img['naturalWidth'] ) && ! empty( $img['renderedWidth'] ) ) {
			$aspect = $img['naturalWidth'] / max( (int) ( $img['naturalHeight'] ?? 1 ), 1 );
			$need   = ( 'cover' === ( $img['objectFit'] ?? '' ) ? max( $img['renderedWidth'], ( $img['renderedHeight'] ?? 0 ) * $aspect ) : $img['renderedWidth'] ) * 2;
			$ratio  = $img['naturalWidth'] / max( $need, 1 );
			/* translators: 1: delivered width, 2: needed width, 3: ratio */
			$msg = __( '%1$dpx delivered, ~%2$dpx needed (%3$s×)', 'siteproof' );
			if ( $ratio > $t['ratio_red'] ) {
				$up( 'red' );
				$reasons[] = sprintf( $msg, $img['naturalWidth'], round( $need ), number_format_i18n( $ratio, 1 ) );
			} elseif ( $ratio > $t['ratio_yellow'] ) {
				$up( 'yellow' );
				$reasons[] = sprintf( $msg, $img['naturalWidth'], round( $need ), number_format_i18n( $ratio, 1 ) );
			} elseif ( $ratio < 0.6 ) {
				$up( 'yellow' );
				/* translators: 1: delivered width, 2: needed width */
				$reasons[] = sprintf( __( 'blurry: %1$dpx delivered, ~%2$dpx needed', 'siteproof' ), $img['naturalWidth'], round( $need ) );
			}
			if ( $bytes && ! empty( $img['naturalHeight'] ) ) {
				$kbmp = $bytes / 1024 / ( $img['naturalWidth'] * $img['naturalHeight'] / 1e6 );
				if ( $kbmp > $t['kbmp_red'] ) {
					$up( 'red' );
					/* translators: %d: KB per megapixel */
					$reasons[] = sprintf( __( '%d KB per megapixel (compression/format)', 'siteproof' ), round( $kbmp ) );
				} elseif ( $kbmp > $t['kbmp_yellow'] ) {
					$up( 'yellow' );
					/* translators: %d: KB per megapixel */
					$reasons[] = sprintf( __( '%d KB per megapixel', 'siteproof' ), round( $kbmp ) );
				}
			}
		}
		if ( $bytes > 100 * 1024 && preg_match( '/png|jpeg/', $mime ) ) {
			$reasons[] = __( 'WebP/AVIF usually saves 30-60 %', 'siteproof' );
		}
		return array(
			'level'   => $level,
			'blink'   => $blink,
			'reasons' => $reasons,
			'bytes'   => $bytes,
			'mime'    => $type,
		);
	}

	/**
	 * Rate a link from the link check.
	 *
	 * @param array $l Link.
	 * @return array
	 */
	private function rate_link( array $l ) {
		$r        = $this->links[ $l['href'] ] ?? array( 'status' => 'unchecked' );
		$internal = $r['internal'] ?? $this->internal( $l['href'] );
		switch ( $r['status'] ) {
			case 'ok':
				$res = array( 'green', __( 'reachable', 'siteproof' ) );
				break;
			case 'skipped':
				$res = array( 'green', $r['note'] ?? __( 'not requested', 'siteproof' ) );
				break;
			case 'redirect':
				/* translators: 1: status code, 2: target URL */
				$res = array( 'yellow', sprintf( __( '%1$d redirect -> %2$s', 'siteproof' ), $r['code'], $r['location'] ) );
				break;
			case 'temp-redirect':
				/* translators: 1: status code, 2: target URL */
				$res = array( 'yellow', sprintf( __( '%1$d temporary redirect -> %2$s', 'siteproof' ), $r['code'], $r['location'] ) );
				break;
			case 'broken':
				$res = array( 'red', trim( ( $r['code'] ?? '' ) . ' ' . ( $r['note'] ?? __( 'not reachable', 'siteproof' ) ) . ( ! empty( $r['finalCode'] ) ? ' (' . sprintf( /* translators: %d: status code */ __( 'target %d', 'siteproof' ), $r['finalCode'] ) . ')' : '' ) ) );
				break;
			case 'ssl':
				$res = array( 'yellow', $r['note'] );
				break;
			case 'anchor-missing':
				// Anchors added by JavaScript (or only visible when logged in) exist in the browser measurement.
				if ( $this->browser_anchor( $l['href'] ) ) {
					$res = array( 'green', __( 'reachable', 'siteproof' ) );
					break;
				}
				$res = array( 'red', $r['note'] );
				break;
			case 'blocked':
				$res = array( 'grey', $r['note'] ?? __( 'not checkable', 'siteproof' ) );
				break;
			default:
				$res = array( 'grey', __( 'not checked', 'siteproof' ) );
		}
		return array(
			'level'    => $res[0],
			'note'     => $res[1],
			'internal' => (bool) $internal,
			'blink'    => 'red' === $res[0] && $internal,
			'status'   => $r['status'],
			'location' => $r['location'] ?? null,
		);
	}

	/**
	 * Does the anchor exist in the browser measurement of the target page?
	 *
	 * @param string $href Link URL.
	 * @return bool
	 */
	private function browser_anchor( $href ) {
		$frag = rawurldecode( (string) wp_parse_url( $href, PHP_URL_FRAGMENT ) );
		$path = (string) wp_parse_url( $href, PHP_URL_PATH );
		return '' !== $frag && isset( $this->browser_ids[ $path ][ $frag ] );
	}

	// ------------------------------------------------------------------ Sections

	/**
	 * SEO section.
	 *
	 * @param array $p Page.
	 * @return array
	 */
	private function seo_section( array $p ) {
		$s    = $p['seo'];
		$st   = $p['seoStored'];
		$rv   = $p['review']['suggestions'] ?? array();
		$t    = $this->th;
		$has  = ! empty( $st['plugin'] );
		$rows = array();
		$add  = function ( $label, $level, $value, $note = '', $blink = false, $key = null ) use ( &$rows ) {
			$rows[] = compact( 'label', 'level', 'value', 'note', 'blink', 'key' );
		};
		$guess = function ( $v, $what = '' ) {
			/* translators: %s: field name */
			return '<span class="sp-guess">' . ( $v ? esc_html__( 'Suggestion:', 'siteproof' ) . ' ' . self::e( $v ) : sprintf( esc_html__( '%s missing - no suggestion yet', 'siteproof' ), self::e( $what ) ) ) . '</span>';
		};
		$noindex = (bool) preg_match( '/noindex/i', (string) ( $s['robots'] ?? '' ) );
		$add( __( 'Indexing', 'siteproof' ), $noindex ? 'red' : 'green', $noindex ? 'noindex' : 'index', self::e( $s['robots'] ?? '' ), false, $noindex ? "seo:{$p['id']}:noindex" : null );

		if ( empty( $s['title'] ) ) {
			$add( __( 'Meta title', 'siteproof' ), 'red', $guess( $rv['title'] ?? null, 'Title' ), '', true, "seo:{$p['id']}:title-missing" );
		} else {
			$len    = mb_strlen( $s['title'] );
			$custom = ! empty( $st['title'] );
			$lvl    = $len > $t['title_max'] || $len < $t['title_min'] ? 'yellow' : ( $custom || ! $has ? 'green' : 'yellow' );
			/* translators: %d: number of characters */
			$note = sprintf( __( '%d characters', 'siteproof' ), $len ) . ( $has ? ', ' . ( $custom ? __( 'custom title', 'siteproof' ) : sprintf( /* translators: %s: title template */ __( 'from template %s', 'siteproof' ), $st['titleTemplate'] ?? '' ) ) : '' );
			$add( __( 'Meta title', 'siteproof' ), $lvl, self::e( $s['title'] ), self::e( $note ) . ( ! empty( $rv['title'] ) && 'green' !== $lvl ? '<br>' . $guess( $rv['title'] ) : '' ), false, 'green' !== $lvl ? "seo:{$p['id']}:title" : null );
		}
		if ( empty( $s['description'] ) ) {
			$add( __( 'Meta description', 'siteproof' ), 'red', $guess( $rv['description'] ?? null, 'Description' ), '', false, "seo:{$p['id']}:desc-missing" );
		} else {
			$len    = mb_strlen( $s['description'] );
			$custom = ! empty( $st['description'] );
			$lvl    = $len > $t['desc_max'] || $len < $t['desc_min'] ? 'yellow' : ( $custom || ! $has ? 'green' : 'yellow' );
			/* translators: %d: number of characters */
			$note = sprintf( __( '%d characters', 'siteproof' ), $len ) . ( $has ? ', ' . ( $custom ? __( 'custom description', 'siteproof' ) : __( 'generated automatically', 'siteproof' ) ) : '' );
			$add( __( 'Meta description', 'siteproof' ), $lvl, self::e( $s['description'] ), self::e( $note ) . ( ! empty( $rv['description'] ) && 'green' !== $lvl ? '<br>' . $guess( $rv['description'] ) : '' ), false, 'green' !== $lvl ? "seo:{$p['id']}:desc" : null );
		}
		$add( 'og:title', ! empty( $s['ogTitle'] ) ? 'green' : 'red', ! empty( $s['ogTitle'] ) ? self::e( $s['ogTitle'] ) : $guess( $rv['title'] ?? null, 'og:title' ), '', false, ! empty( $s['ogTitle'] ) ? null : "seo:{$p['id']}:ogtitle" );
		$add( 'og:description', ! empty( $s['ogDescription'] ) ? 'green' : 'red', ! empty( $s['ogDescription'] ) ? self::e( $s['ogDescription'] ) : $guess( $rv['ogDescription'] ?? ( $rv['description'] ?? null ), 'og:description' ), '', false, ! empty( $s['ogDescription'] ) ? null : "seo:{$p['id']}:ogdesc" );
		$def = $this->default_og();
		if ( empty( $s['ogImage'] ) ) {
			$add( 'og:image', 'red', $guess( $rv['ogImage'] ?? null, 'og:image' ), '', false, "seo:{$p['id']}:ogimage" );
		} else {
			$is_def = $def && $s['ogImage'] === $def;
			$add( 'og:image', $is_def ? 'yellow' : 'green', sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $s['ogImage'] ), self::e( self::file( $s['ogImage'] ) ) ), $is_def ? esc_html__( 'Default image of the website', 'siteproof' ) . ( ! empty( $rv['ogImage'] ) ? '<br>' . $guess( $rv['ogImage'] ) : '' ) : '', false, $is_def ? "seo:{$p['id']}:ogdefault" : null );
		}
		if ( ! empty( $s['canonical'] ) && wp_parse_url( $s['canonical'], PHP_URL_PATH ) !== $p['path'] ) {
			$add( 'Canonical', 'yellow', self::e( $s['canonical'] ), esc_html__( 'points to a different URL', 'siteproof' ), false, "seo:{$p['id']}:canonical" );
		}
		$status = self::worst( $rows );
		$rows   = self::by_sev( $rows, fn( $r ) => $r );
		$html   = '<table class="sp-table sp-kv"><tbody>';
		foreach ( $rows as $r ) {
			$html .= sprintf( '<tr%s><th scope="row">%s %s%s</th><td>%s%s</td></tr>', self::dl( $r ), self::dot( $r['level'], $r['blink'] ), self::e( $r['label'] ), $r['key'] ? $this->new_badge( $r['key'] ) : '', $r['value'], $r['note'] ? '<div class="sp-note">' . $r['note'] . '</div>' : '' );
		}
		$html .= '</tbody></table>';
		return compact( 'status', 'html' );
	}

	/**
	 * Load time section (PageSpeed Insights or local measurement).
	 *
	 * @param array $p Page.
	 * @return array
	 */
	private function speed_section( array $p ) {
		$s = $p['speed'];
		if ( ! $s ) {
			return array(
				'status' => array( 'level' => 'grey' ),
				'html'   => '<p class="sp-note">' . esc_html__( 'Not measured.', 'siteproof' ) . '</p>',
			);
		}
		$t        = $this->th;
		$b        = ( $s['bytes'] ?? array() ) + array_fill_keys( array( 'total', 'document', 'script', 'stylesheet', 'font', 'thirdParty' ), null );
		$imgs     = $p['images'];
		$img_tot  = array_sum( array_map( fn( $i ) => (int) ( $i['bytes'] ?? ( $i['file']['bytes'] ?? 0 ) ), $imgs ) );
		$not_lazy = array_filter( $imgs, fn( $i ) => empty( $i['aboveFold'] ) && 'lazy' !== ( $i['loading'] ?? '' ) && empty( $i['background'] ) && ! empty( $i['loadedInitially'] ) );
		$lazy_med = array_filter( $p['media'], fn( $m ) => isset( $m['aboveFold'] ) && ! $m['aboveFold'] && 'iframe' === $m['tag'] && 'lazy' !== ( $m['loading'] ?? '' ) );
		$psi      = 'psi' === ( $s['source'] ?? '' );
		$mbv      = 1048576;
		$rows     = array(
			array( __( 'Load time (load)', 'siteproof' ), self::sec( $s['load'] ?? null ), self::th( $s['load'] ?? null, 5000, 10000, 20000 ), __( 'until everything is loaded', 'siteproof' ) ),
			array( __( 'First Contentful Paint', 'siteproof' ), self::sec( $s['fcp'] ?? null ), self::th( $s['fcp'] ?? null, 1800, 3000 ), __( 'first visible content', 'siteproof' ) ),
			array( __( 'Largest Contentful Paint', 'siteproof' ), self::sec( $s['lcp'] ?? null ), self::th( $s['lcp'] ?? null, $t['lcp_green'], $t['lcp_yellow'], $t['lcp_crit'] ), __( 'largest element visible (Core Web Vital)', 'siteproof' ) ),
			array( __( 'Layout shift (CLS)', 'siteproof' ), isset( $s['cls'] ) ? number_format_i18n( (float) $s['cls'], 3 ) : '–', self::th( isset( $s['cls'] ) ? (float) $s['cls'] : null, 0.1, 0.25 ), __( 'Core Web Vital', 'siteproof' ) ),
		);
		if ( $psi ) {
			$rows[] = array( __( 'Total Blocking Time', 'siteproof' ), isset( $s['tbt'] ) ? round( $s['tbt'] ) . ' ms' : '–', self::th( $s['tbt'] ?? null, 200, 600 ), __( 'lab proxy for INP', 'siteproof' ) );
		}
		$rows = array_merge(
			$rows,
			array(
				/* translators: %d: number of requests */
				array( __( 'Total size (initial)', 'siteproof' ), self::mb( $b['total'] ), self::th( $b['total'], $t['total_green'] * $mbv, $t['total_yellow'] * $mbv, $t['total_crit'] * $mbv ), sprintf( __( '%d requests', 'siteproof' ), (int) ( $s['requests'] ?? 0 ) ) ),
				array( 'HTML', self::kb( $b['document'] ), self::th( $b['document'], 100 * 1024, 250 * 1024 ), '' ),
				array( 'JavaScript', self::kb( $b['script'] ), self::th( $b['script'], 300 * 1024, 600 * 1024 ), '' ),
				array( 'CSS', self::kb( $b['stylesheet'] ), self::th( $b['stylesheet'], 100 * 1024, 200 * 1024 ), '' ),
				array( __( 'Fonts', 'siteproof' ), self::kb( $b['font'] ), self::th( $b['font'], 150 * 1024, 300 * 1024 ), '' ),
				array( __( 'Third parties', 'siteproof' ), self::kb( $b['thirdParty'] ), self::th( $b['thirdParty'], 200 * 1024, 500 * 1024 ), __( 'other domains', 'siteproof' ) ),
				/* translators: %d: number of images */
				array( __( 'Images and media total', 'siteproof' ), sprintf( '<a href="#sp-img-%d">%s</a>', $p['id'], self::mb( $img_tot ) ), self::th( $img_tot, $t['total_green'] * $mbv, $t['total_yellow'] * $mbv, $t['media_crit'] * $mbv ), sprintf( __( 'after scrolling through, %d images', 'siteproof' ), count( $imgs ) ) ),
			)
		);
		if ( $p['browser'] ) {
			$nl     = count( $not_lazy );
			$nm     = count( $lazy_med );
			/* translators: 1: images, 2: iframes */
			$rows[] = array( __( 'Not lazy loaded', 'siteproof' ), sprintf( __( '%1$d images, %2$d iframes', 'siteproof' ), $nl, $nm ), array( 'level' => 0 === $nl + $nm ? 'green' : ( $nl > 5 ? 'red' : 'yellow' ) ), __( 'below the visible area, but loaded immediately', 'siteproof' ) );
		}
		if ( ! empty( $s['field'] ) ) {
			$f      = $s['field'];
			$rows[] = array( __( 'LCP (real users)', 'siteproof' ), self::sec( $f['lcp'] ), self::th( $f['lcp'], 2500, 4000 ), __( 'Chrome UX Report, 28 days, 75th percentile', 'siteproof' ) );
			$rows[] = array( __( 'INP (real users)', 'siteproof' ), null === $f['inp'] ? '–' : $f['inp'] . ' ms', self::th( $f['inp'], 200, 500 ), __( 'Interaction to Next Paint', 'siteproof' ) );
			$rows[] = array( __( 'CLS (real users)', 'siteproof' ), null === $f['cls'] ? '–' : number_format_i18n( $f['cls'], 2 ), self::th( $f['cls'], 0.1, 0.25 ), '' );
		}
		$status = self::worst( array_column( $rows, 2 ) );
		$rows   = self::by_sev( $rows, fn( $r ) => $r[2] + array( 'blink' => false ) );
		$sent   = $p['review']['speed'] ?? $this->auto_speed( $s, $img_tot, count( $not_lazy ) );
		$html   = '<p class="sp-lead">' . self::e( $sent ) . '</p><table class="sp-table sp-kv"><tbody>';
		foreach ( $rows as $r ) {
			$r[2] += array( 'blink' => false );
			$key   = "speed:{$p['id']}:" . sanitize_title( $r[0] );
			$badge = in_array( $r[2]['level'], array( 'yellow', 'red' ), true ) ? $this->new_badge( $key ) : '';
			$html .= sprintf( '<tr%s><th scope="row">%s %s%s</th><td>%s%s</td></tr>', self::dl( $r[2] ), self::dot( $r[2]['level'], $r[2]['blink'] ), self::e( $r[0] ), $badge, wp_kses( $r[1], array( 'a' => array( 'href' => true ) ) ), $r[3] ? '<div class="sp-note">' . self::e( $r[3] ) . '</div>' : '' );
		}
		$html .= '</tbody></table>';
		if ( $psi ) {
			/* translators: %s: measured URL */
			$note = sprintf( __( 'Measured by PageSpeed Insights (Lighthouse mobile, simulated 4G and CPU slowdown) against %s.', 'siteproof' ), $s['url'] ) . ( null !== ( $s['score'] ?? null ) ? ' ' . sprintf( /* translators: %d: score */ __( 'Performance score %d.', 'siteproof' ), $s['score'] ) : '' ) . ( empty( $s['field'] ) ? ' ' . __( 'No field data from real users for this URL.', 'siteproof' ) : '' );
		} else {
			$note = __( 'Measured locally in the browser (390px wide), unthrottled, browser cache possible - values are optimistic. Store a PageSpeed Insights key (and a live URL for staging sites) for realistic numbers.', 'siteproof' ) . ( ! empty( $s['psiError'] ) ? ' ' . sprintf( /* translators: %s: error */ __( 'PageSpeed Insights failed: %s', 'siteproof' ), $s['psiError'] ) : '' );
		}
		$html .= '<p class="sp-note">' . self::e( $note ) . '</p>';
		return compact( 'status', 'html' );
	}

	/**
	 * Automatic speed sentence without AI.
	 *
	 * @param array $s        Speed.
	 * @param int   $img_tot  Image bytes.
	 * @param int   $not_lazy Not lazy loaded images.
	 * @return string
	 */
	private function auto_speed( array $s, $img_tot, $not_lazy ) {
		$parts = array();
		if ( ( $s['lcp'] ?? 0 ) > $this->th['lcp_yellow'] ) {
			/* translators: %s: seconds */
			$parts[] = sprintf( __( 'the largest content appears only after %s', 'siteproof' ), self::sec( $s['lcp'] ) );
		}
		if ( $img_tot > $this->th['total_yellow'] * 1048576 ) {
			/* translators: %s: size */
			$parts[] = sprintf( __( 'images account for %s', 'siteproof' ), self::mb( $img_tot ) );
		}
		if ( $not_lazy ) {
			/* translators: %d: number of images */
			$parts[] = sprintf( _n( '%d image loads immediately instead of on scroll', '%d images load immediately instead of on scroll', $not_lazy, 'siteproof' ), $not_lazy );
		}
		/* translators: %s: list of causes */
		return $parts ? sprintf( __( 'Too slow on mobile: %s.', 'siteproof' ), implode( ', ', $parts ) ) : __( 'Mobile load time is in the green range.', 'siteproof' );
	}

	/**
	 * Proofreading type labels.
	 *
	 * @param string $type Type.
	 * @return string
	 */
	private static function type_label( $type ) {
		$l = array(
			'spelling'    => __( 'Spelling', 'siteproof' ),
			'punctuation' => __( 'Punctuation', 'siteproof' ),
			'grammar'     => __( 'Grammar', 'siteproof' ),
			'style'       => __( 'Style', 'siteproof' ),
		);
		return $l[ $type ] ?? $type;
	}

	/**
	 * Content section: heading structure and proofreading.
	 *
	 * @param array $p Page.
	 * @return array
	 */
	private function content_section( array $p ) {
		$hs       = $p['headings'];
		$items    = '';
		$findings = array();
		$h1       = array_values( array_filter( $hs, fn( $h ) => 1 === $h['level'] ) );
		if ( ! $h1 ) {
			$findings[] = array(
				'level' => 'red',
				'blink' => true,
			);
			$items     .= '<li class="sp-h sp-h--bad">' . self::dot( 'red', true ) . ' <strong>' . esc_html__( 'No H1 found', 'siteproof' ) . '</strong>' . $this->new_badge( "content:{$p['id']}:noh1" ) . '</li>';
		}
		$prev     = 0;
		$h1_count = 0;
		foreach ( $hs as $i => $h ) {
			$skip  = $prev && $h['level'] > $prev + 1;
			$extra = 1 === $h['level'] && $h1_count++ > 0;
			$bad   = $skip || ( 0 === $prev && $h['level'] > 2 && ! $h1 );
			if ( $bad ) {
				$findings[] = array( 'level' => 'red' );
			}
			if ( $extra ) {
				$findings[] = array( 'level' => 'yellow' );
			}
			$vis    = isset( $h['visible'] ) && ! $h['visible'] ? ', ' . __( 'hidden', 'siteproof' ) : '';
			$items .= sprintf(
				'<li class="sp-h" style="--lvl:%1$d"><span class="sp-h__tag%2$s">H%1$d</span> <span class="%3$s">%4$s</span> <span class="sp-block">(%5$s)</span>%6$s%7$s</li>',
				$h['level'],
				$bad ? ' sp-bad' : ( $extra ? ' sp-warn' : '' ),
				$bad ? 'sp-bad' : '',
				self::e( $h['text'] ),
				self::e( $h['block'] . $vis ),
				/* translators: 1: previous level, 2: level */
				$bad ? ' <span class="sp-bad">' . esc_html( sprintf( __( 'Jump from H%1$d to H%2$d', 'siteproof' ), $prev, $h['level'] ) ) . '</span>' . $this->new_badge( "content:{$p['id']}:skip:$i" ) : '',
				$extra ? ' <span class="sp-warn">' . esc_html__( 'additional H1', 'siteproof' ) . '</span>' : ''
			);
			$prev = $h['level'];
		}
		// Proofreading.
		$rv    = $p['review'];
		$segs  = array();
		$proof = array();
		foreach ( $p['segments'] as $sg ) {
			$segs[ $sg['id'] ] = $sg;
		}
		if ( $rv && empty( $rv['error'] ) ) {
			foreach ( $rv['proofreading'] ?? array() as $i => $f ) {
				if ( isset( $segs[ $f['seg'] ] ) ) {
					$applied = $f['applied'] ?? null;
					$proof[] = $f + array(
						'index'   => $i,
						'segment' => $segs[ $f['seg'] ],
						'level'   => null !== $applied ? 'green' : ( 'spelling' === $f['type'] ? 'red' : 'yellow' ),
					);
				}
			}
		}
		foreach ( $proof as $f ) {
			$findings[] = array( 'level' => $f['level'] );
		}
		$proof = self::by_sev( $proof, fn( $f ) => $f );
		if ( $proof ) {
			$ph = '<div class="sp-scroll"><table class="sp-table sp-proof"><thead><tr><th scope="col"></th><th scope="col">' . esc_html__( 'Passage', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Correction', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Type', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Block', 'siteproof' ) . '</th></tr></thead><tbody>';
			foreach ( $proof as $f ) {
				$applied = $f['applied'] ?? null;
				$key     = "content:{$p['id']}:proof:" . $f['error'];
				if ( null !== $applied ) {
					$fix = '<strong>' . self::e( $applied ) . '</strong><div class="sp-note">' . esc_html__( 'applied', 'siteproof' ) . '</div>';
				} elseif ( $this->static ) {
					$fix = '<strong>' . self::e( $f['suggestion'] ) . '</strong>';
					$this->is_new( $key );
				} else {
					$this->is_new( $key );
					$fix = sprintf(
						'<form class="sp-fix" data-fix="text" data-post="%d" data-index="%d"><label class="screen-reader-text" for="sp-fix-%1$d-%2$d">%3$s</label><input id="sp-fix-%1$d-%2$d" type="text" name="value" value="%4$s" class="sp-fix__input"><button type="submit" class="button button-small">%5$s</button><span class="sp-fix__msg" role="status"></span></form>',
						$p['id'],
						$f['index'],
						esc_html__( 'Correction', 'siteproof' ),
						esc_attr( $f['suggestion'] ),
						esc_html__( 'Apply', 'siteproof' )
					);
				}
				$ph .= sprintf(
					'<tr%s><td>%s</td><td>%s%s</td><td>%s%s</td><td>%s</td><td class="sp-block">%s</td></tr>',
					self::dl( $f ),
					self::dot( $f['level'] ),
					self::highlight( $f['segment']['text'], $f['error'] ),
					null === $applied && null !== $this->prev_keys && ! isset( $this->prev_keys[ $key ] ) ? ' <span class="sp-new">' . esc_html__( 'new', 'siteproof' ) . '</span>' : '',
					$fix,
					$f['note'] ? '<div class="sp-note">' . self::e( $f['note'] ) . '</div>' : '',
					self::e( self::type_label( $f['type'] ) ),
					self::e( $f['segment']['block'] )
				);
			}
			$ph .= '</tbody></table></div>';
		} elseif ( $rv && ! empty( $rv['error'] ) ) {
			/* translators: %s: error message */
			$ph = '<p class="sp-note">' . esc_html( sprintf( __( 'Proofreading failed: %s', 'siteproof' ), $rv['error'] ) ) . '</p>';
		} else {
			$ph = '<p class="sp-note">' . ( isset( $rv['proofreading'] ) ? esc_html__( 'No errors found.', 'siteproof' ) : esc_html__( 'Not proofread yet (AI proofreading was not part of this run).', 'siteproof' ) ) . '</p>';
		}
		if ( isset( $rv['proofreading'] ) && ! empty( $rv['model'] ) ) {
			/* translators: 1: provider, 2: model */
			$ph .= '<p class="sp-note">' . esc_html( sprintf( __( 'Proofread by %1$s, model %2$s.', 'siteproof' ), Siteproof_Settings::providers()[ $rv['provider'] ] ?? $rv['provider'], $rv['model'] ) ) . ( ! empty( $rv['discarded'] ) ? ' ' . esc_html( sprintf( /* translators: %d: number */ _n( '%d finding discarded (text not found verbatim).', '%d findings discarded (text not found verbatim).', $rv['discarded'], 'siteproof' ), $rv['discarded'] ) ) : '' ) . '</p>';
		}
		$status = self::worst( $findings );
		$html   = '<h4 class="sp-sub">' . esc_html__( 'Heading structure', 'siteproof' ) . '</h4><ul class="sp-headings">' . ( '' !== $items ? $items : '<li class="sp-note">' . esc_html__( 'No headings in the content area.', 'siteproof' ) . '</li>' ) . '</ul><h4 class="sp-sub">' . esc_html__( 'Proofreading', 'siteproof' ) . '</h4>' . $ph;
		return compact( 'status', 'html' );
	}

	/**
	 * Segment excerpt with the error highlighted.
	 *
	 * @param string $text Segment.
	 * @param string $err  Error.
	 * @return string
	 */
	private static function highlight( $text, $err ) {
		$i = mb_strpos( $text, $err );
		if ( false === $i ) {
			return self::e( $text );
		}
		$a = max( 0, $i - 90 );
		$e = min( mb_strlen( $text ), $i + mb_strlen( $err ) + 90 );
		return ( $a ? '… ' : '' ) . self::e( mb_substr( $text, $a, $i - $a ) ) . '<mark class="sp-err">' . self::e( $err ) . '</mark>' . self::e( mb_substr( $text, $i + mb_strlen( $err ), $e - $i - mb_strlen( $err ) ) ) . ( $e < mb_strlen( $text ) ? ' …' : '' );
	}

	/**
	 * Images section.
	 *
	 * @param array      $p      Page (or template pseudo page).
	 * @param array|null $imgs   Images.
	 * @param string     $anchor Anchor ID.
	 * @return array
	 */
	private function images_section( array $p, $imgs = null, $anchor = '' ) {
		$imgs   = $imgs ?? $p['images'];
		$anchor = '' !== $anchor ? $anchor : 'sp-img-' . $p['id'];
		$seen   = array();
		$rows   = array();
		foreach ( $imgs as $img ) {
			$k = $img['src'] . '|' . ( $img['block'] ?? '' );
			if ( isset( $seen[ $k ] ) ) {
				continue;
			}
			$seen[ $k ] = true;
			$r          = $this->rate_image( $img );
			$att        = $img['attachmentId'] ?? ( $img['file']['id'] ?? null );
			$fixed      = $att && isset( $this->fixes['alt'][ $att ] ) ? $this->fixes['alt'][ $att ] : null;
			$alt        = null !== $fixed ? $fixed : ( $img['alt'] ?? null );
			$alt_empty  = empty( $img['background'] ) && ( null === $alt || '' === trim( (string) $alt ) );
			$key        = 'img:' . ( $p['id'] ?? 'tpl' ) . ':' . self::file( $img['src'] );
			$new        = false;
			if ( 'green' !== $r['level'] || $alt_empty ) {
				$new = $this->is_new( $key );
			}
			$row    = self::worst( array( $r, $alt_empty && empty( $img['decorative'] ) ? array( 'level' => 'red' ) : array( 'level' => 'green' ) ) );
			$rows[] = compact( 'img', 'r', 'att', 'alt', 'alt_empty', 'row', 'new', 'fixed' );
		}
		$rows   = self::by_sev( $rows, fn( $x ) => $x['row'] );
		$status = self::worst( array_merge( array_column( $rows, 'r' ), array_map( fn() => array( 'level' => 'red' ), array_filter( $rows, fn( $x ) => $x['alt_empty'] && empty( $x['img']['decorative'] ) ) ) ) );
		if ( ! $rows ) {
			return array(
				'status' => $status,
				'html'   => '<p class="sp-note">' . esc_html__( 'No images.', 'siteproof' ) . '</p>',
			);
		}
		$total = array_sum( array_map( fn( $x ) => (int) $x['r']['bytes'], $rows ) );
		/* translators: 1: number of images, 2: total size */
		$html  = '<p class="sp-note">' . esc_html( sprintf( __( '%1$d images, %2$s in total. Rating: delivered vs needed (rendered width × 2 for retina, mobile) and KB per megapixel.', 'siteproof' ), count( $rows ), self::mb( $total ) ) ) . '</p>';
		$html .= '<div class="sp-scroll"><table class="sp-table sp-images" id="' . esc_attr( $anchor ) . '"><thead><tr>';
		foreach ( array( '#', __( 'Rating', 'siteproof' ), __( 'Image', 'siteproof' ), __( 'Type', 'siteproof' ), __( 'Alt text', 'siteproof' ), __( 'Rendered', 'siteproof' ), __( 'Delivered', 'siteproof' ), __( 'Size', 'siteproof' ), __( 'Block', 'siteproof' ), __( 'Links', 'siteproof' ) ) as $th ) {
			$html .= '<th scope="col">' . self::e( $th ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		foreach ( $rows as $x ) {
			$img = $x['img'];
			$r   = $x['r'];
			if ( ! empty( $img['background'] ) ) {
				$alt_html = '<span class="sp-note">–</span>';
			} elseif ( $x['alt_empty'] ) {
				$alt_html = '<span class="sp-bad">' . esc_html__( 'not set', 'siteproof' ) . '</span>' . ( '' === ( $img['alt'] ?? null ) ? '<div class="sp-note">' . esc_html__( 'alt="" (decorative?)', 'siteproof' ) . '</div>' : '' );
				if ( $x['att'] && ! $this->static ) {
					$alt_html .= sprintf(
						'<form class="sp-fix" data-fix="alt" data-attachment="%1$d" data-post="%2$d"><label class="screen-reader-text" for="sp-alt-%1$d-%2$d">%3$s</label><input id="sp-alt-%1$d-%2$d" type="text" name="value" class="sp-fix__input" placeholder="%4$s" value="%5$s"><button type="submit" class="button button-small">%6$s</button><span class="sp-fix__msg" role="status"></span></form>',
						$x['att'],
						(int) ( $p['id'] ?? 0 ),
						esc_html__( 'Alt text', 'siteproof' ),
						esc_attr__( 'Describe the image', 'siteproof' ),
						esc_attr( (string) ( $img['file']['altDb'] ?? '' ) ),
						esc_html__( 'Save', 'siteproof' )
					);
				}
			} else {
				$alt_html = self::e( $x['alt'] ) . ( null !== $x['fixed'] ? '<div class="sp-note">' . esc_html__( 'saved from this report', 'siteproof' ) . '</div>' : '' );
			}
			$links = '';
			if ( ! empty( $p['path'] ) ) {
				$links .= sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $this->site['home'] . $p['path'] ), esc_html__( 'Page', 'siteproof' ) );
			}
			if ( ! empty( $p['id'] ) ) {
				$links .= sprintf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( self::edit( $p['id'] ) ), esc_html__( 'Edit page', 'siteproof' ) );
			}
			if ( $x['att'] ) {
				$links .= sprintf( ' <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( self::edit( $x['att'] ) ), esc_html__( 'Edit image', 'siteproof' ) );
			}
			$html .= sprintf(
				'<tr%s><td>%s</td><td>%s%s%s</td><td class="sp-file"><a href="%s" target="_blank" rel="noopener">%s</a>%s</td><td>%s</td><td class="sp-alt">%s</td><td>%s</td><td>%s</td><td>%s</td><td class="sp-block">%s</td><td class="sp-links">%s</td></tr>',
				self::dl( $x['row'] ),
				(int) ( $img['order'] ?? 0 ),
				self::dot( $r['level'], $r['blink'] ),
				$x['new'] ? ' <span class="sp-new">' . esc_html__( 'new', 'siteproof' ) . '</span>' : '',
				$r['reasons'] ? '<div class="sp-note">' . self::e( implode( '; ', $r['reasons'] ) ) . '</div>' : '',
				esc_url( $img['src'] ),
				self::e( self::file( $img['src'] ) ),
				! empty( $img['background'] ) ? '<div class="sp-note">' . esc_html__( 'CSS background', 'siteproof' ) . '</div>' : '',
				self::e( $r['mime'] ),
				$alt_html,
				! empty( $img['renderedWidth'] ) ? (int) $img['renderedWidth'] . ' × ' . (int) ( $img['renderedHeight'] ?? 0 ) : '–',
				! empty( $img['naturalWidth'] ) ? (int) $img['naturalWidth'] . ' × ' . (int) ( $img['naturalHeight'] ?? 0 ) : '–',
				self::mb( $r['bytes'] ),
				self::e( $img['block'] ?? '' ),
				$links
			);
		}
		$html .= '</tbody></table></div>';
		// Embedded media.
		$media = array_filter( $p['media'] ?? array(), fn( $m ) => 'content' === ( $m['region'] ?? 'content' ) );
		if ( $media ) {
			$html .= '<h4 class="sp-sub">' . esc_html__( 'Embedded media', 'siteproof' ) . '</h4><table class="sp-table"><tbody>';
			foreach ( $media as $m ) {
				$lvl   = array( 'level' => isset( $m['aboveFold'] ) && ! $m['aboveFold'] && 'lazy' !== ( $m['loading'] ?? '' ) && 'iframe' === $m['tag'] ? 'yellow' : 'green' );
				$html .= sprintf( '<tr%s><td>%s</td><td>%s</td><td class="sp-file">%s</td><td>%s%s</td><td class="sp-block">%s</td></tr>', self::dl( $lvl ), self::dot( $lvl['level'] ), self::e( $m['tag'] ), self::e( $m['src'] ?? '' ), ! empty( $m['loading'] ) ? 'loading=' . self::e( $m['loading'] ) : esc_html__( 'no loading attribute', 'siteproof' ), empty( $m['title'] ) && 'iframe' === $m['tag'] ? ' | <span class="sp-warn">' . esc_html__( 'no title', 'siteproof' ) . '</span>' : '', self::e( $m['block'] ?? '' ) );
			}
			$html .= '</tbody></table>';
		}
		return compact( 'status', 'html' );
	}

	/**
	 * Links section.
	 *
	 * @param array      $p     Page or template pseudo page.
	 * @param array|null $links Links.
	 * @return array
	 */
	private function links_section( array $p, $links = null ) {
		$links   = $links ?? $p['links'];
		$grouped = array();
		foreach ( $links as $l ) {
			$k = $l['href'] . '|' . $l['text'] . '|' . ( $l['image']['file'] ?? '' );
			if ( ! isset( $grouped[ $k ] ) ) {
				$grouped[ $k ] = $l + array( 'n' => 0 );
			}
			++$grouped[ $k ]['n'];
		}
		$rows = array();
		foreach ( $grouped as $l ) {
			$rows[] = array(
				'l' => $l,
				'r' => $this->rate_link( $l ),
			);
		}
		$rows   = self::by_sev( $rows, fn( $x ) => $x['r'] );
		$status = self::worst( array_column( $rows, 'r' ) );
		if ( ! $rows ) {
			return array(
				'status' => $status,
				'html'   => '<p class="sp-note">' . esc_html__( 'No links.', 'siteproof' ) . '</p>',
			);
		}
		$html = '<div class="sp-scroll"><table class="sp-table sp-linktable"><thead><tr><th scope="col"></th><th scope="col">' . esc_html__( 'Linked', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Target', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Block', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Check', 'siteproof' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $x ) {
			$l = $x['l'];
			$r = $x['r'];
			if ( ! empty( $l['image'] ) ) {
				$label = '<span class="sp-note">' . esc_html__( 'Image:', 'siteproof' ) . '</span> ' . self::e( $l['image']['file'] ) . '<div class="sp-note">alt: ' . ( ! empty( $l['image']['alt'] ) ? self::e( $l['image']['alt'] ) : '<span class="sp-bad">' . esc_html__( 'not set', 'siteproof' ) . '</span>' ) . '</div>';
			} elseif ( '' !== (string) $l['text'] ) {
				$label = self::e( $l['text'] );
			} elseif ( ! empty( $l['ariaLabel'] ) ) {
				$label = '<span class="sp-note">aria-label:</span> ' . self::e( $l['ariaLabel'] );
			} else {
				$label = '<span class="sp-bad">' . esc_html__( 'no link text', 'siteproof' ) . '</span>';
			}
			$target = str_starts_with( (string) $l['raw'], '#' ) ? $l['raw'] : preg_replace( '#^https?://#', '', $l['href'] );
			$key    = 'link:' . ( $p['id'] ?? 'tpl' ) . ':' . $l['href'];
			$html  .= sprintf(
				'<tr%s><td>%s</td><td>%s%s</td><td class="sp-file"><a href="%s" target="_blank" rel="noopener">%s</a></td><td class="sp-block">%s</td><td>%s%s</td></tr>',
				self::dl( $r ),
				self::dot( $r['level'], $r['blink'], ! $r['internal'], ( $r['internal'] ? __( 'internal', 'siteproof' ) : __( 'external', 'siteproof' ) ) . ': ' . self::label( $r['level'] ) ),
				$label,
				$l['n'] > 1 ? ' <span class="sp-note">(' . (int) $l['n'] . '×)</span>' : '',
				esc_url( $l['href'] ),
				self::e( $target ),
				self::e( $l['block'] ),
				'green' === $r['level'] ? '<span class="sp-note">ok</span>' : self::e( $r['note'] ),
				in_array( $r['level'], array( 'red', 'yellow' ), true ) ? $this->new_badge( $key ) : ''
			);
		}
		$html .= '</tbody></table></div><p class="sp-note">' . esc_html__( 'Circle = internal/anchor, square = external. Green reachable, yellow redirect, red broken, grey not automatically checkable.', 'siteproof' ) . '</p>';
		return compact( 'status', 'html' );
	}

	/**
	 * Accessibility section (axe-core).
	 *
	 * @param array      $p          Page.
	 * @param array|null $violations Violations (default: page-specific ones).
	 * @return array
	 */
	private function a11y_section( array $p, $violations = null ) {
		if ( null === $violations && ! is_array( $p['axe'] ?? null ) ) {
			return array(
				'status' => array( 'level' => 'grey' ),
				'html'   => '<p class="sp-note">' . esc_html__( 'Not checked (browser measurement was not part of this run).', 'siteproof' ) . '</p>',
			);
		}
		$lv         = fn( $v ) => in_array( $v['impact'], array( 'critical', 'serious' ), true ) ? 'red' : 'yellow';
		$st         = fn( $v ) => array(
			'level' => $lv( $v ),
			'blink' => 'critical' === $v['impact'],
		);
		$violations = $violations ?? array_values( array_filter( $p['axe'], fn( $v ) => ! isset( $this->global_axe[ $v['id'] ] ) ) );
		$violations = self::by_sev( $violations, $st );
		$status     = self::worst( array_map( $st, $violations ) );
		$here       = ! empty( $p['id'] ) ? count( array_filter( $p['axe'] ?? array(), fn( $v ) => isset( $this->global_axe[ $v['id'] ] ) ) ) : 0;
		$html       = '';
		if ( $violations ) {
			$html .= '<table class="sp-table"><thead><tr><th scope="col"></th><th scope="col">' . esc_html__( 'Rule', 'siteproof' ) . '</th><th scope="col">WCAG</th><th scope="col">' . esc_html__( 'Elements', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Example', 'siteproof' ) . '</th></tr></thead><tbody>';
			foreach ( $violations as $v ) {
				$n     = $v['nodes'][0] ?? array();
				$html .= sprintf(
					'<tr%s><td>%s</td><td><a href="%s" target="_blank" rel="noopener">%s</a> <span class="sp-note">%s</span>%s</td><td>%s</td><td>%d</td><td><code>%s</code>%s</td></tr>',
					self::dl( $st( $v ) ),
					self::dot( $lv( $v ), 'critical' === $v['impact'], false, $v['impact'] ),
					esc_url( $v['helpUrl'] ?? '' ),
					self::e( $v['help'] ?? $v['id'] ),
					self::e( $v['id'] ),
					$this->new_badge( 'a11y:' . ( $p['id'] ?? 'global' ) . ':' . $v['id'] ),
					self::e( ! empty( $v['tags'] ) ? implode( ', ', (array) $v['tags'] ) : 'Best Practice' ),
					(int) ( $v['count'] ?? 0 ),
					self::e( $n['target'] ?? '' ),
					! empty( $n['summary'] ) ? '<div class="sp-note">' . self::e( $n['summary'] ) . '</div>' : ''
				);
			}
			$html .= '</tbody></table>';
		} else {
			$html .= '<p class="sp-note">' . esc_html__( 'No page-specific violations (automatically checkable).', 'siteproof' ) . '</p>';
		}
		if ( $here ) {
			/* translators: %d: number of rules */
			$html .= '<p class="sp-note">' . sprintf( esc_html( _n( 'Plus %d rule that occurs on almost every page - see', 'Plus %d rules that occur on almost every page - see', $here, 'siteproof' ) ), $here ) . ' <a href="#sp-global">' . esc_html__( 'Global', 'siteproof' ) . '</a>.</p>';
		}
		$html .= '<p class="sp-note">' . esc_html__( 'axe-core finds about a third of all issues; check keyboard use, focus and screen reader flow manually.', 'siteproof' ) . '</p>';
		return compact( 'status', 'html' );
	}

	// ------------------------------------------------------------------ Assembly

	/**
	 * Section names.
	 *
	 * @return array
	 */
	public static function sections() {
		return array(
			'seo'     => __( 'SEO', 'siteproof' ),
			'speed'   => __( 'Load time', 'siteproof' ),
			'content' => __( 'Content', 'siteproof' ),
			'images'  => __( 'Images', 'siteproof' ),
			'links'   => __( 'Links', 'siteproof' ),
			'a11y'    => __( 'Accessibility', 'siteproof' ),
		);
	}

	/**
	 * One collapsible section in the tree.
	 *
	 * @param string $title   Title.
	 * @param array  $res     Section result.
	 * @param string $anchor  Anchor ID.
	 * @param bool   $carried Result carried over from the last audit (selective re-check).
	 * @return string
	 */
	private static function section_html( $title, array $res, $anchor, $carried = false ) {
		$res['status'] += array( 'blink' => false );
		return sprintf( '<li%s><details class="sp-sec"%s id="%s"><summary>%s <span class="sp-sec__title">%s</span>%s</summary><div class="sp-sec__body">%s</div></details></li>', self::dl( $res['status'] ), 'red' === $res['status']['level'] ? ' open' : '', esc_attr( $anchor ), self::dot( $res['status']['level'], $res['status']['blink'] ), self::e( $title ), $carried ? ' <span class="sp-note">' . esc_html__( 'carried over', 'siteproof' ) . '</span>' : '', $res['html'] );
	}

	/** @var string Rendered HTML */
	private $html = '';
	/** @var array Assessment items (for the summary) */
	private $assessment = array();

	/**
	 * Render the report body.
	 *
	 * @return string
	 */
	public function render() {
		if ( '' !== $this->html ) {
			return $this->html;
		}
		$sections = self::sections();
		$results  = array();
		foreach ( $this->pages as $p ) {
			$results[] = array(
				'p' => $p,
				'r' => array(
					'seo'     => $this->seo_section( $p ),
					'speed'   => $this->speed_section( $p ),
					'content' => $this->content_section( $p ),
					'images'  => $this->images_section( $p ),
					'links'   => $this->links_section( $p ),
					'a11y'    => $this->a11y_section( $p ),
				),
			);
		}
		foreach ( $results as $x ) {
			$this->levels[ $x['p']['id'] ] = array_map( fn( $res ) => $res['status']['level'], $x['r'] );
		}
		$score = fn( $r ) => array_sum( array_map( fn( $x ) => array( 0, 1, 100, 10000, 1000000 )[ self::sev( $x['status'] + array( 'blink' => false ) ) ], $r ) );
		usort( $results, fn( $a, $b ) => $score( $b['r'] ) <=> $score( $a['r'] ) );

		// Pages.
		$pages_html = '';
		foreach ( $results as $x ) {
			$p       = $x['p'];
			$overall = self::worst( array_column( $x['r'], 'status' ) );
			$meta    = sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a> · <a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $p['url'] ), esc_html__( 'View page', 'siteproof' ), esc_url( self::edit( $p['id'] ) ), esc_html__( 'Edit page', 'siteproof' ) );
			if ( $p['status'] && 200 !== (int) $p['status'] ) {
				$meta .= ' · <span class="sp-bad">HTTP ' . (int) $p['status'] . '</span>';
			}
			$tree = '';
			foreach ( $sections as $k => $t ) {
				$tree .= self::section_html( $t, $x['r'][ $k ], "sp-{$k}-{$p['id']}", in_array( $k, $p['carried'], true ) );
			}
			$pages_html .= sprintf( '<li class="sp-pageitem"%s><details class="sp-page" id="sp-p-%d"><summary>%s <span class="sp-page__title">%s</span> <span class="sp-note">%s</span></summary><ul class="sp-tree"><li class="sp-meta-row">%s</li>%s</ul></details></li>', self::dl( $overall ), $p['id'], self::dot( $overall['level'], $overall['blink'] ), self::e( $p['title'] ), self::e( $p['path'] ), $meta, $tree );
		}

		// Global.
		$tpl_page = array(
			'id'    => null,
			'path'  => $this->tpl['path'],
			'media' => array(),
		);
		$g_images = $this->images_section( $tpl_page, $this->tpl['images'], 'sp-img-global' );
		$g_links  = $this->links_section( $tpl_page, $this->tpl['links'] );
		$g_axe    = array();
		foreach ( array_keys( $this->global_axe ) as $id ) {
			foreach ( $this->pages as $p ) {
				foreach ( (array) $p['axe'] as $v ) {
					if ( $v['id'] === $id ) {
						$g_axe[] = $v + array( 'pages' => $this->axe_count[ $id ] );
						continue 3;
					}
				}
			}
		}
		$g_a11y = $this->a11y_section( array( 'id' => null ), $g_axe );
		$gh     = '<p class="sp-note">' . esc_html__( 'Headings in header, footer and popups - they appear on every page in addition to the content structure.', 'siteproof' ) . '</p><ul class="sp-headings">';
		foreach ( $this->tpl['headings'] as $h ) {
			$gh .= sprintf( '<li class="sp-h" style="--lvl:%1$d"><span class="sp-h__tag">H%1$d</span> %2$s <span class="sp-block">(%3$s)</span></li>', $h['level'], self::e( $h['text'] ), self::e( $h['region'] . ( ! in_array( $h['block'], array( 'header', 'footer', 'template' ), true ) ? ', ' . $h['block'] : '' ) ) );
		}
		$gh         .= '</ul>';
		$g_status    = self::worst( array( $g_images['status'], $g_links['status'], $g_a11y['status'] ) );
		$global_html = sprintf(
			'<details class="sp-page sp-global" id="sp-global"><summary>%s <span class="sp-page__title">%s</span></summary><ul class="sp-tree">%s%s%s%s</ul></details>',
			self::dot( $g_status['level'], $g_status['blink'] ),
			esc_html__( 'Global: header, footer, site-wide', 'siteproof' ),
			self::section_html( __( 'Template headings', 'siteproof' ), array( 'status' => array( 'level' => 'green' ), 'html' => $gh ), 'sp-gh' ),
			self::section_html( __( 'Template images', 'siteproof' ), $g_images, 'sp-gi' ),
			self::section_html( __( 'Template links', 'siteproof' ), $g_links, 'sp-gl' ),
			/* translators: %d: number of rules */
			self::section_html( sprintf( __( 'Accessibility on (almost) every page (%d rules)', 'siteproof' ), count( $g_axe ) ), $g_a11y, 'sp-ga' )
		);

		// Overview.
		$ov = '<div class="sp-scroll"><table class="sp-table sp-overview"><thead><tr><th scope="col">' . esc_html__( 'Page', 'siteproof' ) . '</th>';
		foreach ( $sections as $t ) {
			$ov .= '<th scope="col">' . self::e( $t ) . '</th>';
		}
		$ov .= '</tr></thead><tbody>';
		foreach ( $results as $x ) {
			$p   = $x['p'];
			$ov .= sprintf( '<tr%s><th scope="row"><a href="#sp-p-%d" class="sp-jump">%s</a> <span class="sp-note">%s</span></th>', self::dl( self::worst( array_column( $x['r'], 'status' ) ) ), $p['id'], self::e( $p['title'] ), self::e( $p['path'] ) );
			foreach ( $sections as $k => $t ) {
				$s   = $x['r'][ $k ]['status'] + array( 'blink' => false );
				$ov .= sprintf( '<td><a href="#sp-%s-%d" class="sp-jump">%s</a></td>', $k, $p['id'], self::dot( $s['level'], $s['blink'], false, $t . ': ' . self::label( $s['level'] ) ) );
			}
			$ov .= '</tr>';
		}
		$ov .= '</tbody></table></div>';

		$assessment = $this->assessment( $g_axe );

		foreach ( $results as $x ) {
			foreach ( $x['r'] as $res ) {
				$l = 'grey' === $res['status']['level'] ? 'green' : $res['status']['level'];
				++$this->counts[ $l ];
			}
		}

		// Comparison.
		$compare = $this->run['summary']['compare'] ?? null;
		if ( $compare ) {
			$new   = count( array_diff_key( $this->keys, $this->prev_keys ) );
			// Only findings of pages and steps checked in this run count as fixed (partial runs).
			$ids   = array_flip( array_map( 'strval', array_column( $this->pages, 'id' ) ) );
			$set   = $this->run['settings'] ?? array();
			$fixed = array_values(
				array_filter(
					array_keys( array_diff_key( $this->prev_keys, $this->keys ) ),
					function ( $k ) use ( $ids, $set ) {
						$parts = explode( ':', $k, 4 );
						$scope = $parts[1] ?? '';
						if ( ( 'speed' === $parts[0] && empty( $set['browser'] ) && empty( $set['psi'] ) )
							|| ( 'a11y' === $parts[0] && empty( $set['browser'] ) )
							|| ( 'content' === $parts[0] && 'proof' === ( $parts[2] ?? '' ) && empty( $set['ai'] ) ) ) {
							return false;
						}
						return isset( $ids[ $scope ] ) || in_array( $scope, array( 'tpl', 'global' ), true );
					}
				)
			);
			/* translators: 1: date, 2: new findings, 3: fixed findings */
			$diff = '<p class="sp-diff">' . sprintf( esc_html__( 'Compared with the audit of %1$s: %2$s new findings (marked "new"), %3$s fixed.', 'siteproof' ), esc_html( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $compare['date'] . ' UTC' ) ) ), '<strong>' . (int) $new . '</strong>', '<strong>' . count( $fixed ) . '</strong>' ) . '</p>';
			if ( $fixed ) {
				/* translators: %d: number */
				$diff .= '<details class="sp-fixed"><summary>' . esc_html( sprintf( __( 'Fixed findings (%d)', 'siteproof' ), count( $fixed ) ) ) . '</summary><pre>' . self::e( implode( "\n", $fixed ) ) . '</pre></details>';
			}
		} else {
			$diff = '<p class="sp-note">' . esc_html__( 'First run - the next audit will mark new and fixed findings.', 'siteproof' ) . '</p>';
		}

		$created = strtotime( $this->run['created'] . ' UTC' );
		$ai      = $this->ai_info();
		$failed  = '';
		if ( $this->failed ) {
			/* translators: 1: number, 2: paths */
			$failed = ' ' . esc_html( sprintf( __( '%1$d not reachable (%2$s).', 'siteproof' ), count( $this->failed ), implode( ', ', array_map( fn( $f ) => $f['path'] . ': ' . $f['error'], $this->failed ) ) ) );
		}
		$intro = sprintf(
			/* translators: 1: number of pages, 2: red, 3: yellow, 4: green */
			esc_html__( '%1$d pages checked. Status: %2$d critical, %3$d to check, %4$d OK (per page and section).', 'siteproof' ),
			count( $this->pages ),
			$this->counts['red'],
			$this->counts['yellow'],
			$this->counts['green']
		) . $failed . ( $ai ? ' ' . esc_html( $ai ) : '' ) . $this->only_info();

		$bar = sprintf(
			'<div class="sp-bar"><div class="sp-filter" role="group" aria-label="%s"><button type="button" data-sp-filter="red" aria-pressed="false">%s %s</button><button type="button" data-sp-filter="ry" aria-pressed="false">%s%s %s</button><button type="button" data-sp-filter="all" aria-pressed="false">%s</button></div><div class="sp-legend"><span>%s %s</span><span>%s %s</span><span>%s %s</span><span>%s %s</span><span>%s %s</span><span>%s %s</span></div><div class="sp-toggles"><button type="button" class="button button-small" data-sp-toggle="open">%s</button><button type="button" class="button button-small" data-sp-toggle="close">%s</button></div></div>',
			esc_attr__( 'Filter findings', 'siteproof' ),
			self::dot( 'red' ),
			esc_html__( 'Red only', 'siteproof' ),
			self::dot( 'red' ),
			self::dot( 'yellow' ),
			esc_html__( 'Red and yellow', 'siteproof' ),
			esc_html__( 'All', 'siteproof' ),
			self::dot( 'red', true ),
			esc_html__( 'very critical', 'siteproof' ),
			self::dot( 'red' ),
			esc_html__( 'critical', 'siteproof' ),
			self::dot( 'yellow' ),
			esc_html__( 'check', 'siteproof' ),
			self::dot( 'green' ),
			esc_html__( 'OK', 'siteproof' ),
			self::dot( 'grey' ),
			esc_html__( 'not checkable', 'siteproof' ),
			self::dot( 'green', false, true, __( 'external', 'siteproof' ) ),
			esc_html__( 'external link', 'siteproof' ),
			esc_html__( 'Expand all', 'siteproof' ),
			esc_html__( 'Collapse all', 'siteproof' )
		);

		$this->html = sprintf(
			'<div class="sp-report" data-run="%d"><h1>%s <span class="sp-note">%s</span></h1><p class="sp-intro">%s</p>%s%s<h2>%s</h2>%s<h2>%s</h2>%s<h2>%s</h2>%s<h2>%s</h2><ul class="sp-pages">%s</ul></div>',
			(int) $this->run['id'],
			/* translators: %s: site name */
			esc_html( sprintf( __( 'Content audit %s', 'siteproof' ), $this->site['name'] ) ),
			esc_html( wp_date( get_option( 'date_format' ) . ' H:i', $created ) ),
			$intro,
			$diff,
			$bar,
			esc_html__( 'Overall assessment', 'siteproof' ),
			$assessment,
			esc_html__( 'Overview', 'siteproof' ),
			$ov,
			esc_html__( 'Global', 'siteproof' ),
			$global_html,
			esc_html__( 'Pages', 'siteproof' ),
			$pages_html
		);
		return $this->html;
	}

	/**
	 * Note on a selective re-check: which levels were checked again per section.
	 *
	 * @return string
	 */
	private function only_info() {
		$only = $this->run['settings']['only'] ?? array();
		if ( ! $only ) {
			return '';
		}
		$sections = self::sections();
		$parts    = array();
		foreach ( $only as $k => $levels ) {
			$parts[] = ( $sections[ $k ] ?? $k ) . ': ' . implode( ', ', array_map( array( __CLASS__, 'label' ), (array) $levels ) );
		}
		$date = wp_date( get_option( 'date_format' ) . ' H:i', strtotime( ( $this->run['settings']['from']['date'] ?? $this->run['created'] ) . ' UTC' ) );
		/* translators: 1: date of the last audit, 2: list of sections with their levels */
		return ' ' . esc_html( sprintf( __( 'Selective re-check: only pages that reached the following levels in the audit of %1$s were checked again (%2$s). All other results are carried over.', 'siteproof' ), $date, implode( '; ', $parts ) ) );
	}

	/**
	 * Note on who proofread.
	 *
	 * @return string
	 */
	private function ai_info() {
		$used = array();
		$cost = 0.0;
		$has  = false;
		foreach ( $this->pages as $p ) {
			if ( ! empty( $p['review']['model'] ) ) {
				$used[ ( Siteproof_Settings::providers()[ $p['review']['provider'] ] ?? $p['review']['provider'] ) . ' ' . $p['review']['model'] ] = true;
				if ( null !== ( $p['review']['cost'] ?? null ) ) {
					$cost += $p['review']['cost'];
					$has   = true;
				}
			}
		}
		if ( ! $used ) {
			return '';
		}
		/* translators: 1: provider and model, 2: cost */
		return sprintf( __( 'Proofread by %1$s%2$s.', 'siteproof' ), implode( ', ', array_keys( $used ) ), $has ? sprintf( ' (~%s USD)', number_format_i18n( $cost, 2 ) ) : '' );
	}

	/**
	 * Site-wide patterns.
	 *
	 * @param array $g_axe Global axe rules.
	 * @return string
	 */
	private function assessment( array $g_axe ) {
		$n     = count( $this->pages );
		$share = fn( $k ) => $k / max( $n, 1 );
		$items = array();
		$add   = function ( $level, $blink, $title, $detail = '' ) use ( &$items ) {
			$items[] = compact( 'level', 'blink', 'title', 'detail' );
		};
		$list  = function ( array $ps, $max = 8 ) {
			$links = array_map( fn( $p ) => sprintf( '<a href="#sp-p-%d" class="sp-jump">%s</a>', $p['id'], self::e( $p['title'] ) ), array_slice( $ps, 0, $max ) );
			/* translators: %d: number */
			return implode( ', ', $links ) . ( count( $ps ) > $max ? ' ' . esc_html( sprintf( __( 'and %d more', 'siteproof' ), count( $ps ) - $max ) ) : '' );
		};
		$median = function ( array $a ) {
			$v = array_values( array_filter( $a, fn( $x ) => null !== $x ) );
			sort( $v );
			return $v ? $v[ (int) floor( count( $v ) / 2 ) ] : null;
		};

		// Headings.
		$no_h1 = array_values( array_filter( $this->pages, fn( $p ) => ! array_filter( $p['headings'], fn( $h ) => 1 === $h['level'] ) ) );
		if ( $no_h1 ) {
			$many = $share( count( $no_h1 ) ) >= 0.5;
			/* translators: 1: pages without H1, 2: all pages */
			$add( $many ? 'red' : 'yellow', $many, sprintf( __( 'The H1 is missing on %1$d of %2$d pages', 'siteproof' ), count( $no_h1 ), $n ), ( $many ? esc_html__( 'Probably a template/block issue (the hero block does not output the main heading as H1).', 'siteproof' ) . ' ' : '' ) . $list( $no_h1 ) );
		}
		$skips = array_values(
			array_filter(
				$this->pages,
				function ( $p ) {
					foreach ( $p['headings'] as $i => $h ) {
						if ( $i && $h['level'] > $p['headings'][ $i - 1 ]['level'] + 1 ) {
							return true;
						}
					}
					return false;
				}
			)
		);
		if ( count( $skips ) >= max( 3, $n * 0.3 ) ) {
			/* translators: %d: number of pages */
			$add( 'yellow', false, sprintf( __( 'Heading jumps on %d pages', 'siteproof' ), count( $skips ) ), $list( $skips ) );
		}

		// Indexing.
		$noindex = array_values( array_filter( $this->pages, fn( $p ) => (bool) preg_match( '/noindex/i', (string) ( $p['seo']['robots'] ?? '' ) ) ) );
		if ( $noindex ) {
			/* translators: %d: number of pages */
			$add( 'red', $share( count( $noindex ) ) >= 0.5, sprintf( _n( '%d page set to noindex', '%d pages set to noindex', count( $noindex ), 'siteproof' ), count( $noindex ) ), $list( $noindex, 12 ) . ' - ' . esc_html__( 'check whether intended.', 'siteproof' ) );
		}
		if ( empty( $this->site['public'] ) ) {
			$add( 'red', true, __( 'Search engine visibility is switched off (blog_public = 0)', 'siteproof' ), esc_html__( 'Settings > Reading. Normal on local and staging sites, critical on the live site.', 'siteproof' ) );
		}

		// SEO fields.
		$auto = array_values( array_filter( $this->pages, fn( $p ) => ! empty( $p['seoStored']['plugin'] ) && empty( $p['seoStored']['description'] ) ) );
		if ( count( $auto ) >= max( 3, $n * 0.3 ) ) {
			/* translators: %d: number of pages */
			$add( 'yellow', false, sprintf( __( '%d pages without a custom meta description', 'siteproof' ), count( $auto ) ), esc_html__( 'Generated automatically from the content.', 'siteproof' ) . ' ' . $list( $auto ) );
		}
		$def    = $this->default_og();
		$og_def = array_filter( $this->pages, fn( $p ) => empty( $p['seo']['ogImage'] ) || ( $def && $p['seo']['ogImage'] === $def ) );
		if ( count( $og_def ) >= max( 3, $n * 0.3 ) ) {
			/* translators: %d: number of pages */
			$add( 'yellow', false, sprintf( __( '%d pages only share the default preview image', 'siteproof' ), count( $og_def ) ), esc_html__( 'All pages look the same when shared on social networks. Set a featured image.', 'siteproof' ) );
		}

		// Recurring images.
		$img_map = array();
		foreach ( $this->pages as $p ) {
			foreach ( $p['images'] as $img ) {
				$k = self::file( $img['src'] );
				if ( ! isset( $img_map[ $k ] ) ) {
					$img_map[ $k ] = array(
						'img'   => $img,
						'pages' => array(),
					);
				}
				$img_map[ $k ]['pages'][ $p['id'] ] = true;
			}
		}
		foreach ( $this->tpl['images'] as $img ) {
			$img_map[ self::file( $img['src'] ) ] = array(
				'img'      => $img,
				'pages'    => array_fill_keys( array_column( $this->pages, 'id' ), true ),
				'template' => true,
			);
		}
		foreach ( $img_map as $name => $v ) {
			if ( count( $v['pages'] ) < max( 3, $n * 0.5 ) ) {
				continue;
			}
			$r = $this->rate_image( $v['img'] );
			if ( in_array( $r['level'], array( 'red', 'yellow' ), true ) ) {
				/* translators: 1: file, 2: pages, 3: all pages */
				$title = sprintf( __( '%1$s loads on %2$d of %3$d pages', 'siteproof' ), $name, count( $v['pages'] ), $n ) . ( $r['bytes'] ? ' (' . self::kb( $r['bytes'] ) . ')' : '' );
				$add( $r['level'], $r['blink'], $title, self::e( implode( '; ', $r['reasons'] ) ) . ( ! empty( $v['template'] ) ? ' - ' . esc_html__( 'in header/footer', 'siteproof' ) : ( ! empty( $v['img']['background'] ) ? ' - ' . esc_html__( 'CSS background', 'siteproof' ) : '' ) ) . '. ' . esc_html__( 'Shrinking it once helps every page.', 'siteproof' ) );
			}
		}
		$all    = array_filter( $img_map, fn( $v ) => empty( $v['img']['background'] ) );
		$no_alt = array_filter(
			$all,
			function ( $v ) {
				$att = $v['img']['attachmentId'] ?? ( $v['img']['file']['id'] ?? null );
				if ( $att && isset( $this->fixes['alt'][ $att ] ) ) {
					return false;
				}
				return null === ( $v['img']['alt'] ?? null ) || '' === trim( (string) $v['img']['alt'] );
			}
		);
		if ( $no_alt ) {
			/* translators: 1: images without alt, 2: all images */
			$add( count( $no_alt ) / max( count( $all ), 1 ) >= 0.3 ? 'red' : 'yellow', false, sprintf( __( '%1$d of %2$d images without alt text', 'siteproof' ), count( $no_alt ), count( $all ) ), esc_html__( 'Details and inline editing per page in the Images section.', 'siteproof' ) );
		}

		// Load time.
		$sp = array_values( array_filter( array_column( $this->pages, 'speed' ) ) );
		if ( $sp ) {
			$lcp_red = count( array_filter( $sp, fn( $x ) => ( $x['lcp'] ?? 0 ) > $this->th['lcp_yellow'] ) );
			$m_lcp   = $median( array_map( fn( $x ) => $x['lcp'] ?? null, $sp ) );
			$m_tot   = $median( array_map( fn( $x ) => $x['bytes']['total'] ?? null, $sp ) );
			/* translators: 1: median LCP, 2: median page weight */
			$det = sprintf( __( 'Median LCP %1$s, median page weight %2$s.', 'siteproof' ), self::sec( $m_lcp ), self::mb( $m_tot ) );
			if ( $lcp_red / count( $sp ) >= 0.5 ) {
				/* translators: 1: pages, 2: measured pages */
				$add( 'red', $lcp_red / count( $sp ) >= 0.8, sprintf( __( 'Mobile load time critical on %1$d of %2$d pages', 'siteproof' ), $lcp_red, count( $sp ) ), self::e( $det ) );
			} elseif ( $m_tot > $this->th['total_yellow'] * 1048576 ) {
				/* translators: %s: size */
				$add( 'yellow', false, sprintf( __( 'Pages weigh %s on average (mobile)', 'siteproof' ), self::mb( $m_tot ) ), self::e( $det ) );
			}
			$m_js   = $median( array_map( fn( $x ) => $x['bytes']['script'] ?? null, $sp ) );
			$m_font = $median( array_map( fn( $x ) => $x['bytes']['font'] ?? null, $sp ) );
			$m_css  = $median( array_map( fn( $x ) => $x['bytes']['stylesheet'] ?? null, $sp ) );
			if ( $m_js > 300 * 1024 ) {
				/* translators: %s: size */
				$add( $m_js > 600 * 1024 ? 'red' : 'yellow', false, sprintf( __( 'JavaScript %s on almost every page', 'siteproof' ), self::kb( $m_js ) ), esc_html__( 'Theme/plugins: load libraries only on pages that need them.', 'siteproof' ) );
			}
			if ( $m_font > 150 * 1024 ) {
				/* translators: %s: size */
				$add( $m_font > 300 * 1024 ? 'red' : 'yellow', false, sprintf( __( 'Fonts %s on almost every page', 'siteproof' ), self::kb( $m_font ) ), esc_html__( 'Reduce font styles, use woff2 and subsetting.', 'siteproof' ) );
			}
			if ( $m_css > 100 * 1024 ) {
				/* translators: %s: size */
				$add( $m_css > 200 * 1024 ? 'red' : 'yellow', false, sprintf( __( 'CSS %s on almost every page', 'siteproof' ), self::kb( $m_css ) ) );
			}
		}

		// Links.
		$broken = array();
		foreach ( $this->pages as $p ) {
			foreach ( $p['links'] as $l ) {
				$r = $this->links[ $l['href'] ] ?? null;
				if ( $r && in_array( $r['status'], array( 'broken', 'anchor-missing' ), true ) ) {
					$broken[ $l['href'] ]['r']                = $r;
					$broken[ $l['href'] ]['pages'][ $p['id'] ] = true;
				}
			}
		}
		$tpl_broken = array_filter( $this->tpl['links'], fn( $l ) => in_array( $this->links[ $l['href'] ]['status'] ?? '', array( 'broken', 'anchor-missing' ), true ) );
		if ( $tpl_broken ) {
			/* translators: %d: number of links */
			$add( 'red', true, sprintf( _n( '%d broken link in header/footer - on every page', '%d broken links in header/footer - on every page', count( $tpl_broken ), 'siteproof' ), count( $tpl_broken ) ), self::e( implode( ', ', array_unique( array_column( $tpl_broken, 'href' ) ) ) ) );
		}
		if ( $broken ) {
			$internal = array_filter( $broken, fn( $v ) => ! empty( $v['r']['internal'] ) );
			$on_pages = array();
			foreach ( $broken as $v ) {
				$on_pages += $v['pages'];
			}
			$multi = array();
			foreach ( $broken as $u => $v ) {
				if ( count( $v['pages'] ) > 1 ) {
					/* translators: 1: URL, 2: pages */
					$multi[] = self::e( sprintf( __( '%1$s (%2$d pages)', 'siteproof' ), $u, count( $v['pages'] ) ) );
				}
			}
			/* translators: 1: links, 2: internal, 3: pages */
			$add( 'red', (bool) $internal, sprintf( __( '%1$d broken links (%2$d internal) on %3$d pages', 'siteproof' ), count( $broken ), count( $internal ), count( $on_pages ) ), $multi ? implode( ', ', $multi ) : esc_html__( 'Details per page in the Links section.', 'siteproof' ) );
		}

		// Accessibility (template rules).
		foreach ( $g_axe as $v ) {
			/* translators: 1: pages, 2: all pages, 3: rule */
			$add( in_array( $v['impact'], array( 'critical', 'serious' ), true ) ? 'red' : 'yellow', 'critical' === $v['impact'], sprintf( __( 'Accessibility on %1$d of %2$d pages: %3$s', 'siteproof' ), $v['pages'], $n, $v['help'] ), sprintf( /* translators: %d: elements */ esc_html__( '%d elements per page, e.g.', 'siteproof' ), (int) $v['count'] ) . ' <code>' . self::e( $v['nodes'][0]['target'] ?? '' ) . '</code> - ' . esc_html__( 'fix in the theme.', 'siteproof' ) );
		}

		// Proofreading.
		$all_proof = array();
		foreach ( $this->pages as $p ) {
			foreach ( $p['review']['proofreading'] ?? array() as $f ) {
				if ( ! isset( $f['applied'] ) ) {
					$all_proof[] = $f;
				}
			}
		}
		if ( $all_proof ) {
			$by_type = array();
			foreach ( $all_proof as $f ) {
				$by_type[ $f['type'] ] = ( $by_type[ $f['type'] ] ?? 0 ) + 1;
			}
			$placeholder = array_filter( $all_proof, fn( $f ) => (bool) preg_match( '/Placeholder|Platzhalter|Lorem|Hier kommt|Text zu/i', $f['error'] . ' ' . ( $f['note'] ?? '' ) . ' ' . $f['suggestion'] ) );
			$parts       = array();
			foreach ( $by_type as $t => $c ) {
				$parts[] = $c . ' ' . self::type_label( $t );
			}
			/* translators: %d: number of findings */
			$add( isset( $by_type['spelling'] ) ? 'red' : 'yellow', false, sprintf( __( '%d text findings from proofreading', 'siteproof' ), count( $all_proof ) ), self::e( implode( ', ', $parts ) ) );
			if ( $placeholder ) {
				/* translators: %d: number */
				$add( 'red', true, sprintf( _n( '%d placeholder or copy-paste text on the website', '%d placeholder or copy-paste texts on the website', count( $placeholder ), 'siteproof' ), count( $placeholder ) ), esc_html__( 'Replace before going live - see the Content section of the affected pages.', 'siteproof' ) );
			}
		}

		$items            = self::by_sev( $items, fn( $x ) => $x );
		$this->assessment = $items;
		if ( ! $items ) {
			return '<p class="sp-note">' . esc_html__( 'No site-wide patterns found.', 'siteproof' ) . '</p>';
		}
		$html = '<table class="sp-table sp-kv sp-assess"><tbody>';
		foreach ( $items as $x ) {
			$html .= sprintf( '<tr%s><th scope="row">%s %s</th><td>%s</td></tr>', self::dl( $x ), self::dot( $x['level'], $x['blink'] ), self::e( $x['title'] ), $x['detail'] );
		}
		return $html . '</tbody></table>';
	}

	/**
	 * Summary for the run list and the comparison.
	 *
	 * @return array
	 */
	public function summary() {
		$this->render();
		return array(
			'counts' => $this->counts,
			'levels' => $this->levels,
			'keys'   => array_keys( $this->keys ),
			'pages'  => count( $this->pages ),
			'failed' => count( $this->failed ),
			'top'    => array_map( fn( $x ) => array( $x['level'], $x['blink'], $x['title'] ), array_slice( $this->assessment, 0, 5 ) ),
			'ai'     => $this->ai_info(),
		);
	}
}

<?php
/**
 * Selective re-check: per category, only pages that reached the selected levels in the last
 * finished audit are checked again. All other results are carried over from that audit.
 *
 * A page keeps the previous data of its carried categories: steps that do not have to run
 * again are prefilled when the run is created, steps that run again get the carried fields
 * merged back in when their result is saved.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Carry-over helper.
 */
class Siteproof_Carry {

	const CATS   = array( 'seo', 'speed', 'content', 'images', 'links', 'a11y' );
	const LEVELS = array( 'red', 'yellow', 'green' );

	/** Step => run setting that enables it (the server step always runs). */
	const FLAG = array(
		'browser' => 'browser',
		'psi'     => 'psi',
		'review'  => 'ai',
	);

	/** Step => categories that need it. */
	const NEEDS = array(
		'server'  => array( 'seo', 'content', 'images', 'links' ),
		'browser' => array( 'speed', 'images', 'a11y' ),
		'psi'     => array( 'speed' ),
		'review'  => array( 'seo', 'content' ),
	);

	/** Step => category => fields (paths into the step data) that belong to the category. */
	const OWN = array(
		'server'  => array(
			'seo'     => array( array( 'seo' ), array( 'seoStored' ) ),
			'content' => array( array( 'headings' ), array( 'segments' ) ),
			'images'  => array( array( 'images' ), array( 'media' ) ),
			'links'   => array( array( 'links' ) ),
		),
		'browser' => array(
			'speed'  => array( array( 'mobile', 'timing' ) ),
			'images' => array( array( 'mobile', 'images' ), array( 'mobile', 'media' ) ),
			'a11y'   => array( array( 'desktop', 'axe' ) ),
		),
		'review'  => array(
			'seo'     => array( array( 'suggestions' ) ),
			'speed'   => array( array( 'speed' ) ),
			'content' => array( array( 'proofreading' ), array( 'discarded' ) ),
		),
	);

	/**
	 * Sanitise the selection of the start form: category => levels.
	 * A category without levels (or with all of them) is not restricted.
	 *
	 * @param mixed $only Raw selection.
	 * @return array
	 */
	public static function sanitize( $only ) {
		$out = array();
		foreach ( self::CATS as $cat ) {
			$levels = array_values( array_intersect( self::LEVELS, array_map( 'sanitize_key', (array) ( ( (array) $only )[ $cat ] ?? array() ) ) ) );
			if ( $levels && count( $levels ) < count( self::LEVELS ) ) {
				$out[ $cat ] = $levels;
			}
		}
		return $out;
	}

	/**
	 * The last finished audit with its level per page and category.
	 * Audits stored before this feature existed get their levels computed once.
	 *
	 * @return array|null { run, levels: post ID => category => level }
	 */
	public static function source() {
		$run = Siteproof_DB::last_done();
		if ( ! $run ) {
			return null;
		}
		if ( ! isset( $run['summary']['levels'] ) ) {
			$report                   = new Siteproof_Report( $run, Siteproof_DB::get_pages( $run['id'] ) );
			$run['summary']['levels'] = $report->summary()['levels'];
			Siteproof_DB::update_run( $run['id'], array( 'summary' => $run['summary'] ) );
		}
		return array(
			'run'    => $run,
			'levels' => (array) $run['summary']['levels'],
		);
	}

	/**
	 * Number of pages per category and level in the last audit (for the start form).
	 *
	 * @param array $levels post ID => category => level.
	 * @return array category => level => count
	 */
	public static function counts( array $levels ) {
		$out = array_fill_keys( self::CATS, array_fill_keys( self::LEVELS, 0 ) );
		foreach ( $levels as $page ) {
			foreach ( self::CATS as $cat ) {
				$level = $page[ $cat ] ?? 'grey';
				if ( isset( $out[ $cat ][ $level ] ) ) {
					++$out[ $cat ][ $level ];
				}
			}
		}
		return $out;
	}

	/**
	 * Decide per page which categories are carried over and prepare its steps.
	 *
	 * Checked in full: pages that were not part of the last audit or could not be loaded there.
	 * Checked again in any case: categories without a result ("not checkable") in the last audit.
	 *
	 * @param array $pages    Pages: post_id, url, title.
	 * @param array $only     Selection: category => levels.
	 * @param array $settings Run settings (browser, psi, ai).
	 * @param array $source   Result of source().
	 * @return array { pages, data: additions to the run data }
	 */
	public static function plan( array $pages, array $only, array $settings, array $source ) {
		$prev = array();
		foreach ( Siteproof_DB::get_pages( $source['run']['id'] ) as $row ) {
			$prev[ $row['post_id'] ] = $row;
		}
		$link_urls = array();
		foreach ( $pages as &$p ) {
			$row    = $prev[ $p['post_id'] ] ?? null;
			$levels = $source['levels'][ $p['post_id'] ] ?? null;
			if ( ! $row || ! $levels || empty( $row['server'] ) || ! empty( $row['server']['error'] ) ) {
				continue;
			}
			$carried = array();
			foreach ( $only as $cat => $want ) {
				$level = $levels[ $cat ] ?? 'grey';
				if ( 'grey' !== $level && ! in_array( $level, $want, true ) ) {
					$carried[] = $cat;
				}
			}
			if ( ! $carried ) {
				continue;
			}
			$fresh        = array_values( array_diff( self::CATS, $carried ) );
			$p['carried'] = $carried;
			$p['carry']   = array();
			foreach ( Siteproof_DB::STEPS as $step ) {
				$enabled = ! isset( self::FLAG[ $step ] ) || ! empty( $settings[ self::FLAG[ $step ] ] );
				if ( $enabled && array_intersect( self::NEEDS[ $step ], $fresh ) ) {
					// Runs again: the carried fields are merged back in when the step is saved.
					$p['carry'][ $step ] = self::transfer( $step, array(), $carried, (array) $row[ $step ] );
				} elseif ( null !== $row[ $step ] ) {
					$p[ $step ] = self::strip( $step, $row[ $step ], $fresh );
				} elseif ( $enabled ) {
					// Nothing to carry over, and nothing to do: mark the step as done.
					$p[ $step ] = array();
				}
			}
			if ( in_array( 'links', $carried, true ) ) {
				foreach ( $row['server']['links'] ?? array() as $l ) {
					$link_urls[ $l['href'] ] = true;
				}
			}
		}
		unset( $p );
		$data = array( 'linksCarry' => array_intersect_key( $source['run']['data']['links'] ?? array(), $link_urls ) );
		if ( ! empty( $source['run']['data']['fixes'] ) ) {
			$data['fixes'] = $source['run']['data']['fixes'];
		}
		return array(
			'pages' => $pages,
			'data'  => $data,
		);
	}

	/**
	 * Result of a step that ran again, with the fields of the carried categories restored.
	 *
	 * @param string $step    server|browser|review.
	 * @param array  $fresh   New result.
	 * @param array  $carried Carried categories of the page.
	 * @param array  $prev    Carried fields of the previous result.
	 * @return array
	 */
	public static function merge( $step, array $fresh, array $carried, array $prev ) {
		$data = self::transfer( $step, $fresh, $carried, $prev );
		// A failed review must not hide the proofreading that was carried over.
		if ( 'review' === $step && in_array( 'content', $carried, true ) && isset( $prev['proofreading'] ) ) {
			unset( $data['error'] );
		}
		return $data;
	}

	/**
	 * Link results that are carried over: URLs that only occur on pages whose links are not checked again.
	 *
	 * @param array $pages Page rows of the run (server step done).
	 * @param array $carry URL => result from the last audit.
	 * @return array
	 */
	public static function links( array $pages, array $carry ) {
		if ( ! $carry ) {
			return array();
		}
		$fresh = Siteproof_Links::unique_urls( array_filter( $pages, fn( $p ) => ! in_array( 'links', $p['carried'], true ) ) );
		return array_diff_key( array_intersect_key( $carry, array_flip( Siteproof_Links::unique_urls( $pages ) ) ), array_flip( $fresh ) );
	}

	/**
	 * Previous result of a step that does not run again, without the fields of the categories
	 * that are checked again (their old values would be outdated).
	 *
	 * @param string $step  Step.
	 * @param array  $data  Previous result.
	 * @param array  $fresh Categories that are checked again.
	 * @return array
	 */
	private static function strip( $step, array $data, array $fresh ) {
		if ( 'psi' === $step ) {
			return in_array( 'speed', $fresh, true ) ? array() : $data;
		}
		$data = self::transfer( $step, $data, $fresh, array() );
		if ( 'review' === $step ) {
			// Costs belong to the audit that paid for them.
			unset( $data['cost'], $data['usage'] );
			if ( in_array( 'content', $fresh, true ) ) {
				unset( $data['model'], $data['provider'], $data['fallback'] );
			}
		}
		return $data;
	}

	/**
	 * Set the fields of the given categories in $to to their values in $from (removed if missing there).
	 *
	 * @param string $step Step.
	 * @param array  $to   Target.
	 * @param array  $cats Categories.
	 * @param array  $from Source.
	 * @return array
	 */
	private static function transfer( $step, array $to, array $cats, array $from ) {
		foreach ( self::OWN[ $step ] ?? array() as $cat => $paths ) {
			if ( in_array( $cat, $cats, true ) ) {
				foreach ( $paths as $path ) {
					$to = self::move( $to, $from, $path );
				}
			}
		}
		return $to;
	}

	/**
	 * Copy one field (path) from $from to $to.
	 *
	 * @param array $to   Target.
	 * @param array $from Source.
	 * @param array $path Keys.
	 * @return array
	 */
	private static function move( array $to, array $from, array $path ) {
		$key = array_shift( $path );
		if ( $path ) {
			$sub = self::move( (array) ( $to[ $key ] ?? array() ), (array) ( $from[ $key ] ?? array() ), $path );
			if ( $sub ) {
				$to[ $key ] = $sub;
			} else {
				unset( $to[ $key ] );
			}
		} elseif ( array_key_exists( $key, $from ) ) {
			$to[ $key ] = $from[ $key ];
		} else {
			unset( $to[ $key ] );
		}
		return $to;
	}
}

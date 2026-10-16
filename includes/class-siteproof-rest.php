<?php
/**
 * REST API (namespace siteproof/v1). The admin page drives a run step by step so that
 * no single request runs into a timeout. Every route requires manage_options.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class Siteproof_REST {

	const NS = 'siteproof/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		$admin = array( __CLASS__, 'can_manage' );
		$id    = '/runs/(?P<id>\d+)';
		$routes = array(
			array( '/runs', 'POST', 'create_run' ),
			array( $id, 'GET', 'get_run' ),
			array( $id . '/server', 'POST', 'server' ),
			array( $id . '/links', 'POST', 'links' ),
			array( $id . '/browser', 'POST', 'browser' ),
			array( $id . '/psi', 'POST', 'psi' ),
			array( $id . '/estimate', 'GET', 'estimate' ),
			array( $id . '/review', 'POST', 'review' ),
			array( $id . '/finish', 'POST', 'finish' ),
			array( $id . '/status', 'POST', 'status' ),
			array( '/models', 'GET', 'models' ),
			array( '/test', 'POST', 'test' ),
			array( $id . '/fix/text', 'POST', 'fix_text' ),
		);
		foreach ( $routes as $r ) {
			register_rest_route(
				self::NS,
				$r[0],
				array(
					'methods'             => $r[1],
					'callback'            => array( __CLASS__, $r[2] ),
					'permission_callback' => $admin,
				)
			);
		}
		// Alt text: editors of the attachment may save it (the report itself is admin-only).
		register_rest_route(
			self::NS,
			$id . '/fix/alt',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'fix_alt' ),
				'permission_callback' => fn( WP_REST_Request $req ) => current_user_can( SITEPROOF_CAP ) && current_user_can( 'edit_post', (int) $req['attachment'] ),
			)
		);
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( SITEPROOF_CAP );
	}

	/**
	 * Load a run or fail.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	private static function run( WP_REST_Request $req ) {
		$run = Siteproof_DB::get_run( (int) $req['id'] );
		return $run ? $run : new WP_Error( 'siteproof_run', __( 'Audit run not found.', 'siteproof' ), array( 'status' => 404 ) );
	}

	/**
	 * Store a step result. In a selective re-check the fields of the carried categories
	 * are restored from the last audit first.
	 *
	 * @param array  $page Page row.
	 * @param string $step server|browser|review.
	 * @param array  $data Result.
	 */
	private static function save( array $page, $step, array $data ) {
		if ( isset( $page['carry'][ $step ] ) ) {
			$data = Siteproof_Carry::merge( $step, $data, $page['carried'], $page['carry'][ $step ] );
		}
		Siteproof_DB::save_step( (int) $page['run_id'], $page['post_id'], $step, $data );
	}

	/**
	 * Allow long steps (AI, PageSpeed, link batches).
	 */
	private static function long() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running audit step.
		}
	}

	// ------------------------------------------------------------------ Run

	/**
	 * Create a run.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_run( WP_REST_Request $req ) {
		$s     = Siteproof_Settings::get();
		$ids   = array_filter( array_map( 'absint', (array) $req['ids'] ) );
		$pages = Siteproof_Collector::page_list( (array) $s['types'], $ids );
		if ( ! $pages ) {
			return new WP_Error( 'siteproof_empty', __( 'No published pages found for the selected post types.', 'siteproof' ), array( 'status' => 400 ) );
		}
		$provider = sanitize_key( (string) $s['provider'] );
		$ai       = rest_sanitize_boolean( $req['ai'] ) && Siteproof_Settings::llm( $provider );
		$settings = array(
			'browser'    => rest_sanitize_boolean( $req['browser'] ),
			'psi'        => rest_sanitize_boolean( $req['psi'] ) && '' !== Siteproof_Settings::key( 'pagespeed' ),
			'ai'         => (bool) $ai,
			'provider'   => $provider,
			'fallback'   => sanitize_key( (string) $s['fallback'] ),
			'types'      => array_map( 'sanitize_key', (array) $s['types'] ),
			'ids'        => $ids,
			'thresholds' => $s['thresholds'],
			'liveUrl'    => $s['live_url'],
			'user'       => get_current_user_id(),
		);
		$data = array( 'site' => Siteproof_Collector::site_info() );
		// Selective re-check: carry over the results of pages outside the selected levels.
		$only   = Siteproof_Carry::sanitize( $req['only'] );
		$source = $only ? Siteproof_Carry::source() : null;
		if ( $source ) {
			self::long();
			$settings['only'] = $only;
			$settings['from'] = array(
				'id'   => $source['run']['id'],
				'date' => $source['run']['created'],
			);
			$plan             = Siteproof_Carry::plan( $pages, $only, $settings, $source );
			$pages            = $plan['pages'];
			$data            += $plan['data'];
		}
		$run_id = Siteproof_DB::create_run( $settings, $data, $pages );
		return self::state( $run_id );
	}

	/**
	 * Run state for the runner (pages with completed steps).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_run( WP_REST_Request $req ) {
		$run = self::run( $req );
		return is_wp_error( $run ) ? $run : self::state( $run['id'] );
	}

	/**
	 * State as response.
	 *
	 * @param int $run_id Run ID.
	 * @return WP_REST_Response
	 */
	private static function state( $run_id ) {
		$run   = Siteproof_DB::get_run( $run_id );
		$pages = array();
		foreach ( Siteproof_DB::get_pages( $run_id, false ) as $p ) {
			$pages[] = array(
				'post'    => $p['post_id'],
				'url'     => $p['url'],
				'title'   => $p['title'],
				'server'  => (bool) $p['has_server'],
				'browser' => (bool) $p['has_browser'],
				'psi'     => (bool) $p['has_psi'],
				'review'  => (bool) $p['has_review'],
			);
		}
		$urls = $run['data']['linkUrls'] ?? null;
		return rest_ensure_response(
			array(
				'id'         => $run['id'],
				'status'     => $run['status'],
				'settings'   => array_intersect_key( $run['settings'], array_flip( array( 'browser', 'psi', 'ai', 'provider' ) ) ),
				'pages'      => $pages,
				'links'      => null === $urls ? null : array(
					'total' => count( $urls ),
					'done'  => count( $run['data']['links'] ?? array() ),
				),
				'blockClass' => Siteproof_Collector::block_class_map(),
				'reportUrl'  => admin_url( 'admin.php?page=siteproof&run=' . $run['id'] ),
			)
		);
	}

	/**
	 * Set status (pause/resume).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function status( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$status = sanitize_key( (string) $req['status'] );
		if ( ! in_array( $status, array( 'running', 'paused' ), true ) || 'done' === $run['status'] ) {
			return new WP_Error( 'siteproof_status', __( 'Invalid status.', 'siteproof' ), array( 'status' => 400 ) );
		}
		Siteproof_DB::update_run( $run['id'], array( 'status' => $status ) );
		return rest_ensure_response( array( 'status' => $status ) );
	}

	// ------------------------------------------------------------------ Steps

	/**
	 * Server collector for a few pages.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function server( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::long();
		$map  = Siteproof_Collector::block_class_map();
		$done = array();
		foreach ( array_slice( array_map( 'absint', (array) $req['posts'] ), 0, 5 ) as $post_id ) {
			$page = Siteproof_DB::get_page( $run['id'], $post_id );
			if ( ! $page ) {
				continue;
			}
			$data = Siteproof_Collector::collect( $page['url'], $post_id, $map );
			self::save( $page, 'server', $data );
			$done[] = array(
				'post'  => $post_id,
				'error' => $data['error'] ?? null,
			);
		}
		return rest_ensure_response( array( 'done' => $done ) );
	}

	/**
	 * Check the next batch of links.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function links( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::long();
		$data = $run['data'];
		if ( ! isset( $data['linkUrls'] ) ) {
			$pages            = Siteproof_DB::get_pages( $run['id'] );
			$data['linkUrls'] = Siteproof_Links::unique_urls( $pages );
			$data['links']    = Siteproof_Carry::links( $pages, $data['linksCarry'] ?? array() );
			unset( $data['linksCarry'] );
		}
		$todo = array_slice( array_values( array_diff( $data['linkUrls'], array_keys( $data['links'] ) ) ), 0, Siteproof_Links::BATCH );
		if ( $todo ) {
			$checker       = new Siteproof_Links( Siteproof_Links::ids_by_path( Siteproof_DB::get_pages( $run['id'] ) ) );
			$data['links'] = array_merge( $data['links'], $checker->check( $todo ) );
		}
		Siteproof_DB::update_run( $run['id'], array( 'data' => $data ) );
		return rest_ensure_response(
			array(
				'total' => count( $data['linkUrls'] ),
				'done'  => count( $data['links'] ),
			)
		);
	}

	/**
	 * Store browser measurements (iframe, axe, image sizes, timing).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function browser( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$post_id = absint( $req['post'] );
		$page    = Siteproof_DB::get_page( $run['id'], $post_id );
		if ( ! $page ) {
			return new WP_Error( 'siteproof_page', __( 'Page not found.', 'siteproof' ), array( 'status' => 404 ) );
		}
		$data = self::clean( (array) $req['data'] );
		foreach ( $data['mobile']['images'] ?? array() as $i => $img ) {
			if ( ! empty( $img['src'] ) ) {
				$data['mobile']['images'][ $i ]['file'] = Siteproof_Collector::resolve_file( (string) $img['src'], isset( $img['attachmentId'] ) ? (int) $img['attachmentId'] : null );
			}
		}
		self::save( $page, 'browser', $data );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Recursively sanitise browser data (plain scalars and arrays, limited size).
	 *
	 * @param mixed $v     Value.
	 * @param int   $depth Depth.
	 * @return mixed
	 */
	private static function clean( $v, $depth = 0 ) {
		if ( is_array( $v ) ) {
			if ( $depth > 8 ) {
				return array();
			}
			$out = array();
			foreach ( array_slice( $v, 0, 2000, true ) as $k => $x ) {
				$out[ is_int( $k ) ? $k : preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $k ) ] = self::clean( $x, $depth + 1 );
			}
			return $out;
		}
		if ( is_bool( $v ) || is_int( $v ) || is_float( $v ) || null === $v ) {
			return $v;
		}
		return mb_substr( wp_strip_all_tags( (string) $v ), 0, 2000 );
	}

	/**
	 * PageSpeed Insights for one page.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function psi( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::long();
		$post_id = absint( $req['post'] );
		$page    = Siteproof_DB::get_page( $run['id'], $post_id );
		if ( ! $page ) {
			return new WP_Error( 'siteproof_page', __( 'Page not found.', 'siteproof' ), array( 'status' => 404 ) );
		}
		$target = Siteproof_PageSpeed::target( $page['url'] );
		if ( ! Siteproof_PageSpeed::public_url( $target ) ) {
			$data = array(
				'url'   => $target,
				'error' => __( 'Not publicly reachable - local measurement is used instead.', 'siteproof' ),
				'local' => true,
			);
		} else {
			$data = Siteproof_PageSpeed::run( $target, Siteproof_Settings::key( 'pagespeed' ) );
		}
		Siteproof_DB::save_step( $run['id'], $post_id, 'psi', $data );
		return rest_ensure_response(
			array(
				'post'  => $post_id,
				'error' => $data['error'] ?? null,
				'local' => ! empty( $data['local'] ),
			)
		);
	}

	/**
	 * Token and cost estimate before the AI step.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function estimate( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		// Only pages that still have to be reviewed (resumed runs, selective re-check).
		$todo = array_filter( Siteproof_DB::get_pages( $run['id'] ), fn( $p ) => null === $p['review'] );
		$est  = Siteproof_Review::estimate( $todo, $run['data']['site'] ?? array() );
		$out = array();
		foreach ( $est as $provider => $e ) {
			$out[] = $e + array(
				'provider' => $provider,
				'label'    => Siteproof_Settings::providers()[ $provider ],
				'selected' => $provider === $run['settings']['provider'],
			);
		}
		return rest_ensure_response( array( 'providers' => $out ) );
	}

	/**
	 * AI review of one page (with optional fallback provider).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function review( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		self::long();
		$post_id = absint( $req['post'] );
		$page    = Siteproof_DB::get_page( $run['id'], $post_id );
		if ( ! $page || empty( $page['server'] ) || ! empty( $page['server']['error'] ) ) {
			return new WP_Error( 'siteproof_page', __( 'Page has no collected content.', 'siteproof' ), array( 'status' => 400 ) );
		}
		$segments = $page['server']['segments'] ?? array();
		$context  = Siteproof_Review::context( $page, $run['data']['site'] ?? array(), Siteproof_Settings::wordlist() );
		$chain    = array_values( array_unique( array_filter( array( $run['settings']['provider'], $run['settings']['fallback'] ) ) ) );
		$result   = null;
		$errors   = array();
		foreach ( $chain as $provider ) {
			$llm = Siteproof_Settings::llm( $provider );
			if ( ! $llm ) {
				continue;
			}
			$res = $llm->review_page( $segments, $context );
			if ( empty( $res['error'] ) ) {
				$result = $res;
				break;
			}
			$errors[] = $provider . ': ' . $res['error'];
		}
		if ( ! $result ) {
			$data = array( 'error' => implode( ' | ', $errors ) ? implode( ' | ', $errors ) : __( 'No AI provider configured.', 'siteproof' ) );
		} else {
			$llm  = Siteproof_Settings::llm( $result['provider'] );
			$data = Siteproof_Review::validate( $result['data'], $segments ) + array(
				'provider' => $result['provider'],
				'model'    => $result['model'],
				'usage'    => $result['usage'],
				'cost'     => $llm ? $llm->estimate_cost( $result['usage'][0], $result['usage'][1] ) : null,
				'fallback' => $result['provider'] !== $chain[0],
			);
		}
		self::save( $page, 'review', $data );
		return rest_ensure_response(
			array(
				'post'      => $post_id,
				'error'     => $data['error'] ?? null,
				'findings'  => count( $data['proofreading'] ?? array() ),
				'discarded' => $data['discarded'] ?? 0,
			)
		);
	}

	/**
	 * Finish: compute ratings, keys and comparison, mark as done.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function finish( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$prev    = Siteproof_DB::previous_done( $run['id'] );
		$compare = $prev ? array(
			'date' => $prev['created'],
			'keys' => $prev['summary']['keys'] ?? array(),
		) : null;
		$run['summary'] = array( 'compare' => $compare );
		$report         = new Siteproof_Report( $run, Siteproof_DB::get_pages( $run['id'] ) );
		$report->render();
		Siteproof_DB::update_run(
			$run['id'],
			array(
				'status'  => 'done',
				'summary' => $report->summary() + array(
					'compare'  => $compare,
					'finished' => current_time( 'mysql', true ),
				),
			)
		);
		Siteproof_DB::clear_carry( $run['id'] );
		return rest_ensure_response( array( 'reportUrl' => admin_url( 'admin.php?page=siteproof&run=' . $run['id'] ) ) );
	}

	// ------------------------------------------------------------------ Settings helpers

	/**
	 * Model list of a provider.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function models( WP_REST_Request $req ) {
		$provider = sanitize_key( (string) $req['provider'] );
		$llm      = Siteproof_Settings::llm( $provider );
		if ( ! $llm ) {
			return new WP_Error( 'siteproof_key', __( 'No API key stored for this provider.', 'siteproof' ), array( 'status' => 400 ) );
		}
		$list = $llm->models( rest_sanitize_boolean( $req['refresh'] ) );
		$out  = array();
		foreach ( $list as $id => $m ) {
			$out[] = array(
				'id'    => $id,
				'label' => $m[0],
				'in'    => $m[2],
				'out'   => $m[3],
			);
		}
		return rest_ensure_response(
			array(
				'models'  => $out,
				'default' => $list ? $llm->model() : $llm::fallback_model(),
			)
		);
	}

	/**
	 * Test a connection.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public static function test( WP_REST_Request $req ) {
		self::long();
		$provider = sanitize_key( (string) $req['provider'] );
		if ( 'pagespeed' === $provider ) {
			$key = Siteproof_Settings::key( 'pagespeed' );
			if ( '' === $key ) {
				return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No key stored.', 'siteproof' ) ) );
			}
			$url = Siteproof_PageSpeed::target( home_url( '/' ) );
			if ( ! Siteproof_PageSpeed::public_url( $url ) ) {
				$url = 'https://wordpress.org/';
			}
			$r = Siteproof_PageSpeed::run( $url, $key );
			return rest_ensure_response(
				array(
					'ok'      => empty( $r['error'] ),
					'message' => empty( $r['error'] ) ? sprintf( /* translators: 1: URL, 2: performance score */ __( 'OK - %1$s measured, score %2$d.', 'siteproof' ), $url, (int) $r['score'] ) : $r['error'],
				)
			);
		}
		$llm = Siteproof_Settings::llm( $provider );
		if ( ! $llm ) {
			return rest_ensure_response( array( 'ok' => false, 'message' => __( 'No key stored.', 'siteproof' ) ) );
		}
		$ok = $llm->test_connection();
		if ( $ok ) {
			$message = __( 'Connection OK.', 'siteproof' );
		} elseif ( 'wordpress' === $provider ) {
			$message = __( 'No AI connection for text generation is set up under Settings › Connectors.', 'siteproof' );
		} else {
			$message = __( 'Connection failed - please check the key.', 'siteproof' );
		}
		return rest_ensure_response(
			array(
				'ok'      => $ok,
				'message' => $message,
			)
		);
	}

	// ------------------------------------------------------------------ Fixes from the report

	/**
	 * Save an alt text to the attachment and to empty img tags of the page.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function fix_alt( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$att  = absint( $req['attachment'] );
		$post = absint( $req['post'] );
		$alt  = sanitize_text_field( (string) $req['alt'] );
		if ( 'attachment' !== get_post_type( $att ) || '' === $alt ) {
			return new WP_Error( 'siteproof_alt', __( 'Invalid image or empty alt text.', 'siteproof' ), array( 'status' => 400 ) );
		}
		$res = Siteproof_Fixer::alt( $att, $alt, $post && current_user_can( 'edit_post', $post ) ? $post : 0 );
		$data                             = $run['data'];
		$data['fixes']['alt'][ $att ]     = $alt;
		Siteproof_DB::update_run( $run['id'], array( 'data' => $data ) );
		return rest_ensure_response( $res );
	}

	/**
	 * Apply a proofreading correction to the page content.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function fix_text( WP_REST_Request $req ) {
		$run = self::run( $req );
		if ( is_wp_error( $run ) ) {
			return $run;
		}
		$post_id = absint( $req['post'] );
		$index   = absint( $req['index'] );
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'siteproof_cap', __( 'You may not edit this page.', 'siteproof' ), array( 'status' => 403 ) );
		}
		$page    = Siteproof_DB::get_page( $run['id'], $post_id );
		$finding = $page['review']['proofreading'][ $index ] ?? null;
		if ( ! $finding ) {
			return new WP_Error( 'siteproof_finding', __( 'Finding not found.', 'siteproof' ), array( 'status' => 404 ) );
		}
		$suggestion = sanitize_text_field( (string) $req['suggestion'] );
		$segment    = '';
		foreach ( $page['server']['segments'] ?? array() as $s ) {
			if ( $s['id'] === $finding['seg'] ) {
				$segment = $s['text'];
			}
		}
		$res = Siteproof_Fixer::text( $post_id, $finding['error'], $suggestion, $segment );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$review                                          = $page['review'];
		$review['proofreading'][ $index ]['applied']     = $suggestion;
		Siteproof_DB::save_step( $run['id'], $post_id, 'review', $review );
		return rest_ensure_response( $res );
	}
}

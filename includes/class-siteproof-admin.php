<?php
/**
 * Admin: menu "Siteproof" (Audits, Settings), runner, report view, settings form,
 * OpenRouter OAuth callback, delete/export, dashboard warning.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Admin screens.
 */
class Siteproof_Admin {

	/** @var string[] Hook suffixes of the plugin pages */
	private static $hooks = array();

	/**
	 * Register hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_init', array( __CLASS__, 'oauth_callback' ) );
		add_action( 'admin_post_siteproof_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_siteproof_or_connect', array( __CLASS__, 'or_connect' ) );
		add_action( 'admin_post_siteproof_or_disconnect', array( __CLASS__, 'or_disconnect' ) );
		add_action( 'admin_post_siteproof_delete', array( __CLASS__, 'delete' ) );
		add_action( 'admin_post_siteproof_export', array( __CLASS__, 'export' ) );
		add_action( 'admin_notices', array( __CLASS__, 'dashboard_notice' ) );
		add_action( 'wp_ajax_siteproof_dismiss', array( __CLASS__, 'dismiss' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( SITEPROOF_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu.
	 */
	public static function menu() {
		self::$hooks[] = add_menu_page( __( 'Siteproof', 'siteproof' ), __( 'Siteproof', 'siteproof' ), SITEPROOF_CAP, 'siteproof', array( __CLASS__, 'page_audits' ), 'dashicons-yes-alt', 81 );
		self::$hooks[] = add_submenu_page( 'siteproof', __( 'Audits', 'siteproof' ), __( 'Audits', 'siteproof' ), SITEPROOF_CAP, 'siteproof', array( __CLASS__, 'page_audits' ) );
		self::$hooks[] = add_submenu_page( 'siteproof', __( 'Siteproof settings', 'siteproof' ), __( 'Settings', 'siteproof' ), SITEPROOF_CAP, 'siteproof-settings', array( __CLASS__, 'page_settings' ) );
	}

	/**
	 * Settings link in the plugin list.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=siteproof-settings' ) ), esc_html__( 'Settings', 'siteproof' ) ) );
		return $links;
	}

	/**
	 * Assets only on the plugin pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( $hook ) {
		if ( ! in_array( $hook, self::$hooks, true ) ) {
			return;
		}
		wp_enqueue_style( 'siteproof-admin', SITEPROOF_URL . 'assets/css/admin.css', array(), SITEPROOF_VERSION );
		$common = array(
			'rest'  => esc_url_raw( rest_url( Siteproof_REST::NS ) ),
			'nonce' => wp_create_nonce( 'wp_rest' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( 'siteproof-settings' === $page ) {
			wp_enqueue_script( 'siteproof-settings', SITEPROOF_URL . 'assets/js/settings.js', array(), SITEPROOF_VERSION, true );
			wp_localize_script(
				'siteproof-settings',
				'siteproofSettings',
				$common + array(
					'models' => Siteproof_Settings::get()['models'],
					'i18n'   => array(
						'testing'  => __( 'Testing…', 'siteproof' ),
						'loading'  => __( 'Loading models…', 'siteproof' ),
						'default'  => __( 'Default', 'siteproof' ),
						'noKey'    => __( 'Store a key first.', 'siteproof' ),
						'perMTok'  => __( 'USD per million tokens (in/out)', 'siteproof' ),
						'failed'   => __( 'Request failed.', 'siteproof' ),
					),
				)
			);
			return;
		}
		wp_enqueue_script( 'siteproof-report', SITEPROOF_URL . 'assets/js/report.js', array(), SITEPROOF_VERSION, true );
		wp_localize_script(
			'siteproof-report',
			'siteproofReport',
			$common + array(
				'i18n' => array(
					'saving' => __( 'Saving…', 'siteproof' ),
					'failed' => __( 'Saving failed.', 'siteproof' ),
					'edit'   => __( 'Open editor', 'siteproof' ),
				),
			)
		);
		wp_enqueue_script( 'siteproof-runner', SITEPROOF_URL . 'assets/js/runner.js', array(), SITEPROOF_VERSION, true );
		wp_localize_script(
			'siteproof-runner',
			'siteproofRunner',
			$common + array(
				'axe'       => SITEPROOF_URL . 'assets/vendor/axe-core/axe.min.js',
				'axeLocale' => self::axe_locale_url(),
				'i18n'      => array(
					'server'      => __( 'Read pages', 'siteproof' ),
					'links'       => __( 'Check links', 'siteproof' ),
					'browser'     => __( 'Measure in the browser (images, timing, accessibility)', 'siteproof' ),
					'psi'         => __( 'PageSpeed Insights', 'siteproof' ),
					'review'      => __( 'AI proofreading', 'siteproof' ),
					'finish'      => __( 'Build report', 'siteproof' ),
					'paused'      => __( 'Paused. You can resume this run later from the list.', 'siteproof' ),
					'error'       => __( 'Error', 'siteproof' ),
					'done'        => __( 'Done - opening the report…', 'siteproof' ),
					'costTitle'   => __( 'Estimated AI costs', 'siteproof' ),
					'noPrice'     => __( 'no price stored', 'siteproof' ),
					'tokens'      => __( 'tokens', 'siteproof' ),
					'selected'    => __( 'selected', 'siteproof' ),
					'notSet'      => __( 'not configured', 'siteproof' ),
					'unthrottled' => __( 'local, unthrottled', 'siteproof' ),
					'confirm'     => __( 'Stop the audit? It can be resumed later.', 'siteproof' ),
				),
			)
		);
	}

	/**
	 * URL of the axe locale matching the admin language (if bundled).
	 *
	 * @return string
	 */
	private static function axe_locale_url() {
		$locale = get_user_locale();
		foreach ( array( $locale, substr( $locale, 0, 2 ) ) as $name ) {
			if ( preg_match( '/^[a-z]{2}(_[A-Z]{2})?$/', $name ) && file_exists( SITEPROOF_DIR . "assets/vendor/axe-core/locales/$name.json" ) ) {
				return SITEPROOF_URL . "assets/vendor/axe-core/locales/$name.json";
			}
		}
		return '';
	}

	// ------------------------------------------------------------------ Audits

	/**
	 * Audits page: list and runner, or a single report.
	 */
	public static function page_audits() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'siteproof' ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view switch only.
		$run_id = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		$run    = $run_id ? Siteproof_DB::get_run( $run_id ) : null;
		echo '<div class="wrap siteproof">';
		if ( $run && 'done' === $run['status'] ) {
			self::report_view( $run );
		} else {
			self::list_view( $run );
		}
		echo '</div>';
	}

	/**
	 * A finished report.
	 *
	 * @param array $run Run.
	 */
	private static function report_view( array $run ) {
		$report = new Siteproof_Report( $run, Siteproof_DB::get_pages( $run['id'] ) );
		printf(
			'<div class="sp-actions"><a href="%s">&larr; %s</a> <a class="button" href="%s">%s</a> %s</div>',
			esc_url( admin_url( 'admin.php?page=siteproof' ) ),
			esc_html__( 'All audits', 'siteproof' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=siteproof_export&run=' . $run['id'] ), 'siteproof_export_' . $run['id'] ) ),
			esc_html__( 'Export as HTML', 'siteproof' ),
			self::delete_form( $run['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_form().
		);
		echo $report->render(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the report escapes every value while building.
	}

	/**
	 * Delete button as a small form.
	 *
	 * @param int|string $id Run ID or "all".
	 * @return string
	 */
	private static function delete_form( $id ) {
		$all = 'all' === $id;
		return sprintf(
			'<form method="post" action="%s" class="sp-inline" data-confirm="%s">%s<input type="hidden" name="action" value="siteproof_delete"><input type="hidden" name="run" value="%s"><button type="submit" class="button-link button-link-delete">%s</button></form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			esc_attr( $all ? __( 'Delete all audit runs permanently?', 'siteproof' ) : __( 'Delete this audit run permanently?', 'siteproof' ) ),
			wp_nonce_field( 'siteproof_delete_' . $id, '_wpnonce', true, false ),
			esc_attr( (string) $id ),
			esc_html( $all ? __( 'Delete all', 'siteproof' ) : __( 'Delete', 'siteproof' ) )
		);
	}

	/**
	 * Start form, runner and list of runs.
	 *
	 * @param array|null $resume Run to resume.
	 */
	private static function list_view( $resume ) {
		$s        = Siteproof_Settings::get();
		$has_psi  = '' !== Siteproof_Settings::key( 'pagespeed' );
		$provider = $s['provider'];
		$has_ai   = (bool) Siteproof_Settings::llm( $provider );
		$runs     = Siteproof_DB::list_runs();
		$source   = Siteproof_Carry::source();
		$counts   = $source ? Siteproof_Carry::counts( $source['levels'] ) : array();

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Siteproof audits', 'siteproof' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a status message.
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Deleted.', 'siteproof' ) . '</p></div>';
		}
		echo '<p class="sp-lead">' . esc_html__( 'Checks every published page: SEO, load time, content (headings, proofreading), images, links and accessibility. Reports are visible to administrators only.', 'siteproof' ) . '</p>';

		// Start form.
		?>
		<div class="sp-card sp-start" id="sp-runner" data-resume="<?php echo esc_attr( $resume ? (string) $resume['id'] : '' ); ?>">
			<form class="sp-start__form">
				<h2><?php esc_html_e( 'New audit', 'siteproof' ); ?></h2>
				<fieldset class="sp-options">
					<legend class="screen-reader-text"><?php esc_html_e( 'Steps', 'siteproof' ); ?></legend>
					<label><input type="checkbox" checked disabled> <?php esc_html_e( 'Read pages and check links (always)', 'siteproof' ); ?></label>
					<label><input type="checkbox" name="browser" checked> <?php esc_html_e( 'Measure in the browser: rendered image sizes, lazy loading, timing, accessibility (axe-core)', 'siteproof' ); ?></label>
					<label><input type="checkbox" name="psi" <?php checked( $has_psi ); ?> <?php disabled( ! $has_psi ); ?>> <?php esc_html_e( 'Load time via Google PageSpeed Insights', 'siteproof' ); ?>
						<?php if ( ! $has_psi ) : ?>
							<span class="sp-note"><?php esc_html_e( '(no API key stored - local measurement is used)', 'siteproof' ); ?></span>
						<?php endif; ?>
					</label>
					<label><input type="checkbox" name="ai" <?php checked( $has_ai ); ?> <?php disabled( ! $has_ai ); ?>> <?php esc_html_e( 'AI proofreading and SEO suggestions', 'siteproof' ); ?>
						<span class="sp-note">
						<?php
						echo $has_ai
							/* translators: %s: provider */
							? esc_html( sprintf( __( '(%s, costs are shown before the step starts)', 'siteproof' ), Siteproof_Settings::providers()[ $provider ] ?? $provider ) )
							: esc_html__( '(no AI provider configured)', 'siteproof' );
						?>
						</span>
					</label>
				</fieldset>
				<p>
					<label for="sp-ids"><?php esc_html_e( 'Only these post IDs (optional, comma-separated)', 'siteproof' ); ?></label><br>
					<input type="text" id="sp-ids" name="ids" class="regular-text" inputmode="numeric" pattern="[0-9, ]*">
				</p>
				<fieldset class="sp-only" <?php disabled( ! $source ); ?>>
					<legend><?php esc_html_e( 'Only pages that reached the following levels in the last scan', 'siteproof' ); ?></legend>
					<table class="sp-only__table">
						<?php foreach ( Siteproof_Report::sections() as $cat => $label ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<?php foreach ( Siteproof_Carry::LEVELS as $level ) : ?>
									<td>
										<label>
											<input type="checkbox" data-cat="<?php echo esc_attr( $cat ); ?>" value="<?php echo esc_attr( $level ); ?>">
											<span class="sp-dot sp-dot--<?php echo esc_attr( $level ); ?>" aria-hidden="true"></span>
											<?php echo esc_html( Siteproof_Report::label( $level ) ); ?>
											<?php if ( $source ) : ?>
												<span class="sp-note">(<?php echo (int) $counts[ $cat ][ $level ]; ?>)</span>
											<?php endif; ?>
										</label>
									</td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</table>
					<p class="sp-note">
						<?php
						esc_html_e( 'All other results are carried over.', 'siteproof' );
						echo ' ';
						if ( $source ) {
							/* translators: %s: date of the last audit */
							echo esc_html( sprintf( __( 'Without a selection every page is checked. In brackets: pages of the last scan (%s). New pages and results that were not checkable are always checked.', 'siteproof' ), wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $source['run']['created'] . ' UTC' ) ) ) );
						} else {
							esc_html_e( 'Available as soon as a finished audit is stored.', 'siteproof' );
						}
						?>
					</p>
				</fieldset>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Start audit', 'siteproof' ); ?></button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=siteproof-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'siteproof' ); ?></a>
				</p>
				<p class="sp-note"><?php esc_html_e( 'Keep this tab open while the audit runs. Pages are loaded in a small preview frame; analytics scripts on your pages may count these visits.', 'siteproof' ); ?></p>
			</form>
			<div class="sp-progress" hidden>
				<h2 class="sp-progress__title"><?php esc_html_e( 'Audit running', 'siteproof' ); ?></h2>
				<ol class="sp-steps"></ol>
				<p class="sp-progress__current" aria-live="polite"></p>
				<div class="sp-preview" aria-hidden="true"><iframe title="" tabindex="-1"></iframe></div>
				<p><button type="button" class="button sp-stop"><?php esc_html_e( 'Stop', 'siteproof' ); ?></button></p>
				<details class="sp-log"><summary><?php esc_html_e( 'Log', 'siteproof' ); ?></summary><ol></ol></details>
			</div>
			<dialog class="sp-dialog">
				<form method="dialog">
					<h2><?php esc_html_e( 'Estimated AI costs', 'siteproof' ); ?></h2>
					<div class="sp-dialog__body"></div>
					<p class="sp-note"><?php esc_html_e( 'Estimate based on the collected text; actual costs depend on the model and its reasoning. Prices are editable in the settings.', 'siteproof' ); ?></p>
					<p>
						<button value="yes" class="button button-primary"><?php esc_html_e( 'Start proofreading', 'siteproof' ); ?></button>
						<button value="no" class="button"><?php esc_html_e( 'Skip proofreading', 'siteproof' ); ?></button>
					</p>
				</form>
			</dialog>
		</div>
		<?php

		// Runs.
		echo '<h2>' . esc_html__( 'Stored audit runs', 'siteproof' ) . '</h2>';
		if ( ! $runs ) {
			echo '<p class="sp-note">' . esc_html__( 'No audit runs yet.', 'siteproof' ) . '</p>';
			return;
		}
		$labels = array(
			'running' => __( 'running', 'siteproof' ),
			'paused'  => __( 'paused', 'siteproof' ),
			'done'    => __( 'done', 'siteproof' ),
		);
		echo '<table class="widefat striped sp-runs"><thead><tr><th scope="col">' . esc_html__( 'Date', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Status', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Pages', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Result', 'siteproof' ) . '</th><th scope="col">' . esc_html__( 'Most important', 'siteproof' ) . '</th><th scope="col"><span class="screen-reader-text">' . esc_html__( 'Actions', 'siteproof' ) . '</span></th></tr></thead><tbody>';
		foreach ( $runs as $r ) {
			$c    = $r['summary']['counts'] ?? null;
			$url  = admin_url( 'admin.php?page=siteproof&run=' . $r['id'] );
			$date = wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $r['created'] . ' UTC' ) );
			$top  = '';
			foreach ( array_slice( $r['summary']['top'] ?? array(), 0, 3 ) as $t ) {
				$top .= sprintf( '<li><span class="sp-dot sp-dot--%s%s" aria-hidden="true"></span> %s</li>', esc_attr( $t[0] ), $t[1] && 'red' === $t[0] ? ' sp-dot--crit' : '', esc_html( $t[2] ) );
			}
			printf(
				'<tr><td><a href="%s"><strong>%s</strong></a>%s</td><td>%s</td><td>%d</td><td>%s</td><td><ul class="sp-top">%s</ul></td><td class="sp-row-actions">%s %s</td></tr>',
				esc_url( $url ),
				esc_html( $date ),
				empty( $r['settings']['only'] ) ? '' : '<div class="sp-note">' . esc_html__( 'selective re-check', 'siteproof' ) . '</div>',
				esc_html( $labels[ $r['status'] ] ?? $r['status'] ),
				(int) $r['pages'],
				$c ? sprintf(
					'<span class="sp-count"><span class="sp-dot sp-dot--red" aria-hidden="true"></span> %1$d</span> <span class="sp-count"><span class="sp-dot sp-dot--yellow" aria-hidden="true"></span> %2$d</span> <span class="sp-count"><span class="sp-dot sp-dot--green" aria-hidden="true"></span> %3$d</span><span class="screen-reader-text">%4$s</span>',
					(int) $c['red'],
					(int) $c['yellow'],
					(int) $c['green'],
					/* translators: 1: red, 2: yellow, 3: green */
					esc_html( sprintf( __( '%1$d critical, %2$d to check, %3$d OK', 'siteproof' ), $c['red'], $c['yellow'], $c['green'] ) )
				) : '–',
				$top, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
				'done' === $r['status']
					? sprintf( '<a href="%s">%s</a> · <a href="%s">%s</a> ·', esc_url( $url ), esc_html__( 'View', 'siteproof' ), esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=siteproof_export&run=' . $r['id'] ), 'siteproof_export_' . $r['id'] ) ), esc_html__( 'Export', 'siteproof' ) )
					: sprintf( '<a href="%s">%s</a> ·', esc_url( $url ), esc_html__( 'Resume', 'siteproof' ) ),
				self::delete_form( $r['id'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_form().
			);
		}
		echo '</tbody></table><div class="sp-actions">' . self::delete_form( 'all' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in delete_form().
	}

	/**
	 * Delete one or all runs.
	 */
	public static function delete() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'siteproof' ) );
		}
		$run = isset( $_POST['run'] ) ? sanitize_key( wp_unslash( $_POST['run'] ) ) : '';
		check_admin_referer( 'siteproof_delete_' . $run );
		if ( 'all' === $run ) {
			Siteproof_DB::delete_all();
		} elseif ( absint( $run ) ) {
			Siteproof_DB::delete_run( absint( $run ) );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=siteproof&deleted=1' ) );
		exit;
	}

	/**
	 * Export a report as a standalone HTML file.
	 */
	public static function export() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'siteproof' ) );
		}
		$id = isset( $_GET['run'] ) ? absint( $_GET['run'] ) : 0;
		check_admin_referer( 'siteproof_export_' . $id );
		$run = Siteproof_DB::get_run( $id );
		if ( ! $run || 'done' !== $run['status'] ) {
			wp_die( esc_html__( 'Report not found.', 'siteproof' ) );
		}
		$report = new Siteproof_Report( $run, Siteproof_DB::get_pages( $id ), true );
		$css    = (string) file_get_contents( SITEPROOF_DIR . 'assets/css/report.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$js     = (string) file_get_contents( SITEPROOF_DIR . 'assets/js/report.js' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local plugin file.
		$name   = sanitize_file_name( 'content-audit-' . wp_parse_url( home_url(), PHP_URL_HOST ) . '-' . gmdate( 'Y-m-d', strtotime( $run['created'] ) ) . '.html' );
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $name . '"' );
		printf(
			'<!doctype html><html lang="%s"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex, nofollow"><title>%s</title><style>%s</style></head><body class="sp-export"><div class="siteproof">%s</div><script>%s</script></body></html>',
			esc_attr( get_bloginfo( 'language' ) ),
			/* translators: %s: site name */
			esc_html( sprintf( __( 'Content audit %s', 'siteproof' ), get_bloginfo( 'name' ) ) ),
			$css, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin stylesheet.
			$report->render(), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- the report escapes every value while building.
			$js // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plugin script.
		);
		exit;
	}

	// ------------------------------------------------------------------ Settings

	/**
	 * Settings page.
	 */
	public static function page_settings() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to access this page.', 'siteproof' ) );
		}
		$s         = Siteproof_Settings::get();
		$providers = Siteproof_Settings::providers();
		$types     = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		?>
		<div class="wrap siteproof sp-settings">
			<h1><?php esc_html_e( 'Siteproof settings', 'siteproof' ); ?></h1>
			<?php self::settings_notices(); ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="siteproof_save_settings">
				<?php wp_nonce_field( 'siteproof_save_settings' ); ?>

				<div class="sp-card">
					<h2><?php esc_html_e( 'AI proofreading', 'siteproof' ); ?></h2>
					<p class="sp-note"><?php esc_html_e( 'Proofreading and SEO suggestions are sent page by page to the selected provider (text segments, title, meta description, image URLs). Anthropic and OpenAI need an API key from their developer consoles: logging in with a Claude Pro/Max or ChatGPT subscription is not possible - Anthropic does not allow subscription logins in third-party applications, and "Sign in with ChatGPT" only provides identity, no model access. OpenRouter can be connected with one click and gives access to Claude and OpenAI models with a single key.', 'siteproof' ); ?></p>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Provider', 'siteproof' ); ?></th>
							<td>
								<?php foreach ( $providers as $id => $label ) : ?>
									<label class="sp-radio"><input type="radio" name="provider" value="<?php echo esc_attr( $id ); ?>" <?php checked( $s['provider'], $id ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sp-fallback"><?php esc_html_e( 'Fallback', 'siteproof' ); ?></label></th>
							<td>
								<select id="sp-fallback" name="fallback">
									<option value=""><?php esc_html_e( 'none', 'siteproof' ); ?></option>
									<?php foreach ( $providers as $id => $label ) : ?>
										<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['fallback'], $id ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
								<p class="description"><?php esc_html_e( 'Used when the selected provider fails or hits its rate limit.', 'siteproof' ); ?></p>
							</td>
						</tr>
						<?php foreach ( $providers as $id => $label ) : ?>
							<tr class="sp-provider" data-provider="<?php echo esc_attr( $id ); ?>">
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td>
									<?php if ( 'wordpress' === $id ) : ?>
										<p class="sp-note">
											<?php esc_html_e( 'Uses the AI connections of this site. WordPress chooses provider and model; no separate key is needed.', 'siteproof' ); ?>
											<a href="<?php echo esc_url( admin_url( 'options-connectors.php' ) ); ?>"><?php esc_html_e( 'Manage connections', 'siteproof' ); ?></a>
										</p>
										<p><button type="button" class="button sp-test" data-provider="wordpress"><?php esc_html_e( 'Test connection', 'siteproof' ); ?></button> <span class="sp-test__result" role="status"></span></p>
								</td>
							</tr>
										<?php
										continue;
									endif;
									?>
									<?php self::key_field( $id ); ?>
									<?php if ( 'openrouter' === $id && ! Siteproof_Settings::key_from_constant( 'openrouter' ) ) : ?>
										<p>
										<?php if ( '' === Siteproof_Settings::key( 'openrouter' ) ) : ?>
											<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=siteproof_or_connect' ), 'siteproof_or_connect' ) ); ?>"><?php esc_html_e( 'Connect with OpenRouter', 'siteproof' ); ?></a>
										<?php else : ?>
											<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=siteproof_or_disconnect' ), 'siteproof_or_disconnect' ) ); ?>"><?php esc_html_e( 'Disconnect', 'siteproof' ); ?></a>
										<?php endif; ?>
										</p>
									<?php endif; ?>
									<p class="sp-model">
										<label for="sp-model-<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Model', 'siteproof' ); ?></label>
										<select id="sp-model-<?php echo esc_attr( $id ); ?>" name="models[<?php echo esc_attr( $id ); ?>]" data-provider="<?php echo esc_attr( $id ); ?>" data-has-key="<?php echo esc_attr( '' !== Siteproof_Settings::key( $id ) ? '1' : '' ); ?>">
											<option value="<?php echo esc_attr( $s['models'][ $id ] ); ?>"><?php echo esc_html( '' !== $s['models'][ $id ] ? $s['models'][ $id ] : __( 'Default', 'siteproof' ) ); ?></option>
										</select>
										<button type="button" class="button-link sp-refresh" data-provider="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Reload list', 'siteproof' ); ?></button>
									</p>
								</td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row"><label for="sp-prices"><?php esc_html_e( 'Prices', 'siteproof' ); ?></label></th>
							<td>
								<textarea id="sp-prices" name="prices" rows="5" class="large-text code" placeholder="model-id | 2.00 | 10.00"><?php echo esc_textarea( $s['prices'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One line per model: model ID | USD per million input tokens | USD per million output tokens. Used for the cost estimate before proofreading. OpenRouter prices are loaded automatically.', 'siteproof' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sp-card">
					<h2><?php esc_html_e( 'Load time', 'siteproof' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'PageSpeed Insights API key', 'siteproof' ); ?></th>
							<td>
								<?php self::key_field( 'pagespeed' ); ?>
								<p class="description"><?php esc_html_e( 'Free key from the Google Cloud Console (PageSpeed Insights API). Without a key, load time is measured locally in the browser (unthrottled, less realistic).', 'siteproof' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sp-live"><?php esc_html_e( 'Live URL', 'siteproof' ); ?></label></th>
							<td>
								<input type="url" id="sp-live" name="live_url" class="regular-text" value="<?php echo esc_attr( $s['live_url'] ); ?>" placeholder="https://example.com">
								<p class="description"><?php esc_html_e( 'For staging or local sites: PageSpeed Insights measures the same paths on this domain.', 'siteproof' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sp-card">
					<h2><?php esc_html_e( 'Scope', 'siteproof' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><?php esc_html_e( 'Post types', 'siteproof' ); ?></th>
							<td>
								<?php foreach ( $types as $type ) : ?>
									<label class="sp-radio"><input type="checkbox" name="types[]" value="<?php echo esc_attr( $type->name ); ?>" <?php checked( in_array( $type->name, (array) $s['types'], true ) ); ?>> <?php echo esc_html( $type->labels->name ); ?></label>
								<?php endforeach; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sp-exclude"><?php esc_html_e( 'Excluded URLs', 'siteproof' ); ?></label></th>
							<td>
								<textarea id="sp-exclude" name="excluded_urls" rows="4" class="large-text code" placeholder="/impressum/&#10;/shop/*"><?php echo esc_textarea( $s['excluded_urls'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One pattern per line, * as wildcard, matched against path or full URL.', 'siteproof' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="sp-words"><?php esc_html_e( 'Word list', 'siteproof' ); ?></label></th>
							<td>
								<textarea id="sp-words" name="wordlist" rows="4" class="large-text"><?php echo esc_textarea( $s['wordlist'] ); ?></textarea>
								<p class="description"><?php esc_html_e( 'Names and terms that are always correct (one per line).', 'siteproof' ); ?></p>
							</td>
						</tr>
					</table>
				</div>

				<div class="sp-card">
					<h2><?php esc_html_e( 'Thresholds', 'siteproof' ); ?></h2>
					<div class="sp-thresholds">
						<?php foreach ( Siteproof_Settings::threshold_fields() as $k => $f ) : ?>
							<p>
								<label for="sp-th-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $f[0] ); ?></label>
								<span class="sp-thresholds__input"><input type="number" step="any" min="0" id="sp-th-<?php echo esc_attr( $k ); ?>" name="thresholds[<?php echo esc_attr( $k ); ?>]" value="<?php echo esc_attr( (string) $s['thresholds'][ $k ] ); ?>" class="small-text"> <?php echo esc_html( $f[2] ); ?></span>
							</p>
						<?php endforeach; ?>
					</div>
				</div>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * API key field (masked, never prints the key).
	 *
	 * @param string $id Key name.
	 */
	private static function key_field( $id ) {
		$masked = Siteproof_Settings::masked( $id );
		if ( Siteproof_Settings::key_from_constant( $id ) ) {
			/* translators: %s: constant name */
			printf( '<p><code>%s</code> <span class="sp-note">%s</span></p>', esc_html( $masked ), esc_html( sprintf( __( 'defined in wp-config.php (%s)', 'siteproof' ), Siteproof_Settings::CONSTANTS[ $id ] ) ) );
		} else {
			printf(
				'<p><label class="screen-reader-text" for="sp-key-%1$s">%2$s</label><input type="password" id="sp-key-%1$s" name="keys[%1$s]" class="regular-text" autocomplete="new-password" placeholder="%3$s"></p>',
				esc_attr( $id ),
				esc_html__( 'API key', 'siteproof' ),
				esc_attr( '' !== $masked ? $masked : __( 'API key', 'siteproof' ) )
			);
			if ( '' !== $masked ) {
				printf( '<p><label><input type="checkbox" name="delete_keys[]" value="%s"> %s</label></p>', esc_attr( $id ), esc_html__( 'Delete stored key', 'siteproof' ) );
			}
		}
		if ( '' !== $masked ) {
			printf( '<p><button type="button" class="button sp-test" data-provider="%s">%s</button> <span class="sp-test__result" role="status"></span></p>', esc_attr( $id ), esc_html__( 'Test connection', 'siteproof' ) );
		}
	}

	/**
	 * Messages after saving / OAuth.
	 */
	private static function settings_notices() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of status messages only.
		$msg = isset( $_GET['sp_msg'] ) ? sanitize_key( wp_unslash( $_GET['sp_msg'] ) ) : '';
		$map = array(
			'saved'        => array( 'success', __( 'Settings saved.', 'siteproof' ) ),
			'or_connected' => array( 'success', __( 'Connected with OpenRouter.', 'siteproof' ) ),
			'or_removed'   => array( 'success', __( 'OpenRouter connection removed.', 'siteproof' ) ),
			'or_failed'    => array( 'error', __( 'The OpenRouter connection failed. Please try again.', 'siteproof' ) ),
		);
		// phpcs:enable
		if ( isset( $map[ $msg ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $map[ $msg ][0] ), esc_html( $map[ $msg ][1] ) );
		}
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'siteproof' ) );
		}
		check_admin_referer( 'siteproof_save_settings' );
		$in        = wp_unslash( $_POST );
		$s         = Siteproof_Settings::get();
		$providers = array_keys( Siteproof_Settings::providers() );

		$s['provider'] = in_array( $in['provider'] ?? '', $providers, true ) ? $in['provider'] : 'anthropic';
		$s['fallback'] = in_array( $in['fallback'] ?? '', $providers, true ) && $in['fallback'] !== $s['provider'] ? $in['fallback'] : '';
		foreach ( $providers as $p ) {
			$s['models'][ $p ] = sanitize_text_field( (string) ( $in['models'][ $p ] ?? '' ) );
		}
		$s['prices']   = sanitize_textarea_field( (string) ( $in['prices'] ?? '' ) );
		$public        = get_post_types( array( 'public' => true ) );
		$s['types']    = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $in['types'] ?? array() ) ), $public ) );
		$s['types']    = $s['types'] ? $s['types'] : array( 'page' );
		$s['excluded_urls'] = sanitize_textarea_field( (string) ( $in['excluded_urls'] ?? '' ) );
		$s['wordlist'] = sanitize_textarea_field( (string) ( $in['wordlist'] ?? '' ) );
		$s['live_url'] = esc_url_raw( trim( (string) ( $in['live_url'] ?? '' ) ) );
		foreach ( Siteproof_Settings::threshold_fields() as $k => $f ) {
			$v                    = (float) str_replace( ',', '.', (string) ( $in['thresholds'][ $k ] ?? $f[1] ) );
			$s['thresholds'][ $k ] = $v > 0 ? $v : $f[1];
		}
		Siteproof_Settings::save( $s );

		foreach ( array_keys( Siteproof_Settings::CONSTANTS ) as $k ) {
			if ( in_array( $k, (array) ( $in['delete_keys'] ?? array() ), true ) ) {
				Siteproof_Settings::set_key( $k, '' );
			}
			$new = trim( sanitize_text_field( (string) ( $in['keys'][ $k ] ?? '' ) ) );
			if ( '' !== $new && ! str_contains( $new, '•' ) ) {
				Siteproof_Settings::set_key( $k, $new );
			}
		}
		wp_safe_redirect( admin_url( 'admin.php?page=siteproof-settings&sp_msg=saved' ) );
		exit;
	}

	/**
	 * Start the OpenRouter OAuth flow.
	 */
	public static function or_connect() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'siteproof' ) );
		}
		check_admin_referer( 'siteproof_or_connect' );
		wp_redirect( Siteproof_LLM_OpenRouter::oauth_start( admin_url( 'admin.php?page=siteproof-settings' ) ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- external OAuth endpoint.
		exit;
	}

	/**
	 * Remove the OpenRouter key.
	 */
	public static function or_disconnect() {
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'siteproof' ) );
		}
		check_admin_referer( 'siteproof_or_disconnect' );
		Siteproof_Settings::set_key( 'openrouter', '' );
		wp_safe_redirect( admin_url( 'admin.php?page=siteproof-settings&sp_msg=or_removed' ) );
		exit;
	}

	/**
	 * OAuth return from OpenRouter (?code=...&siteproof_or_state=...). The state stored per user
	 * in a transient replaces the nonce, because OpenRouter builds the return URL.
	 */
	public static function oauth_callback() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified via the per-user PKCE state.
		if ( ! isset( $_GET['page'], $_GET['code'], $_GET['siteproof_or_state'] ) || 'siteproof-settings' !== $_GET['page'] ) {
			return;
		}
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			return;
		}
		$key = Siteproof_LLM_OpenRouter::oauth_finish( sanitize_text_field( wp_unslash( $_GET['code'] ) ), sanitize_text_field( wp_unslash( $_GET['siteproof_or_state'] ) ) );
		// phpcs:enable
		if ( is_wp_error( $key ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=siteproof-settings&sp_msg=or_failed' ) );
			exit;
		}
		Siteproof_Settings::set_key( 'openrouter', $key );
		$s = Siteproof_Settings::get();
		if ( '' === Siteproof_Settings::key( $s['provider'] ) ) {
			$s['provider'] = 'openrouter';
			Siteproof_Settings::save( $s );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=siteproof-settings&sp_msg=or_connected' ) );
		exit;
	}

	// ------------------------------------------------------------------ Dashboard warning

	/**
	 * Red, dismissible warning on the dashboard while audit runs are stored.
	 * Dismissal is stored per user and reset when the stored runs change.
	 */
	public static function dashboard_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'dashboard' !== $screen->id || ! current_user_can( SITEPROOF_CAP ) ) {
			return;
		}
		$count = Siteproof_DB::count_runs();
		$state = (string) get_option( 'siteproof_state' );
		if ( ! $count || get_user_meta( get_current_user_id(), 'siteproof_dismissed', true ) === $state ) {
			return;
		}
		printf(
			'<div id="siteproof-warning" class="notice notice-error is-dismissible" data-nonce="%1$s"><p><strong>%2$s</strong> %3$s <a href="%4$s">%5$s</a></p></div>',
			esc_attr( wp_create_nonce( 'siteproof_dismiss' ) ),
			/* translators: %d: number of audit runs */
			esc_html( sprintf( _n( 'Siteproof: %d audit run is stored.', 'Siteproof: %d audit runs are stored.', $count, 'siteproof' ), $count ) ),
			esc_html__( 'Reports contain findings and edit links and are visible to administrators only. Delete them when they are no longer needed, at the latest before going live:', 'siteproof' ),
			esc_url( admin_url( 'admin.php?page=siteproof' ) ),
			esc_html__( 'Siteproof › Audits', 'siteproof' )
		);
		wp_enqueue_script( 'siteproof-dismiss', SITEPROOF_URL . 'assets/js/dismiss.js', array(), SITEPROOF_VERSION, true );
	}

	/**
	 * Store the dismissal.
	 */
	public static function dismiss() {
		check_ajax_referer( 'siteproof_dismiss' );
		if ( ! current_user_can( SITEPROOF_CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		update_user_meta( get_current_user_id(), 'siteproof_dismissed', (string) get_option( 'siteproof_state' ) );
		wp_send_json_success();
	}
}

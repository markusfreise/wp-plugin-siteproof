<?php
/**
 * OpenRouter (OpenAI-compatible API; Claude and OpenAI models).
 * Connection either via OAuth PKCE (user-owned key) or a manually entered key.
 * Only models that support structured outputs are offered.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * OpenRouter provider.
 */
class Siteproof_LLM_OpenRouter extends Siteproof_LLM_OpenAI {

	const OR_API  = 'https://openrouter.ai/api/v1';
	const OR_AUTH = 'https://openrouter.ai/auth';

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'openrouter';
	}

	/**
	 * Fallback model (only used when the model list is unavailable).
	 *
	 * @return string
	 */
	public static function fallback_model(): string {
		return 'anthropic/claude-sonnet-5.5';
	}

	/**
	 * Base URL of the chat API.
	 *
	 * @return string
	 */
	protected function api() {
		return self::OR_API;
	}

	/**
	 * Request headers (app attribution as recommended by OpenRouter).
	 *
	 * @return array
	 */
	protected function headers() {
		return array(
			'Authorization' => 'Bearer ' . $this->key,
			'HTTP-Referer'  => home_url( '/' ),
			'X-Title'       => 'Siteproof',
		);
	}

	/**
	 * Load models: Anthropic and OpenAI models with structured output support, with prices.
	 *
	 * @return array
	 */
	protected function fetch_models(): array {
		$r = $this->http( 'GET', self::OR_API . '/models', array() );
		if ( ! empty( $r['error'] ) || empty( $r['body']['data'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $r['body']['data'] as $m ) {
			$id = $m['id'];
			if ( ! preg_match( '#^(anthropic|openai)/#', $id ) || ! in_array( 'structured_outputs', (array) ( $m['supported_parameters'] ?? array() ), true ) ) {
				continue;
			}
			$in         = isset( $m['pricing']['prompt'] ) ? (float) $m['pricing']['prompt'] * 1000000 : null;
			$outp       = isset( $m['pricing']['completion'] ) ? (float) $m['pricing']['completion'] * 1000000 : null;
			$out[ $id ] = array( $m['name'] ?? $id, (int) ( $m['created'] ?? 0 ), $in, $outp );
		}
		uasort( $out, fn( $a, $b ) => $b[1] <=> $a[1] );
		return $out;
	}

	/**
	 * Default: newest Claude Sonnet.
	 *
	 * @param array $models Models.
	 * @return string
	 */
	protected static function pick_default( array $models ): string {
		foreach ( $models as $id => $m ) {
			if ( str_starts_with( $id, 'anthropic/claude-sonnet' ) ) {
				return $id;
			}
		}
		return $models ? (string) array_key_first( $models ) : self::fallback_model();
	}

	/**
	 * Key valid?
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		$r = $this->http( 'GET', self::OR_API . '/key', $this->headers() );
		return empty( $r['error'] ) && 200 === $r['code'];
	}

	/**
	 * Request body: OpenRouter uses max_tokens.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	protected function body( string $system, string $user ) {
		$body = parent::body( $system, $user );
		unset( $body['max_completion_tokens'] );
		$body['max_tokens'] = 16000;
		return $body;
	}

	// ------------------------------------------------------------------ OAuth PKCE

	/**
	 * Start the PKCE flow: store verifier and state per user, return the authorisation URL.
	 *
	 * @param string $callback Callback URL (settings page).
	 * @return string
	 */
	public static function oauth_start( $callback ) {
		$verifier  = rtrim( strtr( base64_encode( random_bytes( 48 ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE code verifier (base64url).
		$state     = wp_generate_password( 24, false );
		$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE S256 challenge.
		set_transient(
			'siteproof_or_pkce_' . get_current_user_id(),
			array(
				'verifier' => $verifier,
				'state'    => $state,
			),
			15 * MINUTE_IN_SECONDS
		);
		return add_query_arg(
			array(
				'callback_url'          => rawurlencode( add_query_arg( 'siteproof_or_state', $state, $callback ) ),
				'code_challenge'        => $challenge,
				'code_challenge_method' => 'S256',
			),
			self::OR_AUTH
		);
	}

	/**
	 * Finish the PKCE flow: check state, exchange code for a user-owned key.
	 *
	 * @param string $code  Code from the callback.
	 * @param string $state State from the callback.
	 * @return string|WP_Error Key.
	 */
	public static function oauth_finish( $code, $state ) {
		$tkey  = 'siteproof_or_pkce_' . get_current_user_id();
		$saved = get_transient( $tkey );
		delete_transient( $tkey );
		if ( ! is_array( $saved ) || ! hash_equals( (string) $saved['state'], (string) $state ) ) {
			return new WP_Error( 'siteproof_state', __( 'The OpenRouter connection could not be verified (state mismatch or expired). Please try again.', 'siteproof' ) );
		}
		$res = wp_remote_post(
			self::OR_API . '/auth/keys',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'code'                  => $code,
						'code_verifier'         => $saved['verifier'],
						'code_challenge_method' => 'S256',
					)
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $body['key'] ) ) {
			return new WP_Error( 'siteproof_or', __( 'OpenRouter did not return a key.', 'siteproof' ) );
		}
		return (string) $body['key'];
	}
}

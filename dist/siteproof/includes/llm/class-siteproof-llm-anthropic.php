<?php
/**
 * Anthropic (Claude) via the Messages API with structured output (output_config.format).
 *
 * Current models reject forced tool_choice, so the JSON schema is enforced through
 * output_config.format instead of a forced tool call.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Anthropic provider.
 */
class Siteproof_LLM_Anthropic extends Siteproof_LLM_Base {

	const API     = 'https://api.anthropic.com/v1';
	const VERSION = '2023-06-01';

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'anthropic';
	}

	/**
	 * Fallback model (only used when the model list is unavailable).
	 *
	 * @return string
	 */
	public static function fallback_model(): string {
		return 'claude-sonnet-5-5';
	}

	/**
	 * Request headers.
	 *
	 * @return array
	 */
	private function headers() {
		return array(
			'x-api-key'         => $this->key,
			'anthropic-version' => self::VERSION,
		);
	}

	/**
	 * Load models.
	 *
	 * @return array
	 */
	protected function fetch_models(): array {
		$r = $this->http( 'GET', self::API . '/models?limit=100', $this->headers() );
		if ( ! empty( $r['error'] ) || empty( $r['body']['data'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $r['body']['data'] as $m ) {
			$out[ $m['id'] ] = array( $m['display_name'] ?? $m['id'], isset( $m['created_at'] ) ? strtotime( $m['created_at'] ) : 0, null, null );
		}
		return $out;
	}

	/**
	 * Default: the newest Sonnet in the list.
	 *
	 * @param array $models Models.
	 * @return string
	 */
	protected static function pick_default( array $models ): string {
		$sonnets = array_filter( $models, fn( $m, $id ) => str_starts_with( $id, 'claude-sonnet' ), ARRAY_FILTER_USE_BOTH );
		uasort( $sonnets, fn( $a, $b ) => $b[1] <=> $a[1] );
		return $sonnets ? (string) array_key_first( $sonnets ) : self::fallback_model();
	}

	/**
	 * Key valid?
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		$r = $this->http( 'GET', self::API . '/models?limit=1', $this->headers() );
		return empty( $r['error'] ) && 200 === $r['code'];
	}

	/**
	 * Review request.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	protected function request( string $system, string $user ): array {
		$model   = $this->model();
		$headers = $this->headers();
		$body    = array(
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);
		// Effort exists on current Opus/Sonnet/Fable models; medium keeps proofreading careful but affordable.
		if ( preg_match( '/^claude-(opus|sonnet|fable|mythos)-(5|4-[6-9])/', $model ) ) {
			$body['output_config']['effort'] = 'medium';
		}
		// Server-side fallback on safety declines (routes by category, no model list to maintain).
		if ( preg_match( '/^claude-(sonnet-5-5|opus-5-5|opus-5|fable-5-1)$/', $model ) ) {
			$body['fallbacks']          = 'default';
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
		}
		$r = $this->http( 'POST', self::API . '/messages', $headers, $body );
		if ( ! empty( $r['error'] ) ) {
			return array(
				'error'     => $r['error'],
				'retryable' => ! empty( $r['retryable'] ),
			);
		}
		$b    = $r['body'];
		$stop = $b['stop_reason'] ?? '';
		if ( 'refusal' === $stop ) {
			return array(
				'error'     => __( 'The model declined the request.', 'siteproof' ),
				'retryable' => false,
			);
		}
		if ( 'max_tokens' === $stop ) {
			return array(
				'error'     => __( 'Answer was cut off (max_tokens).', 'siteproof' ),
				'retryable' => false,
			);
		}
		$text = '';
		foreach ( $b['content'] ?? array() as $block ) {
			if ( 'text' === ( $block['type'] ?? '' ) ) {
				$text .= $block['text'];
			}
		}
		$data = self::parse_json( $text );
		if ( ! $data ) {
			return array(
				'error'     => __( 'Answer is not valid JSON.', 'siteproof' ),
				'retryable' => true,
			);
		}
		return array(
			'data'  => $data,
			'usage' => array( (int) ( $b['usage']['input_tokens'] ?? 0 ), (int) ( $b['usage']['output_tokens'] ?? 0 ) ),
			'model' => $b['model'] ?? $model,
		);
	}
}

<?php
/**
 * OpenAI via Chat Completions with response_format json_schema (strict).
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * OpenAI provider.
 */
class Siteproof_LLM_OpenAI extends Siteproof_LLM_Base {

	const API = 'https://api.openai.com/v1';

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'openai';
	}

	/**
	 * Fallback model (only used when the model list is unavailable).
	 *
	 * @return string
	 */
	public static function fallback_model(): string {
		return 'gpt-5';
	}

	/**
	 * Base URL of the chat API.
	 *
	 * @return string
	 */
	protected function api() {
		return self::API;
	}

	/**
	 * Request headers.
	 *
	 * @return array
	 */
	protected function headers() {
		return array( 'Authorization' => 'Bearer ' . $this->key );
	}

	/**
	 * Load models (chat models only).
	 *
	 * @return array
	 */
	protected function fetch_models(): array {
		$r = $this->http( 'GET', self::API . '/models', $this->headers() );
		if ( ! empty( $r['error'] ) || empty( $r['body']['data'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $r['body']['data'] as $m ) {
			$id = $m['id'];
			if ( ! preg_match( '/^(gpt-|o\d)/', $id ) || preg_match( '/(audio|realtime|transcribe|tts|image|search|embedding|moderation|instruct|codex)/', $id ) ) {
				continue;
			}
			$out[ $id ] = array( $id, (int) ( $m['created'] ?? 0 ), null, null );
		}
		uasort( $out, fn( $a, $b ) => $b[1] <=> $a[1] );
		return $out;
	}

	/**
	 * Default: newest general model (gpt-N or gpt-N.M without suffix).
	 *
	 * @param array $models Models.
	 * @return string
	 */
	protected static function pick_default( array $models ): string {
		foreach ( $models as $id => $m ) {
			if ( preg_match( '/^gpt-\d+(\.\d+)?$/', $id ) ) {
				return $id;
			}
		}
		return self::fallback_model();
	}

	/**
	 * Key valid?
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		$r = $this->http( 'GET', self::API . '/models', $this->headers() );
		return empty( $r['error'] ) && 200 === $r['code'];
	}

	/**
	 * Request body (OpenAI and OpenRouter share the format).
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	protected function body( string $system, string $user ) {
		return array(
			'model'                 => $this->model(),
			'messages'              => array(
				array(
					'role'    => 'system',
					'content' => $system,
				),
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'response_format'       => array(
				'type'        => 'json_schema',
				'json_schema' => array(
					'name'   => 'page_review',
					'strict' => true,
					'schema' => self::schema(),
				),
			),
			'max_completion_tokens' => 16000,
		);
	}

	/**
	 * Review request.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	protected function request( string $system, string $user ): array {
		$r = $this->http( 'POST', $this->api() . '/chat/completions', $this->headers(), $this->body( $system, $user ) );
		if ( ! empty( $r['error'] ) ) {
			return array(
				'error'     => $r['error'],
				'retryable' => ! empty( $r['retryable'] ),
			);
		}
		$choice = $r['body']['choices'][0] ?? array();
		if ( ! empty( $choice['message']['refusal'] ) ) {
			return array(
				'error'     => __( 'The model declined the request.', 'siteproof' ),
				'retryable' => false,
			);
		}
		if ( 'length' === ( $choice['finish_reason'] ?? '' ) ) {
			return array(
				'error'     => __( 'Answer was cut off (token limit).', 'siteproof' ),
				'retryable' => false,
			);
		}
		$data = self::parse_json( $choice['message']['content'] ?? '' );
		if ( ! $data ) {
			return array(
				'error'     => __( 'Answer is not valid JSON.', 'siteproof' ),
				'retryable' => true,
			);
		}
		$u = $r['body']['usage'] ?? array();
		return array(
			'data'  => $data,
			'usage' => array( (int) ( $u['prompt_tokens'] ?? 0 ), (int) ( $u['completion_tokens'] ?? 0 ) ),
			'model' => $r['body']['model'] ?? $this->model(),
		);
	}
}

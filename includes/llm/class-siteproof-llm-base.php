<?php
/**
 * Shared base for AI providers: HTTP with retry/backoff, JSON schema, model list cache, costs.
 * No SDKs - plain wp_remote_* calls.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Base class.
 */
abstract class Siteproof_LLM_Base implements Siteproof_LLM {

	const TIMEOUT     = 90;
	const MAX_RETRIES = 3;

	/** @var string API key */
	protected $key;
	/** @var string Model ID ('' = provider default) */
	protected $model;

	/**
	 * Constructor.
	 *
	 * @param string $key   API key.
	 * @param string $model Model ID.
	 */
	public function __construct( $key, $model = '' ) {
		$this->key   = (string) $key;
		$this->model = (string) $model;
	}

	/**
	 * Provider ID (anthropic|openai|openrouter).
	 *
	 * @return string
	 */
	abstract public static function id(): string;

	/**
	 * Fallback model if the model list cannot be loaded.
	 *
	 * @return string
	 */
	abstract public static function fallback_model(): string;

	/**
	 * Load the model list from the provider.
	 *
	 * @return array id => [label, created (unix), price_in, price_out] (prices per million tokens or null)
	 */
	abstract protected function fetch_models(): array;

	/**
	 * Pick the default model from a list.
	 *
	 * @param array $models Model list.
	 * @return string
	 */
	abstract protected static function pick_default( array $models ): string;

	/**
	 * Send the review request.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array { data: array, usage: [in, out] } or { error, retryable }
	 */
	abstract protected function request( string $system, string $user ): array;

	/**
	 * Model list (cached for a day per provider and key).
	 *
	 * @param bool $refresh Reload.
	 * @return array
	 */
	public function models( $refresh = false ) {
		$cache = 'siteproof_models_' . static::id() . '_' . substr( md5( $this->key ), 0, 8 );
		$list  = $refresh ? false : get_transient( $cache );
		if ( ! is_array( $list ) || ! $list ) {
			$list = $this->fetch_models();
			if ( $list ) {
				set_transient( $cache, $list, DAY_IN_SECONDS );
			}
		}
		return $list;
	}

	/**
	 * Model used for requests.
	 *
	 * @return string
	 */
	public function model() {
		if ( '' !== $this->model ) {
			return $this->model;
		}
		$models = $this->models();
		return $models ? static::pick_default( $models ) : static::fallback_model();
	}

	/**
	 * Review one page.
	 *
	 * @param array $segments Text segments.
	 * @param array $context  Page context.
	 * @return array
	 */
	public function review_page( array $segments, array $context ): array {
		$prompt = Siteproof_Review::prompt( $segments, $context );
		$res    = $this->request( $prompt['system'], $prompt['user'] );
		$res   += array(
			'provider' => static::id(),
			'model'    => $this->model(),
		);
		return $res;
	}

	/**
	 * Cost in USD or null without a stored price.
	 *
	 * @param int $input_tokens  Input tokens.
	 * @param int $output_tokens Output tokens.
	 * @return float|null
	 */
	public function estimate_cost( int $input_tokens, int $output_tokens ): ?float {
		$model  = $this->model();
		$prices = Siteproof_Settings::prices();
		if ( ! isset( $prices[ $model ] ) ) {
			$m = $this->models()[ $model ] ?? null;
			if ( $m && null !== $m[2] && null !== $m[3] ) {
				$prices[ $model ] = array( $m[2], $m[3] );
			} else {
				return null;
			}
		}
		return ( $input_tokens * $prices[ $model ][0] + $output_tokens * $prices[ $model ][1] ) / 1000000;
	}

	/**
	 * Rough token estimate for German/English text plus JSON (about 3.2 characters per token).
	 *
	 * @param string $text Text.
	 * @return int
	 */
	public static function estimate_tokens( $text ) {
		return (int) ceil( mb_strlen( (string) $text ) / 3.2 );
	}

	/**
	 * Shared response schema for all providers.
	 *
	 * @return array
	 */
	public static function schema() {
		$nullable = array(
			'anyOf' => array(
				array( 'type' => 'string' ),
				array( 'type' => 'null' ),
			),
		);
		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'required'             => array( 'proofreading', 'suggestions', 'speed' ),
			'properties'           => array(
				'proofreading' => array(
					'type'  => 'array',
					'items' => array(
						'type'                 => 'object',
						'additionalProperties' => false,
						'required'             => array( 'seg', 'error', 'suggestion', 'type', 'note' ),
						'properties'           => array(
							'seg'        => array( 'type' => 'string' ),
							'error'      => array( 'type' => 'string' ),
							'suggestion' => array( 'type' => 'string' ),
							'type'       => array(
								'type' => 'string',
								'enum' => array( 'spelling', 'punctuation', 'grammar', 'style' ),
							),
							'note'       => $nullable,
						),
					),
				),
				'suggestions'  => array(
					'type'                 => 'object',
					'additionalProperties' => false,
					'required'             => array( 'title', 'description', 'ogDescription', 'ogImage' ),
					'properties'           => array(
						'title'         => $nullable,
						'description'   => $nullable,
						'ogDescription' => $nullable,
						'ogImage'       => $nullable,
					),
				),
				'speed'        => $nullable,
			),
		);
	}

	/**
	 * HTTP request with retries on 429/5xx (backoff, honours retry-after up to 30 s).
	 *
	 * @param string     $method  GET|POST.
	 * @param string     $url     URL.
	 * @param array      $headers Headers.
	 * @param array|null $body    JSON body.
	 * @return array { code, body (decoded), error?, retryable? }
	 */
	protected function http( $method, $url, array $headers, $body = null ) {
		$args = array(
			'method'  => $method,
			'timeout' => self::TIMEOUT,
			'headers' => $headers + array( 'Content-Type' => 'application/json' ),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		for ( $attempt = 0; ; $attempt++ ) {
			$res = wp_remote_request( $url, $args );
			if ( is_wp_error( $res ) ) {
				$out = array(
					'code'      => 0,
					'body'      => null,
					'error'     => $res->get_error_message(),
					'retryable' => true,
				);
			} else {
				$code = (int) wp_remote_retrieve_response_code( $res );
				$json = json_decode( (string) wp_remote_retrieve_body( $res ), true );
				$out  = array(
					'code' => $code,
					'body' => $json,
				);
				if ( $code >= 400 ) {
					$msg              = is_array( $json ) ? ( $json['error']['message'] ?? ( is_string( $json['error'] ?? null ) ? $json['error'] : '' ) ) : '';
					$out['error']     = trim( 'HTTP ' . $code . ' ' . $msg );
					$out['retryable'] = 429 === $code || $code >= 500;
				}
			}
			if ( empty( $out['error'] ) || empty( $out['retryable'] ) || $attempt >= self::MAX_RETRIES ) {
				break;
			}
			$wait = 2 ** ( $attempt + 1 );
			if ( ! is_wp_error( $res ) ) {
				$after = (int) wp_remote_retrieve_header( $res, 'retry-after' );
				if ( $after > 0 ) {
					$wait = $after;
				}
			}
			sleep( min( 30, $wait ) );
		}
		if ( ! empty( $out['error'] ) && '' !== $this->key ) {
			$out['error'] = str_replace( $this->key, '***', $out['error'] );
		}
		return $out;
	}

	/**
	 * Decode the model's JSON answer.
	 *
	 * @param string $text Answer text.
	 * @return array|null
	 */
	protected static function parse_json( $text ) {
		$text = trim( (string) $text );
		// Tolerate code fences from models without strict structured output.
		$text = preg_replace( '/^```(?:json)?\s*|\s*```$/', '', $text );
		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : null;
	}
}

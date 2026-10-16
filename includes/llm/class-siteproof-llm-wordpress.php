<?php
/**
 * WordPress AI Client (WordPress 7.0+): uses the AI connections configured for the site
 * under Settings › Connectors. No key of its own; WordPress picks provider and model.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * WordPress AI Client provider.
 */
class Siteproof_LLM_WordPress extends Siteproof_LLM_Base {

	/**
	 * Provider ID.
	 *
	 * @return string
	 */
	public static function id(): string {
		return 'wordpress';
	}

	/**
	 * Is the AI Client available in this WordPress version and environment?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
	}

	/**
	 * WordPress chooses the model.
	 *
	 * @return string
	 */
	public static function fallback_model(): string {
		return 'auto';
	}

	/**
	 * No model list: the site's connections decide.
	 *
	 * @return array
	 */
	protected function fetch_models(): array {
		return array();
	}

	/**
	 * Default model.
	 *
	 * @param array $models Models.
	 * @return string
	 */
	protected static function pick_default( array $models ): string {
		return 'auto';
	}

	/**
	 * Is a connection configured that supports text generation?
	 *
	 * @return bool
	 */
	public function test_connection(): bool {
		if ( ! self::available() ) {
			return false;
		}
		$ok = wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
		return true === $ok;
	}

	/**
	 * Review request through the AI Client with JSON schema output.
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @return array
	 */
	protected function request( string $system, string $user ): array {
		if ( ! self::available() ) {
			return array(
				'error'     => __( 'The WordPress AI Client is not available.', 'siteproof' ),
				'retryable' => false,
			);
		}
		$result = wp_ai_client_prompt( $user )
			->using_system_instruction( $system )
			->using_max_tokens( 16000 )
			->as_json_response( self::schema() )
			->generate_text_result();
		if ( is_wp_error( $result ) ) {
			return array(
				'error'     => $result->get_error_message(),
				'retryable' => true,
			);
		}
		$data = self::parse_json( $result->toText() );
		if ( ! $data ) {
			return array(
				'error'     => __( 'Answer is not valid JSON.', 'siteproof' ),
				'retryable' => true,
			);
		}
		$usage = $result->getTokenUsage();
		return array(
			'data'  => $data,
			'usage' => array( $usage->getPromptTokens(), $usage->getCompletionTokens() ),
			'model' => $result->getProviderMetadata()->getId() . '/' . $result->getModelMetadata()->getId(),
		);
	}
}

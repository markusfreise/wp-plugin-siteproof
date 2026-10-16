<?php
/**
 * Encryption of API keys (sodium secretbox, key derived from the site's salts).
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Encrypt and decrypt.
 */
class Siteproof_Crypto {

	/**
	 * Key derived from the installation's salts.
	 *
	 * @return string
	 */
	private static function key() {
		return sodium_crypto_generichash( 'siteproof|' . wp_salt( 'auth' ), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt plain text.
	 *
	 * @param string $plain Plain text.
	 * @return string base64(nonce . cipher)
	 */
	public static function encrypt( $plain ) {
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext storage.
	}

	/**
	 * Decrypt; empty string if unreadable (e.g. salts changed).
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$raw = base64_decode( (string) $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext storage.
		if ( ! $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}
}

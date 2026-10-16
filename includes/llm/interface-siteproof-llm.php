<?php
/**
 * Gemeinsame Schnittstelle der KI-Anbieter.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * KI-Anbieter fuer Korrekturlesen und SEO-Vorschlaege.
 */
interface Siteproof_LLM {

	/**
	 * Eine Seite pruefen.
	 *
	 * @param array $segments Textsegmente: id, block, tag, text.
	 * @param array $context  Seite, SEO-Bedarf, Ladezeit, Bilder, Wortliste.
	 * @return array { proofreading, suggestions, speed, usage: {in, out}, model } oder { error, retryable }
	 */
	public function review_page( array $segments, array $context ): array;

	/**
	 * Schluessel gueltig?
	 *
	 * @return bool
	 */
	public function test_connection(): bool;

	/**
	 * Kosten in USD (null, wenn fuer das Modell kein Preis hinterlegt ist).
	 *
	 * @param int $input_tokens  Input-Token.
	 * @param int $output_tokens Output-Token.
	 * @return float|null
	 */
	public function estimate_cost( int $input_tokens, int $output_tokens ): ?float;
}

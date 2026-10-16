<?php
/**
 * AI review: prompt per page (proofreading, SEO suggestions, one sentence on load time),
 * answer validation and cost estimate.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Review helper.
 */
class Siteproof_Review {

	/**
	 * System prompt (the rules apply to every page).
	 *
	 * @param string $lang Content language (e.g. de_DE).
	 * @return string
	 */
	public static function system( $lang ) {
		return "You proofread website content and suggest SEO fields. Content language: {$lang}. Write every suggestion, note and the speed sentence in the content language.\n\n"
			. "Rules:\n"
			. "- Only real errors: spelling, punctuation (commas, quotation marks, dashes, spaces), grammar. Style only for clear defects (duplicated words, broken sentences, placeholder text such as \"Lorem ipsum\", \"Text zu ...\", \"Hier kommt ...\").\n"
			. "- \"error\" must appear verbatim in the segment text and be as short as possible (a word or phrase); \"suggestion\" replaces exactly that excerpt. \"seg\" is the segment ID (e.g. s12).\n"
			. "- Do not correct proper names, brands, quotes in their original spelling, deliberately colloquial forms or gender-inclusive language (e.g. Trainer:innen, Teilnehmende). Words from the word list are always correct.\n"
			. "- Missing spaces between lines (\"werden.Prävention\") may come from line breaks in the layout; only report them if the text is really joined in the block.\n"
			. "- Placeholder or copy-paste texts: report with type \"style\" and a note starting with \"Placeholder\".\n"
			. "- SEO suggestions only for the fields listed under \"SEO suggestions needed\", otherwise null: title <= 60 characters, description 140-155 characters, in the tone of the page, containing the main term. ogImage: absolute URL of an image from the image list.\n"
			. "- speed: one sentence assessing the load time with the main cause, or null if not measured.\n"
			. '- If there is nothing to report, return an empty proofreading list.';
	}

	/**
	 * Prompt for one page.
	 *
	 * @param array $segments Segments: id, block, tag, text.
	 * @param array $context  title, url, need[], seo{}, speed (text), images[], wordlist[], lang.
	 * @return array { system, user }
	 */
	public static function prompt( array $segments, array $context ) {
		$seo   = $context['seo'] ?? array();
		$lines = array();
		foreach ( $segments as $s ) {
			$lines[] = sprintf( '[%s] (%s, %s) %s', $s['id'], $s['block'], $s['tag'], $s['text'] );
		}
		$need = $context['need'] ?? array();
		$user = sprintf( "# Page: %s\nURL: %s\n\n", $context['title'] ?? '', $context['url'] ?? '' )
			. "## SEO suggestions needed\n" . ( $need ? '- ' . implode( "\n- ", $need ) : '- none' ) . "\n"
			. sprintf( "Current: title \"%s\" | description \"%s\" | og:image %s\n\n", $seo['title'] ?? '', $seo['description'] ?? '', $seo['ogImage'] ?? '-' )
			. "## Load time\n" . ( $context['speed'] ?? 'not measured' ) . "\n\n"
			. "## Images (for ogImage)\n" . ( empty( $context['images'] ) ? '- none' : '- ' . implode( "\n- ", $context['images'] ) ) . "\n\n"
			. "## Word list (always correct)\n" . ( empty( $context['wordlist'] ) ? '- none' : implode( ', ', $context['wordlist'] ) ) . "\n\n"
			. "## Text segments\n" . implode( "\n", $lines );
		return array(
			'system' => self::system( $context['lang'] ?? get_locale() ),
			'user'   => $user,
		);
	}

	/**
	 * Build the context for a page from the collected data.
	 *
	 * @param array $page     Page row.
	 * @param array $site     Site info.
	 * @param array $wordlist Word list.
	 * @return array
	 */
	public static function context( array $page, array $site, array $wordlist ) {
		$s    = $page['server']['seo'] ?? array();
		$st   = $page['server']['seoStored'] ?? array();
		$need = array();
		if ( empty( $s['title'] ) ) {
			$need[] = 'title (missing entirely)';
		}
		if ( empty( $st['description'] ) ) {
			$need[] = empty( $s['description'] ) ? 'description (not maintained, missing in the HTML)' : sprintf( 'description (not maintained, generated automatically: "%s")', $s['description'] );
		}
		if ( empty( $s['ogDescription'] ) ) {
			$need[] = 'ogDescription (missing)';
		}
		$def_og = ! empty( $site['defaultOgImage'] ) ? WP_Http::make_absolute_url( $site['defaultOgImage'], $site['home'] . '/' ) : null;
		if ( empty( $s['ogImage'] ) || ( $def_og && $s['ogImage'] === $def_og ) ) {
			$need[] = 'ogImage (missing or default image) - choose the URL of a fitting content image';
		}
		$images = array();
		foreach ( self::images( $page ) as $img ) {
			if ( empty( $img['background'] ) && ( $img['renderedWidth'] ?? 999 ) > 200 && 'content' === ( $img['region'] ?? 'content' ) ) {
				$images[] = $img['src'];
			}
		}
		return array(
			'title'    => $page['title'],
			'url'      => $page['url'],
			'need'     => $need,
			'seo'      => $s,
			'speed'    => self::speed_summary( $page ),
			'images'   => array_slice( array_values( array_unique( $images ) ), 0, 8 ),
			'wordlist' => $wordlist,
			'lang'     => get_locale(),
		);
	}

	/**
	 * Images of a page: browser data if present, otherwise from the HTML.
	 *
	 * @param array $page Page row.
	 * @return array
	 */
	public static function images( array $page ) {
		return $page['browser']['mobile']['images'] ?? ( $page['server']['images'] ?? array() );
	}

	/**
	 * Short load-time summary for the prompt.
	 *
	 * @param array $page Page row.
	 * @return string
	 */
	public static function speed_summary( array $page ) {
		$sp = ! empty( $page['psi'] ) && empty( $page['psi']['error'] ) ? $page['psi'] : ( $page['browser']['mobile']['timing'] ?? null );
		if ( ! $sp ) {
			return 'not measured';
		}
		$b    = $sp['bytes'] ?? array();
		$mb   = fn( $v ) => number_format( ( (float) $v ) / 1048576, 1 ) . ' MB';
		$kb   = fn( $v ) => round( ( (float) $v ) / 1024 ) . ' KB';
		$sec  = fn( $v ) => null === $v ? '-' : number_format( $v / 1000, 1 ) . ' s';
		$how  = ( $sp['source'] ?? '' ) === 'psi' ? 'PageSpeed Insights mobile' : 'local, unthrottled';
		$text = sprintf( '%s: FCP %s, LCP %s, CLS %s, total %s (images %s, JS %s, CSS %s, fonts %s)', $how, $sec( $sp['fcp'] ?? null ), $sec( $sp['lcp'] ?? null ), number_format( (float) ( $sp['cls'] ?? 0 ), 3 ), $mb( $b['total'] ?? 0 ), $mb( $b['image'] ?? 0 ), $kb( $b['script'] ?? 0 ), $kb( $b['stylesheet'] ?? 0 ), $kb( $b['font'] ?? 0 ) );
		$heavy = array();
		foreach ( self::images( $page ) as $img ) {
			$bytes = $img['bytes'] ?? ( $img['file']['bytes'] ?? 0 );
			if ( $bytes ) {
				$heavy[] = array( $bytes, sprintf( '%s %d KB, rendered %dpx, delivered %spx', Siteproof_Collector::file_name( $img['src'] ), round( $bytes / 1024 ), $img['renderedWidth'] ?? 0, $img['naturalWidth'] ?? '?' ) );
			}
		}
		usort( $heavy, fn( $a, $b ) => $b[0] <=> $a[0] );
		if ( $heavy ) {
			$text .= "\nHeaviest images: " . implode( ' | ', array_column( array_slice( $heavy, 0, 5 ), 1 ) );
		}
		return $text;
	}

	/**
	 * Validate the answer: "error" must appear verbatim in the segment, otherwise discard and count.
	 *
	 * @param array $data     Model answer.
	 * @param array $segments Segments.
	 * @return array { proofreading, suggestions, speed, discarded }
	 */
	public static function validate( array $data, array $segments ) {
		$by_id = array();
		foreach ( $segments as $s ) {
			$by_id[ $s['id'] ] = $s['text'];
		}
		$ok        = array();
		$discarded = 0;
		$types     = array( 'spelling', 'punctuation', 'grammar', 'style' );
		foreach ( (array) ( $data['proofreading'] ?? array() ) as $f ) {
			$seg   = (string) ( $f['seg'] ?? '' );
			$error = (string) ( $f['error'] ?? '' );
			if ( '' === $error || ! isset( $by_id[ $seg ] ) || ! str_contains( $by_id[ $seg ], $error ) || $error === ( $f['suggestion'] ?? '' ) ) {
				++$discarded;
				continue;
			}
			$ok[] = array(
				'seg'        => $seg,
				'error'      => $error,
				'suggestion' => (string) ( $f['suggestion'] ?? '' ),
				'type'       => in_array( $f['type'] ?? '', $types, true ) ? $f['type'] : 'style',
				'note'       => isset( $f['note'] ) ? (string) $f['note'] : null,
			);
		}
		$sug = (array) ( $data['suggestions'] ?? array() );
		return array(
			'proofreading' => $ok,
			'suggestions'  => array(
				'title'         => $sug['title'] ?? null,
				'description'   => $sug['description'] ?? null,
				'ogDescription' => $sug['ogDescription'] ?? null,
				'ogImage'       => $sug['ogImage'] ?? null,
			),
			'speed'        => isset( $data['speed'] ) ? (string) $data['speed'] : null,
			'discarded'    => $discarded,
		);
	}

	/**
	 * Token and cost estimate for all pages of a run, per configured provider.
	 *
	 * @param array $pages Page rows with server data.
	 * @param array $site  Site info.
	 * @return array provider => { model, in, out, cost|null, configured }
	 */
	public static function estimate( array $pages, array $site ) {
		$wordlist = Siteproof_Settings::wordlist();
		$in       = 0;
		$out      = 0;
		foreach ( $pages as $p ) {
			if ( empty( $p['server'] ) || ! empty( $p['server']['error'] ) ) {
				continue;
			}
			$prompt  = self::prompt( $p['server']['segments'] ?? array(), self::context( $p, $site, $wordlist ) );
			$tokens  = Siteproof_LLM_Base::estimate_tokens( $prompt['system'] . $prompt['user'] );
			$in     += $tokens;
			// Answer plus reasoning: about a fifth of the input, at least 600 tokens per page.
			$out += max( 600, (int) ( $tokens * 0.2 ) ) * 2;
		}
		$result = array();
		foreach ( array_keys( Siteproof_Settings::providers() ) as $provider ) {
			$llm                 = Siteproof_Settings::llm( $provider );
			$result[ $provider ] = array(
				'configured' => (bool) $llm,
				'model'      => $llm ? $llm->model() : null,
				'in'         => $in,
				'out'        => $out,
				'cost'       => $llm ? $llm->estimate_cost( $in, $out ) : null,
			);
		}
		return $result;
	}
}

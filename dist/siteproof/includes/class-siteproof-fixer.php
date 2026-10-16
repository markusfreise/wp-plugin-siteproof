<?php
/**
 * Fixes from the report: alt texts (attachment + img tags of the page) and proofreading
 * corrections in the page content (HTML and block attribute JSON).
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content fixer.
 */
class Siteproof_Fixer {

	/**
	 * Save an alt text to the attachment and to img tags of the page whose alt is empty.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $alt           Alt text.
	 * @param int    $post_id       Page to update (0 = attachment only).
	 * @return array { ok, message, inContent }
	 */
	public static function alt( $attachment_id, $alt, $post_id = 0 ) {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
		$count = 0;
		if ( $post_id ) {
			$post = get_post( $post_id );
			if ( $post && str_contains( $post->post_content, 'wp-image-' . $attachment_id ) ) {
				$p = new WP_HTML_Tag_Processor( $post->post_content );
				while ( $p->next_tag( 'img' ) ) {
					$classes = ' ' . (string) $p->get_attribute( 'class' ) . ' ';
					$current = $p->get_attribute( 'alt' );
					if ( str_contains( $classes, ' wp-image-' . $attachment_id . ' ' ) && ( null === $current || true === $current || '' === trim( (string) $current ) ) ) {
						$p->set_attribute( 'alt', $alt );
						++$count;
					}
				}
				if ( $count ) {
					self::save_content( $post_id, $p->get_updated_html() );
				}
			}
		}
		return array(
			'ok'        => true,
			'inContent' => $count,
			'message'   => $count
				? sprintf(
					/* translators: %d: number of images */
					_n( 'Saved to the media library and to %d image on this page.', 'Saved to the media library and to %d images on this page.', $count, 'siteproof' ),
					$count
				)
				: __( 'Saved to the media library.', 'siteproof' ),
		);
	}

	/**
	 * Apply a correction to the page content.
	 *
	 * Rendered text differs from stored content (entities, typographic quotes and dashes,
	 * block attributes as JSON), so the search is tolerant. Only unambiguous hits are replaced:
	 * first the error with its surrounding words, then the error alone if it occurs once.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $error      Faulty excerpt (as rendered).
	 * @param string $suggestion Replacement.
	 * @param string $segment    Full segment text (context).
	 * @return array|WP_Error
	 */
	public static function text( $post_id, $error, $suggestion, $segment ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new WP_Error( 'siteproof_post', __( 'Page not found.', 'siteproof' ), array( 'status' => 404 ) );
		}
		$content = $post->post_content;
		$pos     = '' === $segment ? false : mb_strpos( $segment, $error );
		$tries   = array();
		if ( false !== $pos ) {
			// Error with up to three words before and after, then with one word.
			foreach ( array( 3, 1 ) as $words ) {
				$before = implode( ' ', array_slice( preg_split( '/\s+/u', trim( mb_substr( $segment, 0, $pos ) ) ), -$words ) );
				$after  = implode( ' ', array_slice( preg_split( '/\s+/u', trim( mb_substr( $segment, $pos + mb_strlen( $error ) ) ) ), 0, $words ) );
				$b      = '' === $before ? '' : $before . ( preg_match( '/\s$/u', mb_substr( $segment, 0, $pos ) ) ? ' ' : '' );
				$a      = '' === $after ? '' : ( preg_match( '/^\s/u', mb_substr( $segment, $pos + mb_strlen( $error ) ) ) ? ' ' : '' ) . $after;
				if ( '' !== $b . $a ) {
					$tries[] = array( $b, $a );
				}
			}
		}
		$tries[] = array( '', '' );

		foreach ( $tries as $t ) {
			$regex = '/(' . self::pattern( $t[0] ) . ')(' . self::pattern( $error ) . ')(' . self::pattern( $t[1] ) . ')/u';
			if ( ! preg_match_all( $regex, $content, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER ) ) {
				continue;
			}
			$html = array();
			$json = array();
			foreach ( $m as $hit ) {
				if ( self::in_block_comment( $content, $hit[0][1] ) ) {
					$json[] = $hit;
				} else {
					$html[] = $hit;
				}
			}
			if ( count( $html ) > 1 || count( $json ) > 1 || ! $html && ! $json ) {
				continue;
			}
			// Replace from the end so that offsets stay valid.
			$hits = array_merge( $html, $json );
			usort( $hits, fn( $x, $y ) => $y[2][1] <=> $x[2][1] );
			foreach ( $hits as $hit ) {
				$is_json = self::in_block_comment( $content, $hit[0][1] );
				$rep     = $is_json ? self::json_text( $suggestion ) : htmlspecialchars( $suggestion, ENT_NOQUOTES, 'UTF-8' );
				$content = substr_replace( $content, $rep, $hit[2][1], strlen( $hit[2][0] ) );
			}
			self::save_content( $post_id, $content );
			return array(
				'ok'      => true,
				'message' => __( 'Correction saved to the page (a revision was created).', 'siteproof' ),
			);
		}
		return new WP_Error(
			'siteproof_ambiguous',
			__( 'The text was not found exactly once in the page content (it may span formatting, come from a template, reusable block or custom field, or occur several times). Please correct it in the editor.', 'siteproof' ),
			array(
				'status' => 409,
				'edit'   => get_edit_post_link( $post_id, 'raw' ),
			)
		);
	}

	/**
	 * Tolerant regex for rendered text: entities, typographic quotes/dashes, JSON escapes.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function pattern( $text ) {
		$out   = '';
		$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$space = false;
		foreach ( $chars as $c ) {
			if ( preg_match( '/\s|\x{00a0}/u', $c ) ) {
				if ( ! $space ) {
					$out .= '(?:\s|&nbsp;|&#160;|\x{00a0}|\\\\u00a0)+';
				}
				$space = true;
				continue;
			}
			$space = false;
			$out  .= self::char_pattern( $c );
		}
		return $out;
	}

	/**
	 * Alternatives for one character.
	 *
	 * @param string $c Character.
	 * @return string
	 */
	private static function char_pattern( $c ) {
		$alt = array( preg_quote( $c, '/' ) );
		if ( in_array( $c, array( '"', '„', '“', '”', '«', '»' ), true ) ) {
			$alt = array( '"', '„', '“', '”', '«', '»', '&quot;', '&#8222;', '&#8220;', '&#8221;', '&bdquo;', '&ldquo;', '&rdquo;', '\\\\u0022', '\\\\"' );
		} elseif ( in_array( $c, array( "'", '‚', '‘', '’' ), true ) ) {
			$alt = array( "'", '‚', '‘', '’', '&#8217;', '&#8216;', '&#8218;', '&#039;', '&#39;', '&rsquo;', '&lsquo;', '\\\\u0027' );
		} elseif ( in_array( $c, array( '-', '–', '—' ), true ) ) {
			$alt = array( '-', '–', '—', '&#8211;', '&#8212;', '&ndash;', '&mdash;', '\\\\u002d', '\\\\u2013', '\\\\u2014' );
		} elseif ( '&' === $c ) {
			$alt = array( '&', '&amp;', '&#038;', '\\\\u0026' );
		} elseif ( '<' === $c ) {
			$alt = array( '&lt;', '\\\\u003c' );
		} elseif ( '>' === $c ) {
			$alt = array( '&gt;', '\\\\u003e' );
		} elseif ( '…' === $c ) {
			$alt = array( '…', '&#8230;', '&hellip;', '\\.\\.\\.', '\\\\u2026' );
		} elseif ( strlen( $c ) > 1 ) {
			$cp    = mb_ord( $c, 'UTF-8' );
			$alt[] = sprintf( '\\\\u%04x', $cp );
			$alt[] = sprintf( '&#%d;', $cp );
		}
		return '(?:' . implode( '|', $alt ) . ')';
	}

	/**
	 * Is the offset inside a block comment (<!-- wp:... {json} -->)?
	 *
	 * @param string $content Content.
	 * @param int    $offset  Byte offset.
	 * @return bool
	 */
	private static function in_block_comment( $content, $offset ) {
		$open = strrpos( substr( $content, 0, $offset ), '<!--' );
		if ( false === $open ) {
			return false;
		}
		$close = strpos( $content, '-->', $open );
		return false !== $close && $close > $offset;
	}

	/**
	 * Text as it appears inside a block attribute JSON string (like serialize_block_attributes()).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function json_text( $text ) {
		$json = substr( (string) wp_json_encode( $text, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 1, -1 );
		return strtr(
			$json,
			array(
				'--' => '\\u002d\\u002d',
				'<'  => '\\u003c',
				'>'  => '\\u003e',
				'&'  => '\\u0026',
				'\\"' => '\\u0022',
			)
		);
	}

	/**
	 * Save content with a revision, without kses touching existing markup.
	 * Only text is inserted (escaped), so bypassing kses for this update is safe.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $content New content.
	 */
	private static function save_content( $post_id, $content ) {
		$kses = has_filter( 'content_save_pre', 'wp_filter_post_kses' );
		if ( $kses ) {
			kses_remove_filters();
		}
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_slash( $content ),
			)
		);
		if ( $kses ) {
			kses_init_filters();
		}
	}
}

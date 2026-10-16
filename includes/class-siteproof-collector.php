<?php
/**
 * Server collector: loads every page via loopback (as an anonymous visitor) and extracts
 * SEO fields, headings with block, text segments, links, images and media from the HTML.
 * Blocks are identified via wp-block-* classes from the block registry.
 *
 * @package Siteproof
 */

defined( 'ABSPATH' ) || exit;

/**
 * Collector.
 */
class Siteproof_Collector {

	/** Wrapper blocks; the nearest meaningful block is used instead. */
	const SKIP = array( 'core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/column', 'core/columns', 'core/buttons', 'core/button', 'core/image', 'core/spacer' );

	/** Elements that innerText separates (whitespace at the boundaries). */
	const BLOCK_TAGS = array( 'address', 'article', 'aside', 'blockquote', 'dd', 'details', 'div', 'dl', 'dt', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'li', 'main', 'nav', 'ol', 'p', 'pre', 'section', 'summary', 'table', 'td', 'th', 'tr', 'ul', 'button', 'option' );

	/** @var DOMXPath */
	private $xp;
	/** @var array CSS class => block name */
	private $block_class;
	/** @var array node path => running number */
	private $block_index = array();
	/** @var array block name => counter */
	private $counters = array();
	/** @var string base URL for relative links */
	private $base;

	/**
	 * Block class map: core/paragraph -> wp-block-paragraph, acme/hero -> wp-block-acme-hero.
	 *
	 * @return array
	 */
	public static function block_class_map() {
		$map = array();
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $name => $type ) {
			$parts = explode( '/', $name, 2 );
			$ns    = $parts[0];
			$slug  = $parts[1] ?? '';
			$map[ 'core' === $ns ? "wp-block-$slug" : "wp-block-$ns-$slug" ] = $name;
		}
		return $map;
	}

	/**
	 * Site information for a run.
	 *
	 * @return array
	 */
	public static function site_info() {
		$titles = defined( 'RANK_MATH_VERSION' ) ? (array) get_option( 'rank-math-options-titles', array() ) : array();
		$def_og = $titles['open_graph_image'] ?? null;
		if ( ! $def_og && defined( 'WPSEO_VERSION' ) ) {
			$social = (array) get_option( 'wpseo_social', array() );
			$def_og = $social['og_default_image'] ?? null;
		}
		return array(
			'name'           => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
			'home'           => untrailingslashit( home_url() ),
			'admin'          => untrailingslashit( admin_url() ),
			'public'         => (int) get_option( 'blog_public' ),
			'seoPlugin'      => defined( 'RANK_MATH_VERSION' ) ? 'rank-math' : ( defined( 'WPSEO_VERSION' ) ? 'yoast' : null ),
			'defaultOgImage' => $def_og,
		);
	}

	/**
	 * Page list for a run.
	 *
	 * @param string[] $types Post types.
	 * @param int[]    $ids   Optional: only these IDs.
	 * @return array[] post_id, url, titel
	 */
	public static function page_list( array $types, array $ids = array() ) {
		$q = array(
			'post_type'        => $types ? $types : array( 'page' ),
			'post_status'      => 'publish',
			'posts_per_page'   => -1,
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'has_password'     => false,
			'no_found_rows'    => true,
		);
		if ( $ids ) {
			$q['post__in'] = $ids;
		}
		$out = array();
		foreach ( get_posts( $q ) as $p ) {
			$url = get_permalink( $p );
			if ( ! $url || Siteproof_Settings::excluded( $url ) ) {
				continue;
			}
			$out[] = array(
				'post_id' => $p->ID,
				'url'     => $url,
				'title'   => html_entity_decode( get_the_title( $p ), ENT_QUOTES, 'UTF-8' ),
			);
		}
		return $out;
	}

	/**
	 * Stored SEO fields (Rank Math / Yoast) to tell custom / template / missing apart.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public static function seo_stored( $post_id ) {
		$rm     = defined( 'RANK_MATH_VERSION' );
		$yoast  = defined( 'WPSEO_VERSION' );
		$m      = fn( $k ) => (string) get_post_meta( $post_id, $k, true );
		$titles = $rm ? (array) get_option( 'rank-math-options-titles', array() ) : array();
		$type   = get_post_type( $post_id );
		return array(
			'plugin'        => $rm ? 'rank-math' : ( $yoast ? 'yoast' : null ),
			'title'         => $rm ? $m( 'rank_math_title' ) : ( $yoast ? $m( '_yoast_wpseo_title' ) : '' ),
			'description'   => $rm ? $m( 'rank_math_description' ) : ( $yoast ? $m( '_yoast_wpseo_metadesc' ) : '' ),
			'ogTitle'       => $rm ? $m( 'rank_math_facebook_title' ) : ( $yoast ? $m( '_yoast_wpseo_opengraph-title' ) : '' ),
			'ogDescription' => $rm ? $m( 'rank_math_facebook_description' ) : ( $yoast ? $m( '_yoast_wpseo_opengraph-description' ) : '' ),
			'ogImage'       => $rm ? $m( 'rank_math_facebook_image' ) : ( $yoast ? $m( '_yoast_wpseo_opengraph-image' ) : '' ),
			'focusKeyword'  => $rm ? $m( 'rank_math_focus_keyword' ) : ( $yoast ? $m( '_yoast_wpseo_focuskw' ) : '' ),
			'titleTemplate' => $titles[ 'pt_' . $type . '_title' ] ?? null,
			'descTemplate'  => $titles[ 'pt_' . $type . '_description' ] ?? null,
		);
	}

	/**
	 * Collect one page.
	 *
	 * @param string $url         URL.
	 * @param int    $post_id     Post ID.
	 * @param array  $block_class Block class map.
	 * @return array
	 */
	public static function collect( $url, $post_id, array $block_class ) {
		$start = microtime( true );
		$res   = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter for loopback requests.
				'user-agent'  => 'Mozilla/5.0 (compatible; Siteproof/' . SITEPROOF_VERSION . ')',
				'headers'     => array( 'Accept-Language' => 'de-DE,de;q=0.9' ),
			)
		);
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $res ) ) {
			return array( 'error' => $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$html = (string) wp_remote_retrieve_body( $res );
		if ( $code >= 400 || '' === $html ) {
			return array(
				'status' => $code,
				'error'  => 'HTTP ' . $code,
			);
		}
		$c        = new self();
		$data     = $c->parse( $html, $url, $block_class );
		$data    += array(
			'status'    => $code,
			'finalUrl'  => $url,
			'serverMs'  => $ms,
			'htmlBytes' => strlen( $html ),
		);
		$data['seoStored'] = self::seo_stored( $post_id );
		$data['images']    = self::resolve_images( $data['images'] );
		return $data;
	}

	/**
	 * Parse HTML.
	 *
	 * @param string $html        HTML.
	 * @param string $url         Page URL.
	 * @param array  $block_class Block class map.
	 * @return array
	 */
	public function parse( $html, $url, array $block_class ) {
		$this->block_class = $block_class;
		$this->base        = $url;
		$doc               = new DOMDocument();
		$prev              = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_COMPACT );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		$this->xp = new DOMXPath( $doc );

		// Number blocks in DOM order.
		foreach ( $this->xp->query( '//*[contains(@class, "wp-block-")]' ) as $n ) {
			$name = $this->block_name( $n );
			if ( $name ) {
				$this->label( $name, $n );
			}
		}

		return array(
			'seo'      => $this->seo(),
			'headings' => $this->headings(),
			'segments' => $this->segments(),
			'ids'      => $this->ids(),
			'links'    => $this->links(),
			'images'   => $this->images(),
			'media'    => $this->media(),
		);
	}

	// ------------------------------------------------------------------ Helpers

	/**
	 * Value of a meta tag (name or property).
	 *
	 * @param string $n Name.
	 * @return string|null
	 */
	private function meta( $n ) {
		foreach ( array( 'name', 'property' ) as $attr ) {
			$el = $this->xp->query( sprintf( '//meta[@%s="%s"]', $attr, $n ) )->item( 0 );
			if ( $el ) {
				return $el->getAttribute( 'content' );
			}
		}
		return null;
	}

	/**
	 * Class list.
	 *
	 * @param DOMElement $el Element.
	 * @return string[]
	 */
	private static function classes( $el ) {
		return preg_split( '/\s+/', trim( (string) $el->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );
	}

	/**
	 * Block name of an element.
	 *
	 * @param DOMElement $el Element.
	 * @return string|null
	 */
	private function block_name( $el ) {
		foreach ( self::classes( $el ) as $c ) {
			if ( isset( $this->block_class[ $c ] ) ) {
				return $this->block_class[ $c ];
			}
			if ( ! $this->block_class && str_starts_with( $c, 'wp-block-' ) ) {
				return substr( $c, 9 );
			}
		}
		return null;
	}

	/**
	 * "name #n" - nth occurrence of the block on the page.
	 *
	 * @param string     $name Block name.
	 * @param DOMElement $n    Element.
	 * @return string
	 */
	private function label( $name, $n ) {
		$path = $n->getNodePath();
		if ( ! isset( $this->block_index[ $path ] ) ) {
			$this->counters[ $name ]    = ( $this->counters[ $name ] ?? 0 ) + 1;
			$this->block_index[ $path ] = $this->counters[ $name ];
		}
		return $name . ' #' . $this->block_index[ $path ];
	}

	/**
	 * Region: content | header | footer | template.
	 *
	 * @param DOMNode $el Element.
	 * @return string
	 */
	private function region( $el ) {
		$header = false;
		$footer = false;
		for ( $n = $el; $n instanceof DOMElement; $n = $n->parentNode ) {
			$tag  = strtolower( $n->nodeName );
			$role = $n->getAttribute( 'role' );
			if ( 'main' === $tag || 'main' === $role ) {
				return 'content';
			}
			if ( 'header' === $tag || 'header' === $n->getAttribute( 'id' ) || 'banner' === $role ) {
				$header = true;
			}
			if ( 'footer' === $tag || 'contentinfo' === $role ) {
				$footer = true;
			}
		}
		return $header ? 'header' : ( $footer ? 'footer' : 'template' );
	}

	/**
	 * Nearest meaningful block; otherwise section/landmark or region.
	 *
	 * @param DOMNode $el   Element.
	 * @param array   $skip Wrapper blocks.
	 * @return string
	 */
	private function block_of( $el, array $skip = self::SKIP ) {
		$first = null;
		for ( $n = $el; $n instanceof DOMElement && 'body' !== strtolower( $n->nodeName ); $n = $n->parentNode ) {
			$name = $this->block_name( $n );
			if ( $name ) {
				if ( ! $first ) {
					$first = array( $name, $n );
				}
				if ( ! in_array( $name, $skip, true ) ) {
					return $this->label( $name, $n );
				}
			}
		}
		if ( $first ) {
			return $this->label( $first[0], $first[1] );
		}
		for ( $n = $el; $n instanceof DOMElement; $n = $n->parentNode ) {
			$tag = strtolower( $n->nodeName );
			if ( ( 'section' === $tag && ( $n->getAttribute( 'id' ) || $n->getAttribute( 'class' ) ) ) || in_array( $tag, array( 'article', 'aside', 'nav' ), true ) || 'region' === $n->getAttribute( 'role' ) ) {
				if ( $n->getAttribute( 'id' ) ) {
					return '#' . $n->getAttribute( 'id' );
				}
				return $tag . ( self::classes( $n ) ? '.' . implode( '.', array_slice( self::classes( $n ), 0, 2 ) ) : '' );
			}
		}
		$r = $this->region( $el );
		return 'content' === $r ? __( 'Content', 'siteproof' ) : $r;
	}

	/**
	 * Text like innerText (br and block boundaries become spaces).
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	public static function text( $node ) {
		return trim( preg_replace( '/\s+/u', ' ', self::text_raw( $node ) ) );
	}

	/**
	 * Raw text, recursive.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private static function text_raw( $node ) {
		if ( XML_TEXT_NODE === $node->nodeType || XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return $node->nodeValue;
		}
		if ( XML_ELEMENT_NODE !== $node->nodeType ) {
			return '';
		}
		$tag = strtolower( $node->nodeName );
		if ( in_array( $tag, array( 'script', 'style', 'noscript', 'template', 'svg' ), true ) ) {
			return '';
		}
		if ( 'br' === $tag ) {
			return ' ';
		}
		$s = '';
		foreach ( $node->childNodes as $child ) {
			$part = self::text_raw( $child );
			// Elements that CSS shows as separate lines ("Träger<span>Ein ...") would run together
			// without layout information: separate lowercase/punctuation followed by uppercase.
			if ( XML_ELEMENT_NODE === $child->nodeType && preg_match( '/[\p{Ll}\p{N}.,:;!?)]$/u', $s ) && preg_match( '/^\p{Lu}/u', $part ) ) {
				$s .= ' ';
			}
			$s .= $part;
		}
		return in_array( $tag, self::BLOCK_TAGS, true ) ? " $s " : $s;
	}

	/**
	 * Absolute URL.
	 *
	 * @param string $href Value.
	 * @return string
	 */
	private function abs( $href ) {
		$href = trim( html_entity_decode( (string) $href, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $href ) {
			return '';
		}
		if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) ) {
			return $href;
		}
		if ( str_starts_with( $href, '#' ) ) {
			return strtok( $this->base, '#' ) . $href;
		}
		return WP_Http::make_absolute_url( $href, $this->base );
	}

	/**
	 * Inside an element matching the condition?
	 *
	 * @param DOMNode $el      Element.
	 * @param string  $xpath   Ancestor condition.
	 * @return bool
	 */
	private function inside( $el, $xpath ) {
		return $this->xp->query( 'ancestor-or-self::*[' . $xpath . ']', $el )->length > 0;
	}

	/**
	 * XPath condition "has class".
	 *
	 * @param string $c Class.
	 * @return string
	 */
	private static function has_class( $c ) {
		return 'contains(concat(" ", normalize-space(@class), " "), " ' . $c . ' ")';
	}

	// ------------------------------------------------------------------ Extraction

	/**
	 * SEO fields.
	 *
	 * @return array
	 */
	private function seo() {
		$title  = $this->xp->query( '//title' )->item( 0 );
		$canon  = $this->xp->query( '//link[@rel="canonical"]' )->item( 0 );
		$html   = $this->xp->query( '//html' )->item( 0 );
		$json   = array();
		foreach ( $this->xp->query( '//script[@type="application/ld+json"]' ) as $s ) {
			$d = json_decode( $s->textContent, true );
			if ( ! is_array( $d ) ) {
				$json[] = 'INVALID';
				continue;
			}
			foreach ( $d['@graph'] ?? array( $d ) as $x ) {
				foreach ( (array) ( $x['@type'] ?? array() ) as $t ) {
					$json[] = $t;
				}
			}
		}
		$t = $title ? trim( preg_replace( '/\s+/u', ' ', $title->textContent ) ) : '';
		return array(
			'title'         => '' === $t ? null : html_entity_decode( $t, ENT_QUOTES, 'UTF-8' ),
			'description'   => $this->meta( 'description' ),
			'robots'        => $this->meta( 'robots' ),
			'canonical'     => $canon ? $this->abs( $canon->getAttribute( 'href' ) ) : null,
			'ogTitle'       => $this->meta( 'og:title' ),
			'ogDescription' => $this->meta( 'og:description' ),
			'ogImage'       => $this->meta( 'og:image' ),
			'ogUrl'         => $this->meta( 'og:url' ),
			'lang'          => $html && $html->getAttribute( 'lang' ) ? $html->getAttribute( 'lang' ) : null,
			'jsonLd'        => $json,
		);
	}

	/**
	 * Headings.
	 *
	 * @return array
	 */
	private function headings() {
		$out = array();
		foreach ( $this->xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' ) as $h ) {
			$text = mb_substr( self::text( $h ), 0, 160 );
			if ( '' === $text ) {
				continue;
			}
			$out[] = array(
				'level'  => (int) substr( $h->nodeName, 1 ),
				'text'   => $text,
				'block'  => $this->block_of( $h ),
				'region' => $this->region( $h ),
			);
		}
		return $out;
	}

	/**
	 * Text segments in the content area (leaf nodes, duplicates removed).
	 *
	 * @return array
	 */
	private function segments() {
		$tags = array( 'p', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figcaption', 'td', 'th', 'dt', 'dd', 'summary', 'blockquote', 'button' );
		$self = implode( ' or ', array_map( fn( $t ) => "self::$t", $tags ) )
			. ' or self::a[' . self::has_class( 'wp-block-button__link' ) . ' or ancestor::*[' . self::has_class( 'wp-block-button' ) . ']]';
		$desc = implode( ' or ', array_map( fn( $t ) => "self::$t", $tags ) ) . ' or self::a[' . self::has_class( 'wp-block-button__link' ) . ']';
		$seen = array();
		$out  = array();
		foreach ( $this->xp->query( '//main//*[' . $self . ']|//*[@role="main"]//*[' . $self . ']' ) as $el ) {
			if ( $this->xp->query( './/*[' . $desc . ']', $el )->length ) {
				continue;
			}
			if ( $this->inside( $el, 'self::script or self::style or self::noscript or @aria-hidden="true" or ' . self::has_class( 'screen-reader-text' ) ) ) {
				continue;
			}
			$text = self::text( $el );
			if ( mb_strlen( $text ) < 3 || ! preg_match( '/\p{L}{2}/u', $text ) || isset( $seen[ $text ] ) ) {
				continue;
			}
			$seen[ $text ] = true;
			$out[]         = array(
				'id'    => 's' . ( count( $out ) + 1 ),
				'tag'   => strtolower( $el->nodeName ),
				'block' => $this->block_of( $el ),
				'text'  => $text,
			);
		}
		return $out;
	}

	/**
	 * Anchor targets.
	 *
	 * @return string[]
	 */
	private function ids() {
		$out = array();
		foreach ( $this->xp->query( '//*[@id]|//a[@name]' ) as $el ) {
			$out[] = $el->getAttribute( 'id' ) ? $el->getAttribute( 'id' ) : $el->getAttribute( 'name' );
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Links.
	 *
	 * @return array
	 */
	private function links() {
		$out = array();
		foreach ( $this->xp->query( '//a[@href]' ) as $a ) {
			$raw = trim( $a->getAttribute( 'href' ) );
			if ( preg_match( '/^javascript:/i', $raw ) || '' === $raw ) {
				continue;
			}
			$img   = $this->xp->query( './/img', $a )->item( 0 );
			$out[] = array(
				'href'      => $this->abs( $raw ),
				'raw'       => $raw,
				'text'      => mb_substr( self::text( $a ), 0, 120 ),
				'ariaLabel' => $a->getAttribute( 'aria-label' ) ? $a->getAttribute( 'aria-label' ) : null,
				'image'     => $img ? array(
					'file' => self::file_name( $this->img_src( $img ) ),
					'alt'  => $img->hasAttribute( 'alt' ) ? $img->getAttribute( 'alt' ) : null,
				) : null,
				'block'     => $this->block_of( $a ),
				'region'    => $this->region( $a ),
				'target'    => $a->getAttribute( 'target' ) ? $a->getAttribute( 'target' ) : null,
				'rel'       => $a->getAttribute( 'rel' ) ? $a->getAttribute( 'rel' ) : null,
			);
		}
		return $out;
	}

	/**
	 * Image URL (including lazy-load plugins using data-src).
	 *
	 * @param DOMElement $img Image.
	 * @return string
	 */
	private function img_src( $img ) {
		foreach ( array( 'src', 'data-src', 'data-lazy-src' ) as $attr ) {
			$v = $img->getAttribute( $attr );
			if ( $v && ! str_starts_with( $v, 'data:' ) ) {
				return $this->abs( $v );
			}
		}
		return '';
	}

	/**
	 * Images.
	 *
	 * @return array
	 */
	private function images() {
		$out = array();
		$i   = 0;
		foreach ( $this->xp->query( '//img' ) as $img ) {
			if ( $this->inside( $img, self::has_class( 'swiper-slide-duplicate' ) ) ) {
				continue;
			}
			$src = $this->img_src( $img );
			if ( '' === $src ) {
				continue;
			}
			$att = null;
			foreach ( self::classes( $img ) as $c ) {
				if ( preg_match( '/^wp-image-(\d+)$/', $c, $m ) ) {
					$att = (int) $m[1];
				}
			}
			$out[] = array(
				'order'        => ++$i,
				'src'          => $src,
				'alt'          => $img->hasAttribute( 'alt' ) ? $img->getAttribute( 'alt' ) : null,
				'decorative'   => 'presentation' === $img->getAttribute( 'role' ) || $this->inside( $img, '@aria-hidden="true"' ),
				'attachmentId' => $att ? $att : ( (int) $img->getAttribute( 'data-id' ) ? (int) $img->getAttribute( 'data-id' ) : null ),
				'loading'      => $img->getAttribute( 'loading' ) ? $img->getAttribute( 'loading' ) : null,
				'srcset'       => $img->hasAttribute( 'srcset' ),
				'sizes'        => $img->getAttribute( 'sizes' ) ? $img->getAttribute( 'sizes' ) : null,
				'hasDims'      => $img->hasAttribute( 'width' ) && $img->hasAttribute( 'height' ),
				'width'        => (int) $img->getAttribute( 'width' ),
				'height'       => (int) $img->getAttribute( 'height' ),
				'block'        => $this->block_of( $img, array( 'core/image', 'core/column', 'core/columns' ) ),
				'region'       => $this->region( $img ),
				'background'   => false,
			);
		}
		return $out;
	}

	/**
	 * Embedded media.
	 *
	 * @return array
	 */
	private function media() {
		$out = array();
		foreach ( $this->xp->query( '//iframe|//video|//audio' ) as $m ) {
			$src = $m->getAttribute( 'src' );
			if ( ! $src ) {
				$source = $this->xp->query( './/source', $m )->item( 0 );
				$src    = $source ? $source->getAttribute( 'src' ) : '';
			}
			$out[] = array(
				'tag'     => strtolower( $m->nodeName ),
				'src'     => $src ? $this->abs( $src ) : null,
				'loading' => $m->getAttribute( 'loading' ) ? $m->getAttribute( 'loading' ) : null,
				'preload' => $m->getAttribute( 'preload' ) ? $m->getAttribute( 'preload' ) : null,
				'title'   => $m->getAttribute( 'title' ) ? $m->getAttribute( 'title' ) : null,
				'block'   => $this->block_of( $m ),
				'region'  => $this->region( $m ),
			);
		}
		return $out;
	}

	/**
	 * File name from URL.
	 *
	 * @param string $src URL.
	 * @return string
	 */
	public static function file_name( $src ) {
		$path = (string) wp_parse_url( $src, PHP_URL_PATH );
		return rawurldecode( basename( $path ) );
	}

	// ------------------------------------------------------------------ Image files

	/**
	 * Resolve image URLs to attachments and files (ID, alt in the media library, MIME, file size).
	 *
	 * @param array $images Images with src (and optional attachmentId).
	 * @return array
	 */
	public static function resolve_images( array $images ) {
		foreach ( $images as &$img ) {
			$img['file'] = self::resolve_file( $img['src'], $img['attachmentId'] ?? null );
		}
		return $images;
	}

	/**
	 * Resolve one image URL (cached per request).
	 *
	 * @param string   $url  URL.
	 * @param int|null $hint Attachment ID from the HTML.
	 * @return array
	 */
	public static function resolve_file( $url, $hint = null ) {
		static $cache = array();
		if ( isset( $cache[ $url ] ) ) {
			return $cache[ $url ];
		}
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$id   = $hint ? (int) $hint : 0;
		$file = null;
		if ( $host === $home ) {
			if ( ! $id ) {
				$id = attachment_url_to_postid( $url );
			}
			// Map intermediate sizes (-300x200) and -scaled back to the original.
			if ( ! $id ) {
				$base = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', strtok( $url, '?' ) );
				$id   = attachment_url_to_postid( $base );
				if ( ! $id ) {
					$id = attachment_url_to_postid( preg_replace( '/(?=\.\w+$)/', '-scaled', $base, 1 ) );
				}
			}
			$pos = strpos( $path, '/wp-content/' );
			if ( false !== $pos ) {
				$candidate = WP_CONTENT_DIR . '/' . rawurldecode( substr( $path, $pos + 12 ) );
				$real      = realpath( $candidate );
				if ( $real && str_starts_with( $real, realpath( WP_CONTENT_DIR ) ) && is_file( $real ) ) {
					$file = $real;
				}
			}
		}
		$mime = $id ? get_post_mime_type( $id ) : null;
		if ( $file && ( ! $mime || ! preg_match( '/-\d+x\d+\.\w+$/', $file ) ) ) {
			$ft   = wp_check_filetype( $file );
			$mime = $ft['type'] ? $ft['type'] : $mime;
		}
		$cache[ $url ] = array(
			'id'    => $id ? $id : null,
			'altDb' => $id ? (string) get_post_meta( $id, '_wp_attachment_image_alt', true ) : null,
			'mime'  => $mime ? $mime : null,
			'bytes' => $file ? filesize( $file ) : null,
			'theme' => str_contains( $path, '/wp-content/themes/' ),
		);
		return $cache[ $url ];
	}
}

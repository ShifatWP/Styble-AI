<?php
/**
 * Serializer: turns the AI's structured JSON (our intermediate representation)
 * into valid WordPress core-block markup.
 *
 * This is the heart of the plugin. The AI never emits block markup directly
 * (that format is fragile and models hallucinate it). Instead the AI fills a
 * clean JSON schema and THIS class deterministically produces correct markup.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Serializer {

	/**
	 * Serialize the full IR ( { "sections": [ ... ] } ) into a block-markup string.
	 *
	 * @param array $ir Decoded JSON from the model.
	 * @return string Block markup ready for parse_blocks() / wp.blocks.parse().
	 */
	public function serialize( array $ir ) {
		// Edit mode may return a flat list of blocks (no section wrapper) so an
		// edit to loose content is not forced inside a new Group. Prefer sections
		// when present; otherwise serialize a top-level "blocks" list.
		if ( ( ! isset( $ir['sections'] ) || ! is_array( $ir['sections'] ) || empty( $ir['sections'] ) )
			&& isset( $ir['blocks'] ) && is_array( $ir['blocks'] ) ) {
			return $this->serialize_blocks( $ir['blocks'] );
		}

		$out      = '';
		$sections = isset( $ir['sections'] ) && is_array( $ir['sections'] ) ? $ir['sections'] : array();

		foreach ( $sections as $section ) {
			$out .= $this->section( $section );
		}

		return $out;
	}

	/**
	 * Serialize a flat list of leaf blocks (no section/Group wrapper). Used by
	 * contextual editing when the model revises blocks in place.
	 *
	 * @param array $blocks List of block nodes (same schema as section blocks).
	 * @return string Block markup.
	 */
	public function serialize_blocks( array $blocks ) {
		$out = '';
		foreach ( $blocks as $block ) {
			if ( is_array( $block ) ) {
				$out .= $this->block( $block );
			}
		}
		return $out;
	}

	/**
	 * A section = a top-level Group block (our "section" concept).
	 */
	private function section( array $section ) {
		$blocks = isset( $section['blocks'] ) && is_array( $section['blocks'] ) ? $section['blocks'] : array();

		$inner = '';
		foreach ( $blocks as $block ) {
			$inner .= $this->block( $block );
		}

		$width = isset( $section['width'] ) ? $section['width'] : 'default';
		$tone  = isset( $section['tone'] ) ? $section['tone'] : 'default';

		$attrs = array(
			'layout' => array( 'type' => 'constrained' ),
			'style'  => array(
				'spacing' => array(
					'padding' => array(
						'top'    => '4rem',
						'bottom' => '4rem',
						'left'   => '1.5rem',
						'right'  => '1.5rem',
					),
				),
			),
		);

		$classes = array( 'wp-block-group' );

		if ( 'full' === $width ) {
			$attrs['align'] = 'full';
			$classes[]      = 'alignfull';
		} elseif ( 'wide' === $width ) {
			$attrs['align'] = 'wide';
			$classes[]      = 'alignwide';
		}

		// Tone maps to a background/text colour. Kept as inline style so it works
		// on any theme; a production build would prefer theme.json palette slugs.
		if ( 'dark' === $tone ) {
			$attrs['style']['color'] = array(
				'background' => '#16181d',
				'text'       => '#ffffff',
			);
			$classes[] = 'has-text-color has-background';
		} elseif ( 'light' === $tone ) {
			$attrs['style']['color'] = array( 'background' => '#f5f6f8' );
			$classes[]               = 'has-background';
		} elseif ( 'accent' === $tone ) {
			$attrs['style']['color'] = array(
				'background' => '#eef2ff',
				'text'       => '#1e1b4b',
			);
			$classes[] = 'has-text-color has-background';
		}

		$style_attr = $this->inline_style( $attrs['style'] );
		$class_attr = implode( ' ', $classes );

		return sprintf(
			"<!-- wp:group %s -->\n<div class=\"%s\"%s>%s</div>\n<!-- /wp:group -->\n\n",
			wp_json_encode( $attrs ),
			esc_attr( $class_attr ),
			$style_attr ? ' style="' . esc_attr( $style_attr ) . '"' : '',
			$inner
		);
	}

	/**
	 * Dispatch a single block node by its "type".
	 */
	private function block( array $b ) {
		$type = isset( $b['type'] ) ? $b['type'] : '';

		switch ( $type ) {
			case 'heading':
				return $this->heading( $b );
			case 'paragraph':
				return $this->paragraph( $b );
			case 'buttons':
				return $this->buttons( $b );
			case 'list':
				return $this->list_block( $b );
			case 'quote':
				return $this->quote( $b );
			case 'image':
				return $this->image( $b );
			case 'spacer':
				return $this->spacer( $b );
			case 'columns':
				return $this->columns( $b );
			default:
				return '';
		}
	}

	private function heading( array $b ) {
		$level = isset( $b['level'] ) ? (int) $b['level'] : 2;
		$level = max( 1, min( 4, $level ) );
		$text  = $this->clean_inline( isset( $b['text'] ) ? $b['text'] : '' );

		$attrs = $level === 2 ? '' : ' ' . wp_json_encode( array( 'level' => $level ) );

		return sprintf(
			"<!-- wp:heading%s -->\n<h%d class=\"wp-block-heading\">%s</h%d>\n<!-- /wp:heading -->\n\n",
			$attrs,
			$level,
			$text,
			$level
		);
	}

	private function paragraph( array $b ) {
		$text = $this->clean_inline( isset( $b['text'] ) ? $b['text'] : '' );

		return sprintf(
			"<!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->\n\n",
			$text
		);
	}

	private function buttons( array $b ) {
		$items = isset( $b['items'] ) && is_array( $b['items'] ) ? $b['items'] : array();
		$inner = '';

		foreach ( $items as $item ) {
			$label = $this->clean_inline( isset( $item['label'] ) ? $item['label'] : 'Learn more' );
			$url   = isset( $item['url'] ) ? esc_url( $item['url'] ) : '#';
			$style = isset( $item['style'] ) ? $item['style'] : 'fill';

			if ( 'outline' === $style ) {
				$inner .= sprintf(
					"<!-- wp:button {\"className\":\"is-style-outline\"} -->\n<div class=\"wp-block-button is-style-outline\"><a class=\"wp-block-button__link wp-element-button\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->\n",
					$url,
					$label
				);
			} else {
				$inner .= sprintf(
					"<!-- wp:button -->\n<div class=\"wp-block-button\"><a class=\"wp-block-button__link wp-element-button\" href=\"%s\">%s</a></div>\n<!-- /wp:button -->\n",
					$url,
					$label
				);
			}
		}

		return sprintf(
			"<!-- wp:buttons -->\n<div class=\"wp-block-buttons\">%s</div>\n<!-- /wp:buttons -->\n\n",
			$inner
		);
	}

	private function list_block( array $b ) {
		$ordered = ! empty( $b['ordered'] );
		$items   = isset( $b['items'] ) && is_array( $b['items'] ) ? $b['items'] : array();
		$tag     = $ordered ? 'ol' : 'ul';

		$li = '';
		foreach ( $items as $item ) {
			$li .= sprintf(
				"<!-- wp:list-item -->\n<li>%s</li>\n<!-- /wp:list-item -->\n",
				$this->clean_inline( is_string( $item ) ? $item : '' )
			);
		}

		$attrs = $ordered ? ' ' . wp_json_encode( array( 'ordered' => true ) ) : '';

		return sprintf(
			"<!-- wp:list%s -->\n<%s class=\"wp-block-list\">%s</%s>\n<!-- /wp:list -->\n\n",
			$attrs,
			$tag,
			$li,
			$tag
		);
	}

	private function quote( array $b ) {
		$text = $this->clean_inline( isset( $b['text'] ) ? $b['text'] : '' );
		$cite = $this->clean_inline( isset( $b['citation'] ) ? $b['citation'] : '' );

		$cite_html = $cite ? '<cite>' . $cite . '</cite>' : '';

		return sprintf(
			"<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><!-- wp:paragraph -->\n<p>%s</p>\n<!-- /wp:paragraph -->%s</blockquote>\n<!-- /wp:quote -->\n\n",
			$text,
			$cite_html
		);
	}

	/**
	 * Image is intentionally a blank placeholder. The AI does not invent image
	 * URLs; the user picks media after insertion. Alt/caption carry AI intent.
	 */
	private function image( array $b ) {
		$alt     = isset( $b['alt'] ) ? esc_attr( $b['alt'] ) : '';
		$caption = $this->clean_inline( isset( $b['caption'] ) ? $b['caption'] : '' );

		$fig_caption = $caption ? '<figcaption class="wp-element-caption">' . $caption . '</figcaption>' : '';

		return sprintf(
			"<!-- wp:image -->\n<figure class=\"wp-block-image\"><img alt=\"%s\"/>%s</figure>\n<!-- /wp:image -->\n\n",
			$alt,
			$fig_caption
		);
	}

	private function spacer( array $b ) {
		$height = isset( $b['height'] ) ? (int) $b['height'] : 40;
		$height = max( 8, min( 400, $height ) );

		return sprintf(
			"<!-- wp:spacer {\"height\":\"%dpx\"} -->\n<div style=\"height:%dpx\" aria-hidden=\"true\" class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer -->\n\n",
			$height,
			$height
		);
	}

	/**
	 * One level of columns. Each column contains leaf blocks (no further nesting).
	 */
	private function columns( array $b ) {
		$cols  = isset( $b['columns'] ) && is_array( $b['columns'] ) ? $b['columns'] : array();
		$inner = '';

		foreach ( $cols as $col ) {
			$col_blocks = isset( $col['blocks'] ) && is_array( $col['blocks'] ) ? $col['blocks'] : array();
			$col_inner  = '';

			foreach ( $col_blocks as $leaf ) {
				// Guard against runaway nesting from the model.
				if ( isset( $leaf['type'] ) && 'columns' === $leaf['type'] ) {
					continue;
				}
				$col_inner .= $this->block( $leaf );
			}

			$inner .= sprintf(
				"<!-- wp:column -->\n<div class=\"wp-block-column\">%s</div>\n<!-- /wp:column -->\n",
				$col_inner
			);
		}

		return sprintf(
			"<!-- wp:columns -->\n<div class=\"wp-block-columns\">%s</div>\n<!-- /wp:columns -->\n\n",
			$inner
		);
	}

	/* ------------------------------------------------------------------ */

	/**
	 * Allow a tiny set of inline formatting tags the model may emit, strip the rest.
	 */
	private function clean_inline( $text ) {
		$allowed = array(
			'strong' => array(),
			'em'     => array(),
			'a'      => array( 'href' => array(), 'rel' => array(), 'target' => array() ),
			'br'     => array(),
		);
		return wp_kses( (string) $text, $allowed );
	}

	/**
	 * Build an inline CSS string from a block "style" attribute (colours only here).
	 */
	private function inline_style( array $style ) {
		$css = '';
		if ( isset( $style['color']['background'] ) ) {
			$css .= 'background-color:' . $style['color']['background'] . ';';
		}
		if ( isset( $style['color']['text'] ) ) {
			$css .= 'color:' . $style['color']['text'] . ';';
		}
		if ( isset( $style['spacing']['padding'] ) ) {
			$p = $style['spacing']['padding'];
			foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
				if ( isset( $p[ $side ] ) ) {
					$css .= 'padding-' . $side . ':' . $p[ $side ] . ';';
				}
			}
		}
		return $css;
	}
}

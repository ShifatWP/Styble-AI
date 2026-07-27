<?php
/**
 * Reads the active theme's design tokens (theme.json) so the AI can write copy
 * and choose tones that fit the site instead of generic output.
 *
 * This is the differentiator: context-awareness. We hand the model the palette
 * and font sizes; the serializer stays deterministic.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Theme_Context {

	/**
	 * Return a short, prompt-friendly description of the theme's design tokens.
	 *
	 * @return string
	 */
	public function summary() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return 'No theme.json data available (classic theme).';
		}

		$settings = wp_get_global_settings();
		$parts    = array();

		// Colour palette (slug + name).
		$palette = array();
		if ( ! empty( $settings['color']['palette'] ) ) {
			// Merge theme + default + custom origins if present.
			foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
				if ( ! empty( $settings['color']['palette'][ $origin ] ) ) {
					foreach ( $settings['color']['palette'][ $origin ] as $c ) {
						if ( isset( $c['slug'], $c['name'] ) ) {
							$palette[ $c['slug'] ] = $c['name'];
						}
					}
				}
			}
			// Some themes return a flat list.
			if ( isset( $settings['color']['palette'][0] ) ) {
				foreach ( $settings['color']['palette'] as $c ) {
					if ( isset( $c['slug'], $c['name'] ) ) {
						$palette[ $c['slug'] ] = $c['name'];
					}
				}
			}
		}
		if ( $palette ) {
			$list    = array();
			foreach ( $palette as $slug => $name ) {
				$list[] = $name . ' (' . $slug . ')';
			}
			$parts[] = 'Colour palette: ' . implode( ', ', array_slice( $list, 0, 12 ) ) . '.';
		}

		// Font sizes.
		$sizes = array();
		if ( ! empty( $settings['typography']['fontSizes'] ) ) {
			foreach ( array( 'theme', 'default', 'custom' ) as $origin ) {
				if ( ! empty( $settings['typography']['fontSizes'][ $origin ] ) ) {
					foreach ( $settings['typography']['fontSizes'][ $origin ] as $s ) {
						if ( isset( $s['slug'] ) ) {
							$sizes[] = $s['slug'];
						}
					}
				}
			}
			if ( isset( $settings['typography']['fontSizes'][0] ) ) {
				foreach ( $settings['typography']['fontSizes'] as $s ) {
					if ( isset( $s['slug'] ) ) {
						$sizes[] = $s['slug'];
					}
				}
			}
		}
		if ( $sizes ) {
			$parts[] = 'Font size slugs: ' . implode( ', ', array_unique( $sizes ) ) . '.';
		}

		$site_title = get_bloginfo( 'name' );
		if ( $site_title ) {
			$parts[] = 'Site name: ' . $site_title . '.';
		}
		$tagline = get_bloginfo( 'description' );
		if ( $tagline ) {
			$parts[] = 'Tagline: ' . $tagline . '.';
		}

		return $parts ? implode( ' ', $parts ) : 'No theme design tokens found.';
	}
}

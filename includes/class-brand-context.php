<?php
/**
 * Brand context for the system prompt.
 *
 * @package Styble_AI
 */

// CLI scripts load this to render the prompt; there it simply finds no settings.
if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

/**
 * Reads the site's Styble global settings so generated sections land on-brand.
 *
 * This replaces AI Block Composer's theme.json reader: Styble blocks take their
 * colours and type scale from Styble's own global settings, not from the theme.
 * Telling the model the real slugs of the site it is writing for is the moat over
 * a generic block generator — output matches the surrounding design instead of
 * inventing hex values.
 *
 * Fails soft on purpose. A site with no saved global settings, or one where
 * Styble Pro is inactive, should still be able to generate — just without brand
 * guidance. An empty summary is a missing nicety, not an error.
 */
class Styble_AI_Brand_Context {

	/**
	 * Styble Pro's settings reader. Namespaced there; we only ever call it if it
	 * is actually loaded.
	 */
	const HELPER = '\ShapedPlugin\StyblePro\Includes\Global_Settings_Helper';

	/**
	 * Raw settings, or an empty array when Styble Pro is not available.
	 *
	 * @return array
	 */
	public static function settings() {
		if ( ! class_exists( self::HELPER ) ) {
			return array();
		}
		$settings = call_user_func( array( self::HELPER, 'get_settings_for_api' ) );
		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Colour slugs with their hex values.
	 *
	 * @return array<string, string> slug => hex.
	 */
	public static function colors() {
		$settings = self::settings();
		$presets  = isset( $settings['colors']['presetColors'] ) ? $settings['colors']['presetColors'] : array();
		$out      = array();

		foreach ( (array) $presets as $color ) {
			if ( isset( $color['slug'] ) && isset( $color['color'] ) ) {
				$out[ $color['slug'] ] = $color['color'];
			}
		}
		return $out;
	}

	/**
	 * Type-scale slugs with their Desktop font size.
	 *
	 * @return array<string, string> slug => "44px".
	 */
	public static function type_scale() {
		$settings = self::settings();
		$sizes    = isset( $settings['typography']['typographySizes'] ) ? $settings['typography']['typographySizes'] : array();
		$out      = array();

		foreach ( (array) $sizes as $group ) {
			foreach ( (array) $group as $entry ) {
				if ( ! isset( $entry['slug'] ) ) {
					continue;
				}
				$value = isset( $entry['fontSize']['device']['Desktop'] ) ? $entry['fontSize']['device']['Desktop'] : '';
				$unit  = isset( $entry['fontSize']['unit']['Desktop'] ) ? $entry['fontSize']['unit']['Desktop'] : 'px';
				if ( '' !== $value ) {
					$out[ $entry['slug'] ] = $value . $unit;
				}
			}
		}
		return $out;
	}

	/**
	 * A compact, promptable summary of the site's design language.
	 *
	 * @return string Empty when nothing is known.
	 */
	public static function summary() {
		$lines  = array();
		$colors = self::colors();
		$scale  = self::type_scale();

		if ( $colors ) {
			$pairs = array();
			foreach ( $colors as $slug => $hex ) {
				$pairs[] = "{$slug} ({$hex})";
			}
			$lines[] = 'Brand colour slugs: ' . implode( ', ', $pairs ) . '.';
		}

		if ( $scale ) {
			$pairs = array();
			foreach ( $scale as $slug => $size ) {
				$pairs[] = "{$slug} {$size}";
			}
			$lines[] = 'Type scale: ' . implode( ', ', $pairs ) . '.';
		}

		$settings = self::settings();
		$heading  = isset( $settings['typography']['commonFontFamilyHeading']['family'] ) ? $settings['typography']['commonFontFamilyHeading']['family'] : '';
		$body     = isset( $settings['typography']['commonFontFamilyBody']['family'] ) ? $settings['typography']['commonFontFamilyBody']['family'] : '';
		if ( '' !== $heading || '' !== $body ) {
			$lines[] = trim( 'Fonts: heading ' . ( $heading ? $heading : 'theme default' ) . ', body ' . ( $body ? $body : 'theme default' ) . '.' );
		}

		if ( ! $lines ) {
			return '';
		}

		return implode( "\n", $lines )
			. "\nWrite copy that suits this palette and scale. Never invent hex values — the blocks pick up brand colours on their own.";
	}
}

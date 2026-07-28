<?php
/**
 * Builds the configured LLM provider.
 *
 * Extracted so the section route, the chat route and the page planner all get
 * the same provider from the same settings, instead of each growing its own
 * copy of the preset/base-URL logic.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Provider_Factory {

	/**
	 * "anthropic" uses the native Messages API; every other id is an
	 * OpenAI-compatible chat/completions endpoint (Groq, Cerebras, OpenRouter,
	 * DeepSeek, Mistral, Together, Gemini, or a custom base URL).
	 *
	 * @return Styble_AI_Anthropic_Provider|Styble_AI_OpenAI_Compatible_Provider
	 */
	public static function make() {
		$provider = get_option( 'styble_ai_provider', 'anthropic' );
		$api_key  = get_option( 'styble_ai_api_key', '' );
		$model    = get_option( 'styble_ai_model', '' );

		if ( 'anthropic' === $provider ) {
			return new Styble_AI_Anthropic_Provider( $api_key, $model ? $model : 'claude-opus-5' );
		}

		$presets  = Styble_AI_OpenAI_Compatible_Provider::presets();
		$endpoint = '';
		if ( 'custom' === $provider ) {
			$endpoint = get_option( 'styble_ai_base_url', '' );
		} elseif ( isset( $presets[ $provider ] ) ) {
			$endpoint = $presets[ $provider ]['endpoint'];
			if ( '' === $model && ! empty( $presets[ $provider ]['model'] ) ) {
				$model = $presets[ $provider ]['model'];
			}
		}

		return new Styble_AI_OpenAI_Compatible_Provider( $api_key, $model, $endpoint );
	}

	/**
	 * Is a key configured at all? Lets the UI say "add a key" instead of
	 * showing the provider's 401.
	 *
	 * @return bool
	 */
	public static function has_key() {
		return '' !== trim( (string) get_option( 'styble_ai_api_key', '' ) );
	}
}

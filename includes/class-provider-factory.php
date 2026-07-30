<?php
/**
 * Builds the configured LLM provider.
 *
 * Extracted so the section route, the chat route and the page planner all get
 * the same provider from the same settings, instead of each growing its own
 * copy of the preset/base-URL logic.
 *
 * Credentials are stored PER PROVIDER, in one `styble_ai_credentials` array:
 *
 *     [ 'anthropic' => [ 'key' => '…', 'model' => 'claude-opus-5' ],
 *       'gemini'    => [ 'key' => '…', 'model' => '' ], … ]
 *
 * This replaced a single shared `styble_ai_api_key`, which was blocker B3 in the
 * roadmap: switching provider overwrote the only key field, so two providers
 * could never be held at once — and the eval plan's most useful signal is a
 * TARGET model versus a FLOOR model, which needs exactly that. Under the old
 * shape `provider=gemini` moved the endpoint but kept whatever key was in the
 * box, so a mismatch failed all 20 cases on auth and read like a catastrophic
 * model score.
 *
 * The legacy single-key options are still READ, once, and migrated into the
 * array (see maybe_migrate_legacy) so an existing install keeps working without
 * the user re-pasting anything.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'STYBLE_AI_CLI' ) ) {
	exit;
}

class Styble_AI_Provider_Factory {

	/**
	 * Option holding every provider's credentials.
	 *
	 * @var string
	 */
	const CREDENTIALS_OPTION = 'styble_ai_credentials';

	/**
	 * Pre-B3 options. Read for migration, never written.
	 *
	 * @var string
	 */
	const LEGACY_KEY_OPTION   = 'styble_ai_api_key';
	const LEGACY_MODEL_OPTION = 'styble_ai_model';

	/**
	 * The provider selected in Settings.
	 *
	 * @return string
	 */
	public static function active_provider() {
		$provider = get_option( 'styble_ai_provider', 'anthropic' );
		return is_string( $provider ) && '' !== $provider ? $provider : 'anthropic';
	}

	/**
	 * Every provider id the plugin can talk to: Anthropic's native Messages API
	 * plus every OpenAI-compatible preset, plus `custom`.
	 *
	 * @return array<int,string>
	 */
	public static function provider_ids() {
		$ids = array( 'anthropic' );
		foreach ( array_keys( Styble_AI_OpenAI_Compatible_Provider::presets() ) as $id ) {
			$ids[] = $id;
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * All stored credentials, keyed by provider id.
	 *
	 * Unknown provider ids are dropped on read rather than trusted — a renamed
	 * or removed preset must not leave a key addressable under a name nothing
	 * validates.
	 *
	 * @return array<string,array{key:string,model:string}>
	 */
	public static function credentials() {
		self::maybe_migrate_legacy();

		$stored = get_option( self::CREDENTIALS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}

		$known = self::provider_ids();
		$out   = array();
		foreach ( $stored as $provider => $slot ) {
			if ( ! is_string( $provider ) || ! in_array( $provider, $known, true ) || ! is_array( $slot ) ) {
				continue;
			}
			$out[ $provider ] = array(
				'key'   => isset( $slot['key'] ) && is_string( $slot['key'] ) ? trim( $slot['key'] ) : '',
				'model' => isset( $slot['model'] ) && is_string( $slot['model'] ) ? trim( $slot['model'] ) : '',
			);
		}
		return $out;
	}

	/**
	 * One provider's API key, or ''.
	 *
	 * @param string|null $provider Provider id; the active one when null.
	 *
	 * @return string
	 */
	public static function key_for( $provider = null ) {
		$provider = null === $provider ? self::active_provider() : $provider;
		$creds    = self::credentials();
		return isset( $creds[ $provider ]['key'] ) ? $creds[ $provider ]['key'] : '';
	}

	/**
	 * One provider's model override, or '' to mean "use the preset default".
	 *
	 * @param string|null $provider Provider id; the active one when null.
	 *
	 * @return string
	 */
	public static function model_for( $provider = null ) {
		$provider = null === $provider ? self::active_provider() : $provider;
		$creds    = self::credentials();
		return isset( $creds[ $provider ]['model'] ) ? $creds[ $provider ]['model'] : '';
	}

	/**
	 * Which providers currently hold a key. Drives the Settings summary and lets
	 * the eval runner say "gemini has no key" instead of running 20 cases into a
	 * 401.
	 *
	 * @return array<int,string>
	 */
	public static function providers_with_keys() {
		$out = array();
		foreach ( self::credentials() as $provider => $slot ) {
			if ( '' !== $slot['key'] ) {
				$out[] = $provider;
			}
		}
		sort( $out );
		return $out;
	}

	/**
	 * "anthropic" uses the native Messages API; every other id is an
	 * OpenAI-compatible chat/completions endpoint (Groq, Cerebras, OpenRouter,
	 * DeepSeek, Mistral, Together, Gemini, or a custom base URL).
	 *
	 * @param string|null $provider Provider id; the active one when null. Passing
	 *                              it explicitly is how the eval runner targets a
	 *                              model other than the configured one — it now
	 *                              picks up THAT provider's own key.
	 *
	 * @return Styble_AI_Anthropic_Provider|Styble_AI_OpenAI_Compatible_Provider
	 */
	public static function make( $provider = null ) {
		$provider = null === $provider ? self::active_provider() : $provider;
		$api_key  = self::key_for( $provider );
		$model    = self::model_for( $provider );

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
	 * @param string|null $provider Provider id; the active one when null.
	 *
	 * @return bool
	 */
	public static function has_key( $provider = null ) {
		return '' !== self::key_for( $provider );
	}

	/**
	 * Seed the credentials array from the pre-B3 single-key options, once.
	 *
	 * Runs at most one write per install: the legacy key is copied into the
	 * ACTIVE provider's slot, because that is the only provider it could have
	 * belonged to. Legacy options are left in place rather than deleted — they
	 * cost nothing, and deleting them would make a downgrade lose the key.
	 *
	 * Idempotent, and never clobbers a slot that already has a key: once the
	 * user has saved through the new screen, this is inert.
	 *
	 * @return void
	 */
	private static function maybe_migrate_legacy() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$legacy_key = trim( (string) get_option( self::LEGACY_KEY_OPTION, '' ) );
		if ( '' === $legacy_key ) {
			return;
		}

		$stored = get_option( self::CREDENTIALS_OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$provider = self::active_provider();
		if ( isset( $stored[ $provider ]['key'] ) && '' !== trim( (string) $stored[ $provider ]['key'] ) ) {
			return;
		}

		$stored[ $provider ] = array(
			'key'   => $legacy_key,
			'model' => trim( (string) get_option( self::LEGACY_MODEL_OPTION, '' ) ),
		);

		update_option( self::CREDENTIALS_OPTION, $stored );
	}

	/**
	 * Sanitize a posted credentials array: known providers only, strings only,
	 * trimmed. Unknown keys inside a slot are dropped.
	 *
	 * Registered as the option's `sanitize_callback`, so it is the only path by
	 * which the option is written from the admin screen.
	 *
	 * @param mixed $value Posted value.
	 *
	 * @return array<string,array{key:string,model:string}>
	 */
	public static function sanitize_credentials( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$known = self::provider_ids();
		$out   = array();
		foreach ( $value as $provider => $slot ) {
			if ( ! is_string( $provider ) || ! in_array( $provider, $known, true ) || ! is_array( $slot ) ) {
				continue;
			}
			$key   = isset( $slot['key'] ) ? trim( sanitize_text_field( (string) $slot['key'] ) ) : '';
			$model = isset( $slot['model'] ) ? trim( sanitize_text_field( (string) $slot['model'] ) ) : '';

			// An empty slot is dropped rather than stored, so
			// providers_with_keys() stays a straight read and the option does not
			// accumulate a row per provider the user merely looked at.
			if ( '' === $key && '' === $model ) {
				continue;
			}
			$out[ $provider ] = array(
				'key'   => $key,
				'model' => $model,
			);
		}
		return $out;
	}
}

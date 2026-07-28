<?php
/**
 * Fills a validated tree's image placeholders with real stock photography.
 *
 * The model still never invents a URL or an attachment id — that rule does not
 * bend, and the validator still rejects a tree that tries. This runs *after*
 * validation: the model describes the photo it wants in `imgAltText`, and that
 * description is the search query. Nothing about the contract changes, which is
 * why this needed no new tool, no new attribute and no prompt rewrite.
 *
 * Photos are sideloaded into the media library and the block gets a real
 * attachment id. Hotlinking the provider's CDN would be less work and worse:
 * Styble's frontend resolves `selectImageId` through wp_get_attachment_image_url()
 * for srcset and sizes, the editor rebuilds `selectImage` from the attachment on
 * mount, and a hotlinked page breaks when the provider rotates a URL. An id also
 * means the user can crop, replace or reuse the image like any other upload.
 *
 * Every failure here is non-fatal. A missing key, a rate limit, a query with no
 * results, a download that fails — all leave the placeholder exactly as the
 * model emitted it and report a warning. An image is worth less than the page.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Media {

	const IMAGE_BLOCK = 'styble/advanced-image';

	/**
	 * How long a query→attachment mapping is remembered.
	 *
	 * Reuse is the point, not just the rate limit: asking twice for "a barista
	 * pouring latte art" should not put two copies of the same photograph in the
	 * media library.
	 */
	const CACHE_TTL = WEEK_IN_SECONDS;

	/**
	 * Candidates fetched per query, so two images in one section can differ.
	 */
	const CANDIDATES = 5;

	/**
	 * Attachment ids used during this fill, to avoid repeating a photo inside one
	 * section.
	 *
	 * @var int[]
	 */
	private $used = array();

	/**
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Provider descriptors. Pexels is the default: its licence permits hosting a
	 * copy, which is what sideloading does. Unsplash needs its download endpoint
	 * pinged and its photographer credited, both handled below.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'pexels'   => array(
				'label'  => 'Pexels',
				'signup' => 'https://www.pexels.com/api/new/',
			),
			'unsplash' => array(
				'label'  => 'Unsplash',
				'signup' => 'https://unsplash.com/oauth/applications',
			),
		);
	}

	/**
	 * @return string Provider id, or '' when image filling is off.
	 */
	public static function provider() {
		$id = (string) get_option( 'styble_ai_media_provider', '' );
		return array_key_exists( $id, self::providers() ) ? $id : '';
	}

	/**
	 * Off unless a provider AND a key are configured. With no key the plugin
	 * behaves exactly as it did before: blank placeholders the user fills in.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return '' !== self::provider() && '' !== trim( (string) get_option( 'styble_ai_media_key', '' ) );
	}

	/**
	 * Fill every image placeholder in a validated tree.
	 *
	 * @param array $tree Validated emit_layout envelope.
	 *
	 * @return array { tree, warnings, filled }
	 */
	public function fill( array $tree ) {
		$this->warnings = array();
		$this->used     = array();

		if ( ! self::is_enabled() || ! isset( $tree['root'] ) ) {
			return array(
				'tree'     => $tree,
				'warnings' => array(),
				'filled'   => 0,
			);
		}

		$filled       = 0;
		$tree['root'] = $this->walk( $tree['root'], $filled );

		return array(
			'tree'     => $tree,
			'warnings' => $this->warnings,
			'filled'   => $filled,
		);
	}

	/**
	 * @param array $node   Tree node.
	 * @param int   $filled Running count, by reference.
	 *
	 * @return array
	 */
	private function walk( array $node, &$filled ) {
		if ( isset( $node['block'] ) && self::IMAGE_BLOCK === $node['block'] ) {
			$id = $this->resolve( isset( $node['attrs']['imgAltText'] ) ? (string) $node['attrs']['imgAltText'] : '' );
			if ( $id ) {
				$attrs = isset( $node['attrs'] ) && is_array( $node['attrs'] ) ? $node['attrs'] : array();
				// selectImageId is the only field worth writing. The editor
				// rebuilds selectImage from the attachment on mount, and the
				// frontend has an explicit attachment-id fallback, so a
				// hand-built image object would only go stale.
				$attrs['selectImageId'] = $id;
				$node['attrs']          = $attrs;
				$filled++;
			}
		}

		if ( ! empty( $node['children'] ) && is_array( $node['children'] ) ) {
			foreach ( $node['children'] as $i => $child ) {
				if ( is_array( $child ) ) {
					$node['children'][ $i ] = $this->walk( $child, $filled );
				}
			}
		}

		return $node;
	}

	/**
	 * Query → attachment id.
	 *
	 * @param string $query Alt text the model wrote.
	 *
	 * @return int Attachment id, or 0 when nothing could be resolved.
	 */
	private function resolve( $query ) {
		$query = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $query ) ) );
		if ( '' === $query ) {
			return 0;
		}

		$provider = self::provider();
		$key      = 'styble_ai_media_' . md5( $provider . '|' . strtolower( $query ) );

		// Keyed by SOURCE URL, not just a list of ids. Two images asking for the
		// same subject have to get different photographs, and an id-only cache
		// cannot tell "already used" from "a different photo of the same thing" —
		// so it downloads the top result a second time and the section shows the
		// identical picture twice under two attachment ids.
		$cached = get_transient( $key );
		$cached = is_array( $cached ) ? $cached : array();

		foreach ( $cached as $url => $id ) {
			$id = (int) $id;
			// A cached id can point at an attachment the user has since deleted;
			// treat that as a miss rather than a broken image.
			if ( ! $id || in_array( $id, $this->used, true ) || ! get_post( $id ) ) {
				continue;
			}
			$this->used[] = $id;
			return $id;
		}

		$hits = $this->search( $query );
		if ( is_wp_error( $hits ) ) {
			$this->warnings[] = sprintf( '%s: %s', $query, $hits->get_error_message() );
			return 0;
		}
		if ( ! $hits ) {
			$this->warnings[] = sprintf( 'No stock photo found for "%s".', $query );
			return 0;
		}

		foreach ( $hits as $hit ) {
			// Already in the library, and the loop above established its id is
			// spoken for in this pass. Move to a genuinely different photo rather
			// than downloading this one again.
			if ( isset( $cached[ $hit['url'] ] ) ) {
				continue;
			}

			$id = $this->sideload( $hit, $query );
			if ( is_wp_error( $id ) ) {
				$this->warnings[] = sprintf( '%s: %s', $query, $id->get_error_message() );
				continue;
			}

			$cached[ $hit['url'] ] = $id;
			set_transient( $key, $cached, self::CACHE_TTL );

			$this->used[] = $id;
			return $id;
		}

		$this->warnings[] = sprintf( 'Only found photos already used for "%s".', $query );

		return 0;
	}

	/**
	 * Search the configured provider.
	 *
	 * @param string $query Search phrase.
	 *
	 * @return array|WP_Error List of { url, credit, creditUrl, alt, ping }.
	 */
	private function search( $query ) {
		$provider = self::provider();
		$api_key  = trim( (string) get_option( 'styble_ai_media_key', '' ) );

		if ( 'unsplash' === $provider ) {
			$url     = add_query_arg(
				array(
					'query'       => $query,
					'per_page'    => self::CANDIDATES,
					'orientation' => 'landscape',
					'content_filter' => 'high',
				),
				'https://api.unsplash.com/search/photos'
			);
			$headers = array( 'Authorization' => 'Client-ID ' . $api_key );
		} else {
			$url     = add_query_arg(
				array(
					'query'       => $query,
					'per_page'    => self::CANDIDATES,
					'orientation' => 'landscape',
				),
				'https://api.pexels.com/v1/search'
			);
			$headers = array( 'Authorization' => $api_key );
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => $headers,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			// 401 and 403 are the two a user can fix, so name them.
			if ( 401 === $code || 403 === $code ) {
				return new WP_Error( 'styble_ai_media_auth', 'the stock photo API key was rejected' );
			}
			if ( 429 === $code ) {
				return new WP_Error( 'styble_ai_media_rate', 'the stock photo API rate limit was reached' );
			}
			return new WP_Error( 'styble_ai_media_http', sprintf( 'the stock photo API returned HTTP %d', $code ) );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		return ( 'unsplash' === $provider )
			? self::parse_unsplash( $body )
			: self::parse_pexels( $body );
	}

	/**
	 * @param mixed $body Decoded response.
	 *
	 * @return array
	 */
	private static function parse_pexels( $body ) {
		$out = array();
		foreach ( ( isset( $body['photos'] ) && is_array( $body['photos'] ) ? $body['photos'] : array() ) as $photo ) {
			if ( empty( $photo['src']['large2x'] ) && empty( $photo['src']['large'] ) ) {
				continue;
			}
			$out[] = array(
				'url'       => ! empty( $photo['src']['large2x'] ) ? $photo['src']['large2x'] : $photo['src']['large'],
				'credit'    => isset( $photo['photographer'] ) ? $photo['photographer'] : '',
				'creditUrl' => isset( $photo['photographer_url'] ) ? $photo['photographer_url'] : '',
				'alt'       => isset( $photo['alt'] ) ? $photo['alt'] : '',
				'ping'      => '',
			);
		}
		return $out;
	}

	/**
	 * @param mixed $body Decoded response.
	 *
	 * @return array
	 */
	private static function parse_unsplash( $body ) {
		$out = array();
		foreach ( ( isset( $body['results'] ) && is_array( $body['results'] ) ? $body['results'] : array() ) as $photo ) {
			if ( empty( $photo['urls']['regular'] ) ) {
				continue;
			}
			$out[] = array(
				'url'       => $photo['urls']['regular'],
				'credit'    => isset( $photo['user']['name'] ) ? $photo['user']['name'] : '',
				'creditUrl' => isset( $photo['user']['links']['html'] ) ? $photo['user']['links']['html'] : '',
				'alt'       => isset( $photo['alt_description'] ) ? (string) $photo['alt_description'] : '',
				// Unsplash's API terms require this endpoint to be called when a
				// photo is used. Not optional, and not a download URL.
				'ping'      => isset( $photo['links']['download_location'] ) ? $photo['links']['download_location'] : '',
			);
		}
		return $out;
	}

	/**
	 * Download one photo into the media library.
	 *
	 * @param array  $hit Search hit.
	 * @param string $alt Alt text to record.
	 *
	 * @return int|WP_Error Attachment id.
	 */
	private function sideload( array $hit, $alt ) {
		/**
		 * Replace the download with an attachment id of your own.
		 *
		 * For a site with its own DAM, an already-licensed library, or a test that
		 * must not reach the network. Only the download is replaced — alt text and
		 * photographer credit are still recorded below, because an attachment
		 * without them is the thing this feature exists to avoid.
		 *
		 * @param int|WP_Error|null $id  Null to let Styble AI download it.
		 * @param array             $hit { url, credit, creditUrl, alt, ping }.
		 * @param string            $alt Alt text the model wrote.
		 */
		$id = apply_filters( 'styble_ai_pre_media_sideload', null, $hit, $alt );

		if ( null === $id ) {
			// media_sideload_image() lives in wp-admin; REST and CLI requests do
			// not load it for us.
			foreach ( array( 'file.php', 'media.php', 'image.php' ) as $inc ) {
				require_once ABSPATH . 'wp-admin/includes/' . $inc;
			}
			$id = media_sideload_image( $hit['url'], 0, $alt, 'id' );
		}

		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$id = (int) $id;
		if ( $id <= 0 ) {
			return new WP_Error( 'styble_ai_media_no_attachment', 'the photo could not be added to the media library' );
		}

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		update_post_meta(
			$id,
			'_styble_ai_media',
			array(
				'provider'  => self::provider(),
				'credit'    => $hit['credit'],
				'creditUrl' => $hit['creditUrl'],
			)
		);

		// Attribution belongs somewhere the user can find it. The caption is
		// where WordPress already shows photo credit.
		if ( '' !== $hit['credit'] ) {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_excerpt' => wp_slash( sprintf(
						/* translators: 1: photographer name, 2: provider name */
						__( 'Photo by %1$s on %2$s', 'styble-ai' ),
						$hit['credit'],
						self::providers()[ self::provider() ]['label']
					) ),
				)
			);
		}

		// Required by Unsplash's API terms whenever a photo is used. Fire and
		// forget: a failed ping must not cost the user their image.
		if ( '' !== $hit['ping'] ) {
			wp_remote_get(
				add_query_arg( 'client_id', trim( (string) get_option( 'styble_ai_media_key', '' ) ), $hit['ping'] ),
				array(
					'timeout'  => 5,
					'blocking' => false,
				)
			);
		}

		return $id;
	}
}

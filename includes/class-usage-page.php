<?php
/**
 * Styble AI → Token Usage.
 *
 * Reads what Styble_AI_Usage_Tracker recorded and shows it: headline totals, the
 * cache hit rate, then breakdowns by day, model and operation, then the last few
 * calls individually.
 *
 * The per-call table is the part worth having. A total tells you that a page cost
 * something; the call list tells you that the planner ran three times because the
 * first two 429'd, or that one section burned a corrective retry — and those are
 * the numbers you act on. It is also why the tracker records before parsing the
 * response: a failed call with an HTTP status in this table is a call you paid
 * for and could not otherwise see.
 *
 * @package Styble_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Styble_AI_Usage_Page {

	/**
	 * Submenu slug, under the Styble AI top-level menu owned by the chat screen.
	 */
	const SLUG = 'styble-ai-usage';

	/**
	 * admin-post action for the reset button.
	 */
	const RESET_ACTION = 'styble_ai_reset_usage';

	/**
	 * How many recent calls to list. The tracker keeps more (LOG_LIMIT); this is
	 * just how much fits on a screen without paging.
	 */
	const SHOW_CALLS = 30;

	/**
	 * How many daily rows to show.
	 */
	const SHOW_DAYS = 14;

	public function register() {
		// Priority 30: after the chat screen's top-level menu (10) and Settings
		// (20), so this lands last in the submenu.
		add_action( 'admin_menu', array( $this, 'menu' ), 30 );
		add_action( 'admin_post_' . self::RESET_ACTION, array( $this, 'handle_reset' ) );
	}

	public function menu() {
		add_submenu_page(
			Styble_AI_Chat_Page::SLUG,
			__( 'Styble AI Token Usage', 'styble-ai' ),
			__( 'Token Usage', 'styble-ai' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Clear the counters, then redirect back. A POST through admin-post rather
	 * than a link, because a GET that deletes is a link a crawler or a prefetch
	 * can follow.
	 *
	 * @return void
	 */
	public function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You cannot do that.', 'styble-ai' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::RESET_ACTION );

		Styble_AI_Usage_Tracker::reset();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::SLUG,
					'reset' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$totals = Styble_AI_Usage_Tracker::totals();
		$log    = Styble_AI_Usage_Tracker::log();
		$prompt = $totals['in'] + $totals['cache_write'] + $totals['cache_read'];

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Token Usage', 'styble-ai' ); ?></h1>

			<?php if ( isset( $_GET['reset'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Token counters cleared.', 'styble-ai' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $totals['calls'] ) : ?>
				<p><?php esc_html_e( 'Nothing recorded yet. Generate a section from the editor sidebar, or build a page from AI Chat, and the numbers land here.', 'styble-ai' ); ?></p>
			<?php else : ?>

				<p class="description">
					<?php
					printf(
						/* translators: 1: first recorded date, 2: last recorded date, 3: number of API calls */
						esc_html__( '%3$s API calls recorded, %1$s to %2$s. Every model call counts here, including the corrective retry and calls that failed after the model had already generated.', 'styble-ai' ),
						esc_html( $this->when( $totals['first'] ) ),
						esc_html( $this->when( $totals['last'] ) ),
						esc_html( number_format_i18n( $totals['calls'] ) )
					);
					?>
				</p>

				<style>
					.styble-ai-usage-cards { display: flex; flex-wrap: wrap; gap: 12px; margin: 16px 0 24px; }
					.styble-ai-usage-card { background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; padding: 12px 16px; min-width: 140px; }
					.styble-ai-usage-card b { display: block; font-size: 20px; line-height: 1.3; }
					.styble-ai-usage-card span { color: #646970; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
					.styble-ai-usage table.widefat { margin-bottom: 28px; max-width: 1000px; }
					.styble-ai-usage td.num, .styble-ai-usage th.num { text-align: right; }
					.styble-ai-usage .bad { color: #b32d2e; }
				</style>

				<div class="styble-ai-usage">
					<div class="styble-ai-usage-cards">
						<?php
						$this->card( __( 'Calls', 'styble-ai' ), number_format_i18n( $totals['calls'] ) );
						$this->card( __( 'Prompt tokens', 'styble-ai' ), number_format_i18n( $prompt ) );
						$this->card( __( 'Output tokens', 'styble-ai' ), number_format_i18n( $totals['out'] ) );
						$this->card( __( 'Cache read', 'styble-ai' ), number_format_i18n( $totals['cache_read'] ) );
						$this->card( __( 'Cache written', 'styble-ai' ), number_format_i18n( $totals['cache_write'] ) );
						$this->card( __( 'Cache hit rate', 'styble-ai' ), $this->hit_rate( $totals ) );
						$this->card( __( 'Est. cost', 'styble-ai' ), $this->money( $totals['cost'] ) );
						if ( $totals['errors'] ) {
							$this->card( __( 'Failed calls', 'styble-ai' ), number_format_i18n( $totals['errors'] ) );
						}
						?>
					</div>

					<?php if ( $totals['unpriced'] ) : ?>
						<div class="notice notice-info inline">
							<p>
								<?php
								printf(
									/* translators: %s: number of calls with no known rate */
									esc_html__( '%s calls used a model with no rate in the price table, so they are counted in the token figures but not in the cost. Add one with the styble_ai_token_rates filter.', 'styble-ai' ),
									esc_html( number_format_i18n( $totals['unpriced'] ) )
								);
								?>
							</p>
						</div>
					<?php endif; ?>

					<p class="description">
						<?php esc_html_e( 'Cost is estimated from published list prices for Claude models — it is an indication, not your bill. Read your provider dashboard for the real figure.', 'styble-ai' ); ?>
					</p>

					<h2><?php esc_html_e( 'By day', 'styble-ai' ); ?></h2>
					<?php $this->breakdown( $this->recent_days( $totals['by_day'] ), __( 'Day', 'styble-ai' ) ); ?>

					<h2><?php esc_html_e( 'By model', 'styble-ai' ); ?></h2>
					<?php $this->breakdown( $totals['by_model'], __( 'Model', 'styble-ai' ) ); ?>

					<h2><?php esc_html_e( 'By operation', 'styble-ai' ); ?></h2>
					<p class="description"><?php esc_html_e( 'plan is one page outline; section is one section of a page, or one generation from the editor sidebar; edit is a selection rewritten with ✦ Edit with AI.', 'styble-ai' ); ?></p>
					<?php $this->breakdown( $totals['by_operation'], __( 'Operation', 'styble-ai' ) ); ?>

					<h2><?php esc_html_e( 'Recent calls', 'styble-ai' ); ?></h2>
					<?php $this->calls( array_slice( $log, 0, self::SHOW_CALLS ) ); ?>
				</div>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::RESET_ACTION ); ?>" />
					<?php wp_nonce_field( self::RESET_ACTION ); ?>
					<?php
					submit_button(
						__( 'Clear token counters', 'styble-ai' ),
						'delete',
						'submit',
						false,
						array( 'onclick' => 'return confirm(\'' . esc_js( __( 'Clear every recorded token figure? This cannot be undone.', 'styble-ai' ) ) . '\');' )
					);
					?>
				</form>

			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param string $label Card label.
	 * @param string $value Card value, already formatted.
	 *
	 * @return void
	 */
	private function card( $label, $value ) {
		printf(
			'<div class="styble-ai-usage-card"><b>%s</b><span>%s</span></div>',
			esc_html( $value ),
			esc_html( $label )
		);
	}

	/**
	 * One breakdown table.
	 *
	 * @param array  $buckets key => bucket row.
	 * @param string $heading Column heading for the key.
	 *
	 * @return void
	 */
	private function breakdown( array $buckets, $heading ) {
		if ( ! $buckets ) {
			echo '<p class="description">' . esc_html__( 'Nothing here yet.', 'styble-ai' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php echo esc_html( $heading ); ?></th>
					<th class="num"><?php esc_html_e( 'Calls', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Input', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Cache read', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Cache write', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Output', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Est. cost', 'styble-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $buckets as $key => $row ) : ?>
					<tr>
						<td><?php echo esc_html( $key ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['calls'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['in'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['cache_read'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['cache_write'] ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $row['out'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $this->money( $row['cost'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The per-call table.
	 *
	 * @param array $calls Log rows, newest first.
	 *
	 * @return void
	 */
	private function calls( array $calls ) {
		if ( ! $calls ) {
			echo '<p class="description">' . esc_html__( 'Nothing here yet.', 'styble-ai' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'When', 'styble-ai' ); ?></th>
					<th><?php esc_html_e( 'Operation', 'styble-ai' ); ?></th>
					<th><?php esc_html_e( 'Model', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Input', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Cache r/w', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Output', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Time', 'styble-ai' ); ?></th>
					<th class="num"><?php esc_html_e( 'Est. cost', 'styble-ai' ); ?></th>
					<th><?php esc_html_e( 'HTTP', 'styble-ai' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $calls as $call ) :
					$failed = ( (int) $call['http'] < 200 || (int) $call['http'] >= 300 );
					?>
					<tr<?php echo $failed ? ' class="bad"' : ''; ?>>
						<td><?php echo esc_html( $this->when( (int) $call['at'] ) ); ?></td>
						<td><?php echo esc_html( $call['operation'] ); ?></td>
						<td>
							<?php echo esc_html( $call['model'] ); ?>
							<br /><small><?php echo esc_html( $call['provider'] ); ?></small>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $call['in'] ) ); ?></td>
						<td class="num">
							<?php
							echo esc_html(
								number_format_i18n( (int) $call['cache_read'] ) . ' / ' . number_format_i18n( (int) $call['cache_write'] )
							);
							?>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( (int) $call['out'] ) ); ?></td>
						<td class="num"><?php echo esc_html( $this->seconds( (int) $call['ms'] ) ); ?></td>
						<td class="num"><?php echo esc_html( null === $call['cost'] ? '—' : $this->money( $call['cost'] ) ); ?></td>
						<td><?php echo esc_html( (int) $call['http'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * The last SHOW_DAYS daily buckets, newest first.
	 *
	 * @param array $by_day Day buckets, oldest first.
	 *
	 * @return array
	 */
	private function recent_days( array $by_day ) {
		krsort( $by_day );

		return array_slice( $by_day, 0, self::SHOW_DAYS, true );
	}

	/**
	 * Share of prompt tokens that came from the cache.
	 *
	 * The one number that says whether prefix caching is working at all. A page
	 * is a burst of near-identical prompts, so this should be high on the
	 * Anthropic path; near zero across many calls means the prefix is not
	 * byte-identical and every call is paying full price for the same 21KB.
	 *
	 * @param array $totals Aggregates.
	 *
	 * @return string
	 */
	private function hit_rate( array $totals ) {
		$prompt = $totals['in'] + $totals['cache_write'] + $totals['cache_read'];
		if ( ! $prompt ) {
			return '—';
		}

		return round( 100 * $totals['cache_read'] / $prompt ) . '%';
	}

	/**
	 * @param float|null $dollars Amount.
	 *
	 * @return string
	 */
	private function money( $dollars ) {
		$dollars = (float) $dollars;
		if ( 0.0 === $dollars ) {
			return '$0.00';
		}
		// Sub-cent amounts are normal for a single call; rounding them all to
		// $0.00 would make the per-call table useless.
		$decimals = ( $dollars < 0.01 ) ? 4 : 2;

		return '$' . number_format_i18n( $dollars, $decimals );
	}

	/**
	 * @param int $ms Milliseconds.
	 *
	 * @return string
	 */
	private function seconds( $ms ) {
		if ( $ms <= 0 ) {
			return '—';
		}

		return number_format_i18n( $ms / 1000, 1 ) . 's';
	}

	/**
	 * @param int $timestamp Unix timestamp, UTC.
	 *
	 * @return string Site-local, short.
	 */
	private function when( $timestamp ) {
		if ( ! $timestamp ) {
			return '—';
		}

		return wp_date( 'M j, H:i', (int) $timestamp );
	}
}

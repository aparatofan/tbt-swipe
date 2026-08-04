<?php
/**
 * The public-facing management page: [tbt_swipe_generator] and
 * [tbt_swipe_sets].
 *
 * Two shortcodes rather than one so they can be split across separate pages
 * later without a code change, even though V1 puts both on /swipe/.
 *
 * Neither renders anything at all for a user without TBTS_CAP — not even a
 * "no access" notice. Students land on this page too, and there is no reason
 * to advertise a tool they cannot use.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Frontend {

	const GENERATOR_SHORTCODE = 'tbt_swipe_generator';
	const SETS_SHORTCODE      = 'tbt_swipe_sets';

	public function __construct() {
		add_shortcode( self::GENERATOR_SHORTCODE, array( $this, 'render_generator' ) );
		add_shortcode( self::SETS_SHORTCODE, array( $this, 'render_sets' ) );
	}

	/**
	 * The generate → review → save flow.
	 *
	 * @return string
	 */
	public function render_generator() {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return '';
		}

		$user_id = get_current_user_id();
		$classes = TBTS_Classes::for_teacher( $user_id );
		$max     = TBTS_Generator::max_cards_per_generation();

		ob_start();
		?>
		<div class="tbts-fe tbts-fe-generator" id="tbts-fe-generator">
			<div class="tbts-fe-notice tbts-fe-error" data-role="error" hidden></div>

			<div class="tbts-fe-step">
				<label class="tbts-fe-label" for="tbts-fe-title"><?php esc_html_e( 'Tytuł zestawu', 'tbt-swipe' ); ?></label>
				<input type="text" id="tbts-fe-title" class="tbts-fe-input" required
					placeholder="<?php esc_attr_e( 'np. Unit 4 — podróże', 'tbt-swipe' ); ?>">
			</div>

			<?php if ( ! empty( $classes ) ) : ?>
				<div class="tbts-fe-step">
					<label class="tbts-fe-label" for="tbts-fe-class"><?php esc_html_e( 'Klasa', 'tbt-swipe' ); ?></label>
					<select id="tbts-fe-class" class="tbts-fe-select">
						<option value="" selected><?php esc_html_e( 'Bez klasy', 'tbt-swipe' ); ?></option>
						<?php foreach ( $classes as $class ) : ?>
							<option value="<?php echo esc_attr( (int) $class['id'] ); ?>">
								<?php echo esc_html( $class['title'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="tbts-fe-help"><?php esc_html_e( 'Przypisz do klasy, żeby uczniowie mogli wrócić do zestawu.', 'tbt-swipe' ); ?></p>

					<div class="tbts-fe-lesson-wrap" data-role="lesson-wrap" hidden>
						<label class="tbts-fe-label" for="tbts-fe-lesson"><?php esc_html_e( 'Lekcja', 'tbt-swipe' ); ?></label>
						<select id="tbts-fe-lesson" class="tbts-fe-select">
							<option value="" selected></option>
						</select>
					</div>
				</div>
			<?php endif; ?>

			<div class="tbts-fe-step">
				<label class="tbts-fe-label" for="tbts-fe-terms"><?php esc_html_e( 'Słowa', 'tbt-swipe' ); ?></label>
				<textarea id="tbts-fe-terms" class="tbts-fe-textarea" rows="10" spellcheck="false"
					placeholder="<?php esc_attr_e( 'Jedno słowo lub wyrażenie w linii', 'tbt-swipe' ); ?>"></textarea>
				<p class="tbts-fe-help">
					<span data-role="count">0</span>
					<span data-role="hint"></span>
				</p>
				<button type="button" class="tbts-fe-btn tbts-fe-btn-primary" data-role="generate">
					<?php esc_html_e( 'Generuj karty', 'tbt-swipe' ); ?>
				</button>
				<span class="tbts-fe-status" data-role="generate-status"></span>
			</div>

			<div class="tbts-fe-step tbts-fe-review" data-role="review" hidden>
				<h3 class="tbts-fe-heading"><?php esc_html_e( 'Sprawdź i popraw', 'tbt-swipe' ); ?></h3>
				<p class="tbts-fe-help">
					<?php esc_html_e( 'Każde pole możesz poprawić przed zapisaniem. Po zapisaniu zestawu kart nie da się już edytować tutaj.', 'tbt-swipe' ); ?>
				</p>
				<div class="tbts-fe-table-scroll">
					<table class="tbts-fe-table">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Słowo', 'tbt-swipe' ); ?></th>
								<th><?php esc_html_e( 'Wymowa', 'tbt-swipe' ); ?></th>
								<th><?php esc_html_e( 'Tłumaczenie', 'tbt-swipe' ); ?></th>
								<th><?php esc_html_e( 'Przykład', 'tbt-swipe' ); ?></th>
								<th class="tbts-fe-col-tool"><span class="screen-reader-text"><?php esc_html_e( 'Usuń', 'tbt-swipe' ); ?></span></th>
							</tr>
						</thead>
						<tbody data-role="review-body"></tbody>
					</table>
				</div>
				<button type="button" class="tbts-fe-btn tbts-fe-btn-primary" data-role="save">
					<?php esc_html_e( 'Zapisz zestaw', 'tbt-swipe' ); ?>
				</button>
				<span class="tbts-fe-status" data-role="save-status"></span>
			</div>

			<div class="tbts-fe-step tbts-fe-result" data-role="result" hidden>
				<h3 class="tbts-fe-heading"><?php esc_html_e( 'Zestaw zapisany', 'tbt-swipe' ); ?></h3>
				<div class="tbts-fe-link-row">
					<input type="text" class="tbts-fe-input tbts-fe-url" data-role="result-url" readonly>
					<button type="button" class="tbts-fe-btn" data-role="copy"><?php esc_html_e( 'Kopiuj link', 'tbt-swipe' ); ?></button>
				</div>
				<div class="tbts-fe-qr" data-role="result-qr"></div>
				<p>
					<button type="button" class="tbts-fe-btn" data-role="reset">
						<?php esc_html_e( 'Zrób kolejny zestaw', 'tbt-swipe' ); ?>
					</button>
				</p>
			</div>
		</div>
		<?php
		$html = ob_get_clean();

		// A cached page can render the shortcode without wp_enqueue_scripts
		// having matched it, so make sure the assets are queued either way.
		TBTS_Shortcode::enqueue_frontend( $max );

		return $html;
	}

	/**
	 * The teacher's own sets, grouped by class.
	 *
	 * Server-rendered rather than fetched, so it paints in one pass inside
	 * Divi with no loading flash.
	 *
	 * @return string
	 */
	public function render_sets() {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return '';
		}

		// Owner-scoped with no exception — administrators included. wp-admin
		// stays the place to see everyone's sets.
		$sets   = TBTS_DB::get_sets( get_current_user_id() );
		$groups = $this->group_by_class( $sets );

		ob_start();
		?>
		<div class="tbts-fe tbts-fe-sets" id="tbts-fe-sets">
			<div class="tbts-fe-notice tbts-fe-error" data-role="error" hidden></div>

			<?php if ( empty( $sets ) ) : ?>
				<p class="tbts-fe-empty"><?php esc_html_e( 'Nie masz jeszcze żadnych zestawów.', 'tbt-swipe' ); ?></p>
			<?php else : ?>
				<?php foreach ( $groups as $group ) : ?>
					<section class="tbts-fe-group">
						<h3 class="tbts-fe-group-title"><?php echo esc_html( $group['title'] ); ?></h3>
						<ul class="tbts-fe-list">
							<?php foreach ( $group['sets'] as $set ) : ?>
								<?php $this->render_set_row( $set ); ?>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="tbts-fe-modal" data-role="qr-modal" hidden>
				<div class="tbts-fe-modal-box" role="dialog" aria-modal="true">
					<p class="tbts-fe-modal-title" data-role="qr-title"></p>
					<div class="tbts-fe-qr" data-role="qr-target"></div>
					<button type="button" class="tbts-fe-btn" data-role="qr-close"><?php esc_html_e( 'Zamknij', 'tbt-swipe' ); ?></button>
				</div>
			</div>
		</div>
		<?php
		$html = ob_get_clean();

		TBTS_Shortcode::enqueue_frontend( TBTS_Generator::max_cards_per_generation() );

		return $html;
	}

	/**
	 * One set in the list.
	 *
	 * @param object $set Set row with card_count.
	 */
	private function render_set_row( $set ) {
		$deck_url    = TBTS_DB::deck_url( $set );
		$lesson_name = $set->lesson_id ? TBTS_Classes::lesson_title( (int) $set->lesson_id ) : '';
		?>
		<li class="tbts-fe-row" data-set-id="<?php echo esc_attr( (int) $set->id ); ?>">
			<div class="tbts-fe-row-main">
				<span class="tbts-fe-row-title"><?php echo esc_html( $set->title ); ?></span>
				<span class="tbts-fe-row-meta">
					<?php echo esc_html( self::card_count_label( (int) $set->card_count ) ); ?>
					<?php if ( '' !== $lesson_name ) : ?>
						<span class="tbts-fe-sep">·</span><?php echo esc_html( $lesson_name ); ?>
					<?php endif; ?>
					<span class="tbts-fe-sep">·</span><?php echo esc_html( mysql2date( get_option( 'date_format' ), $set->created ) ); ?>
				</span>
			</div>
			<div class="tbts-fe-row-actions">
				<?php if ( '' !== $deck_url ) : ?>
					<a class="tbts-fe-btn tbts-fe-btn-small" href="<?php echo esc_url( $deck_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Otwórz', 'tbt-swipe' ); ?>
					</a>
					<button type="button" class="tbts-fe-btn tbts-fe-btn-small" data-role="copy"
						data-url="<?php echo esc_url( $deck_url ); ?>"><?php esc_html_e( 'Kopiuj link', 'tbt-swipe' ); ?></button>
					<button type="button" class="tbts-fe-btn tbts-fe-btn-small" data-role="qr"
						data-url="<?php echo esc_url( $deck_url ); ?>"
						data-title="<?php echo esc_attr( $set->title ); ?>"><?php esc_html_e( 'Kod QR', 'tbt-swipe' ); ?></button>
				<?php endif; ?>
				<button type="button" class="tbts-fe-btn tbts-fe-btn-small tbts-fe-btn-danger" data-role="delete">
					<?php esc_html_e( 'Usuń', 'tbt-swipe' ); ?>
				</button>
			</div>
		</li>
		<?php
	}

	/**
	 * "1 karta" / "3 karty" / "7 kart".
	 *
	 * The UI language here is Polish by design rather than by translation, so
	 * _n() would only ever give two forms — Polish needs three.
	 *
	 * @param int $count Number of cards.
	 * @return string
	 */
	private static function card_count_label( $count ) {
		if ( 1 === $count ) {
			return $count . ' ' . __( 'karta', 'tbt-swipe' );
		}

		$last_two = $count % 100;
		$last_one = $count % 10;

		if ( $last_one >= 2 && $last_one <= 4 && ( $last_two < 12 || $last_two > 14 ) ) {
			return $count . ' ' . __( 'karty', 'tbt-swipe' );
		}

		return $count . ' ' . __( 'kart', 'tbt-swipe' );
	}

	/**
	 * Group sets by class, with the unattached group last.
	 *
	 * "Bez klasy" is rendered as an ordinary group — same styling as the rest.
	 * An unattached set is a supported state, not an orphan to flag.
	 *
	 * @param object[] $sets Sets with card_count.
	 * @return array[] Each array( 'title' => string, 'sets' => object[] ).
	 */
	private function group_by_class( $sets ) {
		$attached   = array();
		$unattached = array();

		foreach ( $sets as $set ) {
			$class_id = (int) $set->class_id;
			if ( ! $class_id ) {
				$unattached[] = $set;
				continue;
			}
			if ( ! isset( $attached[ $class_id ] ) ) {
				$title = TBTS_Classes::class_title( $class_id );
				$attached[ $class_id ] = array(
					// Notes may be inactive, or the class since deleted. The
					// set still works; it just loses its group name.
					'title' => '' !== $title ? $title : __( 'Klasa', 'tbt-swipe' ),
					'sets'  => array(),
				);
			}
			$attached[ $class_id ]['sets'][] = $set;
		}

		$groups = array_values( $attached );

		if ( ! empty( $unattached ) ) {
			$groups[] = array(
				'title' => __( 'Bez klasy', 'tbt-swipe' ),
				'sets'  => $unattached,
			);
		}

		return $groups;
	}
}

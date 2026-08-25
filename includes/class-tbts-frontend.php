<?php
/**
 * The public-facing management page: [tbt_swipe_generator] and
 * [tbt_swipe_sets].
 *
 * Two shortcodes rather than one so they can live on separate pages: since
 * 1.8.0 [tbt_swipe_sets] is the library and [tbt_swipe_generator] the
 * workspace, and they find each other through the generator and library
 * attributes. Both on one page still works, with no attributes at all.
 *
 * Neither renders anything at all for a user without TBTS_CAP — not even a
 * "no access" notice. Students land on this page too, and there is no reason
 * to advertise a tool they cannot use.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Frontend {

	const GENERATOR_SHORTCODE = 'tbt_swipe_generator';
	const SETS_SHORTCODE      = 'tbt_swipe_sets';

	/**
	 * Query parameter that puts the builder into edit mode. Mirrored by
	 * EDIT_PARAM in assets/js/frontend.js, which reads it back.
	 */
	const EDIT_PARAM = 'tbts_edit';

	public function __construct() {
		add_shortcode( self::GENERATOR_SHORTCODE, array( $this, 'render_generator' ) );
		add_shortcode( self::SETS_SHORTCODE, array( $this, 'render_sets' ) );
	}

	/**
	 * The generate → review → save flow.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_generator( $atts = array() ) {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return '';
		}

		/*
		 * The Tool Hero is now supplied by the global Divi header row on the
		 * page. hero="no" suppresses the built-in one so the two do not stack.
		 * The default stays "yes" so a page that has not been migrated is
		 * never left without a header.
		 *
		 * library names the page holding [tbt_swipe_sets]. Empty means there
		 * is none to go back to, and every link to one is left out rather
		 * than pointed somewhere wrong.
		 */
		$atts = shortcode_atts(
			array(
				'hero'    => 'yes',
				'library' => '',
			),
			is_array( $atts ) ? $atts : array(),
			self::GENERATOR_SHORTCODE
		);

		$show_hero   = self::show_hero( $atts['hero'] );
		$library_url = self::library_url( (string) $atts['library'] );

		$user_id = get_current_user_id();
		$classes = TBTS_Classes::for_teacher( $user_id );
		$max     = TBTS_Generator::max_cards_per_generation();

		// The picker opens on the band this teacher last generated with, so a
		// teacher who always works at one level never touches it. A class
		// suggestion, when there is one, overrides this in JS.
		$level_initial = TBTS_Levels::initial_band( $user_id );
		$level_names   = TBTS_Levels::band_names();

		ob_start();
		?>
		<div class="tbt" id="tbts-fe-generator" data-library-url="<?php echo esc_url( $library_url ); ?>">
		<div class="tbt-page">
		<div class="tbt-wrap">

			<?php if ( $show_hero ) : ?>
				<?php
				/*
				 * Two sentences, two lines, two strings: the break is
				 * structural rather than a <br>, so a translation that runs
				 * longer still gets its own line.
				 */
				$this->render_hero(
					array(
						__( 'Your friendly flashcard tool.', 'tbt-swipe' ),
						__( 'Words from lessons become decks your students can play on their phones.', 'tbt-swipe' ),
					)
				);
				?>
			<?php endif; ?>

			<?php if ( '' !== $library_url ) : ?>
				<?php
				/*
				 * A link, not a button: it navigates, it does not act. Left
				 * out entirely when no library page is configured, so it can
				 * never lead somewhere that is not there.
				 */
				?>
				<a class="tbt-backlink" href="<?php echo esc_url( $library_url ); ?>"><?php esc_html_e( '← Back to your decks', 'tbt-swipe' ); ?></a>
			<?php endif; ?>

			<div class="tbt-notice tbt-notice--error" data-role="error" hidden></div>

			<?php
			/*
			 * Edit mode announces itself: the same three stages, but every
			 * save lands on an existing deck. Cancel is a link, not a button,
			 * because it does nothing but leave.
			 */
			?>
			<div class="tbt-editing" data-role="editing" hidden>
				<span>
					<?php esc_html_e( 'Editing deck:', 'tbt-swipe' ); ?>
					<strong data-role="editing-title"></strong>
				</span>
				<span class="tbt-editing-actions">
					<?php
					/*
					 * Cancel leaves for the library when there is one: after a
					 * discard, and on a page that no longer lists decks, the
					 * generator is not somewhere to be returned to.
					 */
					?>
					<a class="tbt-editing-cancel" href="<?php echo esc_url( '' !== $library_url ? $library_url : self::builder_url() ); ?>" data-role="cancel-edit"><?php esc_html_e( 'Cancel', 'tbt-swipe' ); ?></a>
					<?php if ( '' !== $library_url ) : ?>
						<?php
						/*
						 * Drafts only, and JS unhides it once the loaded deck
						 * says which it is. Deleting a published deck stays in
						 * the library, where the row it removes is in view.
						 */
						?>
						<button type="button" class="tbt-btn tbt-btn--ghost tbt-btn--danger" data-role="discard" hidden>
							<?php esc_html_e( 'Discard', 'tbt-swipe' ); ?>
						</button>
					<?php endif; ?>
				</span>
			</div>

			<section class="tbt-stage" data-stage="1" data-state="active">
				<div class="tbt-stage-card">
					<div class="tbt-stage-head">
						<h2 class="tbt-stage-title"><b><?php esc_html_e( 'Stage 1.', 'tbt-swipe' ); ?></b> <?php esc_html_e( 'Admin', 'tbt-swipe' ); ?></h2>
						<p class="tbt-stage-sub"><?php esc_html_e( 'Name the deck, choose the class and lesson.', 'tbt-swipe' ); ?></p>
					</div>

					<div class="tbt-field">
						<label class="tbt-label" for="tbts-fe-title"><?php esc_html_e( 'Deck title', 'tbt-swipe' ); ?></label>
						<input type="text" id="tbts-fe-title" class="tbt-input" required
							placeholder="<?php esc_attr_e( 'e.g. Unit 4 — travel', 'tbt-swipe' ); ?>">
					</div>

					<?php
					/*
					 * An open deck belongs to no class and no lesson: it is
					 * shared by its link alone. The radio sits above the class
					 * field because it decides whether that field applies at
					 * all, and it is rendered even for a teacher with no
					 * classes — the deck type is a property of the deck, not
					 * of what TBT Notes happens to hold.
					 */
					?>
					<fieldset class="tbt-field tbt-radios" data-role="deck-type">
						<legend class="tbt-label"><?php esc_html_e( 'Deck type', 'tbt-swipe' ); ?></legend>
						<div class="tbt-radio-row">
							<label class="tbt-radio">
								<input type="radio" name="tbts-deck-type" value="class" checked>
								<span><?php esc_html_e( 'Class deck', 'tbt-swipe' ); ?></span>
							</label>
							<label class="tbt-radio">
								<input type="radio" name="tbts-deck-type" value="open">
								<span><?php esc_html_e( 'Open deck', 'tbt-swipe' ); ?></span>
							</label>
						</div>
						<p class="tbt-help"><?php esc_html_e( 'An open deck has no class and no lesson. It is shared by its link like any other deck.', 'tbt-swipe' ); ?></p>
					</fieldset>

					<?php if ( ! empty( $classes ) ) : ?>
						<div class="tbt-field tbt-pair" data-role="class-pair">
							<div>
								<label class="tbt-label" for="tbts-fe-class"><?php esc_html_e( 'Class', 'tbt-swipe' ); ?></label>
								<?php
								/*
								 * A text field bound to a datalist rather than a select: a
								 * teacher with thirty classes types two letters and the list
								 * narrows, which a select cannot do. The datalist hands back
								 * the class name, so the id it stands for is resolved in JS
								 * and parked in the hidden field the save reads.
								 */
								?>
								<input type="text" id="tbts-fe-class" class="tbt-input" list="tbts-fe-class-list"
									autocomplete="off" spellcheck="false"
									placeholder="<?php esc_attr_e( 'No class — type to filter', 'tbt-swipe' ); ?>">
								<datalist id="tbts-fe-class-list">
									<?php foreach ( $classes as $class ) : ?>
										<option value="<?php echo esc_attr( $class['title'] ); ?>"
											data-id="<?php echo esc_attr( (int) $class['id'] ); ?>"></option>
									<?php endforeach; ?>
								</datalist>
								<input type="hidden" data-role="class-id" value="">
								<p class="tbt-help" data-role="class-hint" hidden>
									<strong><?php esc_html_e( 'No class matches that. Pick one from the list, or clear the field to save without a class.', 'tbt-swipe' ); ?></strong>
								</p>
							</div>

							<div data-role="lesson-wrap" hidden>
								<label class="tbt-label" for="tbts-fe-lesson"><?php esc_html_e( 'Lesson', 'tbt-swipe' ); ?></label>
								<select id="tbts-fe-lesson" class="tbt-select">
									<option value="" selected></option>
								</select>
							</div>
						</div>
					<?php endif; ?>
				</div>
			</section>

			<section class="tbt-stage" data-stage="2" data-state="active">
				<div class="tbt-stage-card">
					<div class="tbt-stage-head">
						<h2 class="tbt-stage-title"><b><?php esc_html_e( 'Stage 2.', 'tbt-swipe' ); ?></b> <?php esc_html_e( 'Content', 'tbt-swipe' ); ?></h2>
						<p class="tbt-stage-sub"><?php esc_html_e( 'Add words, generate cards, edit and approve the outcome.', 'tbt-swipe' ); ?></p>
					</div>

					<div class="tbt-compose">
						<div>
							<div class="tbt-field">
								<label class="tbt-label" for="tbts-fe-terms"><?php esc_html_e( 'Words', 'tbt-swipe' ); ?></label>
								<textarea id="tbts-fe-terms" class="tbt-textarea" rows="10" spellcheck="false"
									placeholder="<?php esc_attr_e( 'One word or phrase per line', 'tbt-swipe' ); ?>"></textarea>
								<div class="tbt-meter">
									<?php
									// No live word count here: the preview stack already
									// counts the lines, and two counters that can disagree
									// are worse than one.
									?>
									<span data-role="hint">
										<?php
										printf(
											/* translators: %s: maximum number of words per deck, already wrapped in <strong> */
											esc_html__( 'Up to %s words per deck', 'tbt-swipe' ),
											'<strong>' . esc_html( (int) $max ) . '</strong>'
										);
										?>
									</span>
								</div>
							</div>

							<?php
							/*
							 * Real radios, visually hidden and wrapped in labels: arrow-key
							 * navigation, the roving tab stop and the reading order come
							 * from the browser rather than from JS. Divs with click
							 * handlers would look identical and be unreachable from a
							 * keyboard.
							 */
							?>
							<fieldset class="tbt-levels" data-role="levels">
								<legend class="tbt-label"><?php esc_html_e( 'Choose the level of examples.', 'tbt-swipe' ); ?></legend>
								<div class="tbt-levels-grid">
									<?php foreach ( $level_names as $band => $name ) : ?>
										<label class="tbt-level">
											<input type="radio" class="tbt-level-input" name="tbts-level"
												value="<?php echo esc_attr( $band ); ?>"
												<?php checked( $band, $level_initial ); ?>>
											<span class="tbt-level-box">
												<span class="tbt-level-code"><?php echo esc_html( $band ); ?></span>
												<span class="tbt-level-name"><?php echo esc_html( $name ); ?></span>
											</span>
										</label>
									<?php endforeach; ?>
								</div>
								<?php
								// Where the current value came from. Written by the class
								// suggestion and cleared the moment the teacher chooses by
								// hand — a note that outlived its choice would be a lie.
								?>
								<p class="tbt-help tbt-level-note" data-role="level-note" hidden></p>
							</fieldset>

							<button type="button" class="tbt-btn tbt-btn--primary" data-role="generate">
								<?php esc_html_e( 'Generate cards', 'tbt-swipe' ); ?>
							</button>
							<p class="tbt-help" data-role="generate-status"></p>
						</div>

						<aside class="tbt-stack-rail">
							<p class="tbt-eyebrow"><?php esc_html_e( 'preview', 'tbt-swipe' ); ?></p>
							<div class="tbt-stack" data-role="stack" data-empty="true">
								<div class="tbt-stack-layer"></div>
								<div class="tbt-stack-layer"></div>
								<div class="tbt-stack-layer"></div>
								<div class="tbt-stack-face">
									<span class="tbt-stack-term" data-role="stack-term"><?php esc_html_e( 'Your first card lands here', 'tbt-swipe' ); ?></span>
								</div>
							</div>
							<p class="tbt-stack-count">
								<strong data-role="stack-count">0</strong>
								<?php esc_html_e( 'cards in this deck', 'tbt-swipe' ); ?>
							</p>
						</aside>
					</div>

					<div class="tbt-review" data-role="review" hidden>
						<h3 class="tbt-review-title"><?php esc_html_e( 'Check and fix', 'tbt-swipe' ); ?></h3>
						<p class="tbt-help" style="margin-bottom: var( --tbt-s5 )">
							<?php esc_html_e( 'Edit any field here. You can come back to these cards later with Edit in your deck list.', 'tbt-swipe' ); ?>
						</p>

						<?php
						/*
						 * Which face the students see first. A deck setting, so
						 * a link or QR code already shared follows whatever is
						 * chosen here — there is no URL parameter to re-share.
						 */
						?>
						<fieldset class="tbt-field tbt-radios" data-role="front-face">
							<legend class="tbt-label"><?php esc_html_e( 'Show first', 'tbt-swipe' ); ?></legend>
							<div class="tbt-radio-row">
								<label class="tbt-radio">
									<input type="radio" name="tbts-front-face" value="term" checked>
									<span><?php esc_html_e( 'Term', 'tbt-swipe' ); ?></span>
								</label>
								<label class="tbt-radio">
									<input type="radio" name="tbts-front-face" value="translation">
									<span><?php esc_html_e( 'Translation', 'tbt-swipe' ); ?></span>
								</label>
							</div>
						</fieldset>

						<div class="tbt-colheads">
							<span></span>
							<span><?php esc_html_e( 'Term', 'tbt-swipe' ); ?></span>
							<span><?php esc_html_e( 'Pronunciation', 'tbt-swipe' ); ?></span>
							<span><?php esc_html_e( 'Translation', 'tbt-swipe' ); ?></span>
							<span></span>
						</div>

						<div class="tbt-rows" data-role="review-body"></div>

						<div class="tbt-review-foot">
							<button type="button" class="tbt-btn tbt-btn--primary" data-role="save">
								<?php esc_html_e( 'Save deck', 'tbt-swipe' ); ?>
							</button>
							<button type="button" class="tbt-btn tbt-btn--ghost" data-role="add-row">
								<?php esc_html_e( '+ Add row', 'tbt-swipe' ); ?>
							</button>
							<button type="button" class="tbt-btn tbt-btn--ghost" data-role="start-over">
								<?php esc_html_e( 'Start over', 'tbt-swipe' ); ?>
							</button>
							<p class="tbt-help" data-role="save-status"></p>
						</div>
					</div>
				</div>
			</section>

			<section class="tbt-stage" data-stage="3" data-state="waiting">
				<div class="tbt-stage-card">
					<div class="tbt-stage-head">
						<h2 class="tbt-stage-title"><b><?php esc_html_e( 'Stage 3.', 'tbt-swipe' ); ?></b> <?php esc_html_e( 'Save and share', 'tbt-swipe' ); ?></h2>
						<p class="tbt-stage-sub"><?php esc_html_e( 'Save the deck. Share the deck with your students.', 'tbt-swipe' ); ?></p>
					</div>

					<div data-role="result-wait">
						<p class="tbt-stage-wait">
							<?php esc_html_e( 'Your link and QR code appear here once the deck is saved.', 'tbt-swipe' ); ?>
						</p>
					</div>

					<div class="tbt-saved" data-role="result" hidden>
						<div class="tbt-saved-qr">
							<figure class="tbt-qr" data-role="result-qr"></figure>
							<button type="button" class="tbt-btn tbt-btn--primary" data-role="reset">
								<?php esc_html_e( 'Create another deck', 'tbt-swipe' ); ?>
							</button>
						</div>
						<div>
							<div class="tbt-deck-title" data-role="result-title"></div>
							<div class="tbt-deck-meta" data-role="result-meta"></div>
							<div class="tbt-linkrow">
								<input type="text" class="tbt-input" data-role="result-url" readonly>
								<button type="button" class="tbt-btn tbt-btn--ghost" data-role="copy"><?php esc_html_e( 'Copy link', 'tbt-swipe' ); ?></button>
							</div>
							<div class="tbt-saved-actions">
								<a class="tbt-btn tbt-btn--ghost" data-role="result-open" href="#" target="_blank" rel="noopener">
									<?php esc_html_e( 'Open deck', 'tbt-swipe' ); ?>
								</a>
							</div>
						</div>
					</div>
				</div>
			</section>

		</div>
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
	 * @param array|string $atts Shortcode attributes.
	 * @return string
	 */
	public function render_sets( $atts = array() ) {
		if ( ! TBTS_Capabilities::user_can_manage() ) {
			return '';
		}

		/*
		 * generator names the page holding [tbt_swipe_generator]. Empty falls
		 * back to this page, so a site that still carries both shortcodes
		 * together keeps working with no attribute at all.
		 *
		 * The hero defaults to no: the library has never rendered one, and
		 * defaulting to yes would put a header on every page already live.
		 */
		$atts = shortcode_atts(
			array(
				'generator' => '',
				'hero'      => 'no',
			),
			is_array( $atts ) ? $atts : array(),
			self::SETS_SHORTCODE
		);

		$show_hero     = self::show_hero( $atts['hero'] );
		$generator_url = self::generator_url( (string) $atts['generator'] );

		// Owner-scoped with no exception — administrators included. wp-admin
		// stays the place to see everyone's sets.
		$sets   = TBTS_DB::get_sets( get_current_user_id() );
		$groups = $this->group_by_class( $sets );

		ob_start();
		?>
		<?php
		/*
		 * The generator page goes on the markup rather than into tbtsFe:
		 * the config is localised once per page load and could not describe
		 * two libraries pointed at different generators.
		 */
		?>
		<div class="tbt" id="tbts-fe-sets" data-generator-url="<?php echo esc_url( $generator_url ); ?>">
		<div class="tbt-page">
		<div class="tbt-wrap">

			<?php if ( $show_hero ) : ?>
				<?php $this->render_hero( array( __( 'Every deck you have made', 'tbt-swipe' ) ) ); ?>
			<?php endif; ?>

			<div class="tbt-notice tbt-notice--error" data-role="error" hidden></div>

			<div class="tbt-library-head">
				<div class="tbt-section-head">
					<span class="tbt-section-title"><?php esc_html_e( 'Your decks', 'tbt-swipe' ); ?></span>
					<span class="tbt-group-rule"></span>
				</div>
				<?php if ( '' !== $generator_url ) : ?>
					<?php
					/*
					 * Only when there is a generator to land on. With nowhere
					 * to go the button would name a step it cannot take.
					 */
					?>
					<button type="button" class="tbt-btn tbt-btn--primary" data-role="create">
						<?php esc_html_e( 'Create new deck', 'tbt-swipe' ); ?>
					</button>
				<?php endif; ?>
			</div>

			<?php if ( empty( $sets ) ) : ?>
				<div class="tbt-empty">
					<?php
					// "Generate your first one above" stopped being true the
					// moment the generator moved to its own page.
					if ( '' !== $generator_url ) {
						esc_html_e( 'No decks yet. Create your first one.', 'tbt-swipe' );
					} else {
						esc_html_e( 'No decks yet.', 'tbt-swipe' );
					}
					?>
				</div>
			<?php else : ?>
				<?php foreach ( $groups as $group ) : ?>
					<section class="tbt-group" data-role="group">
						<div class="tbt-group-head">
							<span class="tbt-group-name"><?php echo esc_html( $group['title'] ); ?></span>
							<span class="tbt-group-count"><?php echo esc_html( self::deck_count_label( count( $group['sets'] ) ) ); ?></span>
							<span class="tbt-group-rule"></span>
						</div>
						<?php foreach ( $group['sets'] as $set ) : ?>
							<?php $this->render_set_row( $set, $generator_url ); ?>
						<?php endforeach; ?>
					</section>
				<?php endforeach; ?>
			<?php endif; ?>

			<div class="tbt-modal" data-role="qr-modal" hidden>
				<div class="tbt-modal-box" role="dialog" aria-modal="true">
					<p class="tbt-modal-title" data-role="qr-title"></p>
					<figure class="tbt-qr" data-role="qr-target"></figure>
					<button type="button" class="tbt-btn tbt-btn--ghost" data-role="qr-close"><?php esc_html_e( 'Close', 'tbt-swipe' ); ?></button>
				</div>
			</div>

			<?php
			/*
			 * Rendered here rather than built in JS, like the QR modal above:
			 * every string on this surface stays in PHP where it can be
			 * translated, and inline markup is what already works inside
			 * Divi's page structure.
			 */
			?>
			<div class="tbt-modal tbt-modal--form" data-role="create-modal" hidden>
				<div class="tbt-modal-box tbt-modal-box--form" role="dialog" aria-modal="true"
					aria-labelledby="tbts-create-title">
					<h2 class="tbt-modal-title" id="tbts-create-title"><?php esc_html_e( 'Name your new deck', 'tbt-swipe' ); ?></h2>
					<div class="tbt-field">
						<label class="tbt-label" for="tbts-create-input"><?php esc_html_e( 'Deck title', 'tbt-swipe' ); ?></label>
						<input type="text" id="tbts-create-input" class="tbt-input" maxlength="80"
							autocomplete="off" placeholder="<?php esc_attr_e( 'e.g. Unit 4 — travel', 'tbt-swipe' ); ?>">
					</div>
					<p class="tbt-notice tbt-notice--error" data-role="create-error" hidden></p>
					<div class="tbt-modal-actions">
						<button type="button" class="tbt-btn tbt-btn--ghost" data-role="create-cancel"><?php esc_html_e( 'Cancel', 'tbt-swipe' ); ?></button>
						<button type="button" class="tbt-btn tbt-btn--primary" data-role="create-submit"><?php esc_html_e( 'Create', 'tbt-swipe' ); ?></button>
					</div>
				</div>
			</div>

		</div>
		</div>
		</div>
		<?php
		$html = ob_get_clean();

		TBTS_Shortcode::enqueue_frontend( TBTS_Generator::max_cards_per_generation() );

		return $html;
	}

	/**
	 * One deck in the list.
	 *
	 * The chip names the lesson the deck belongs to. A deck with no lesson is a
	 * supported state, not an error — it says so in the violet chip and carries
	 * a violet spine, so the two kinds are told apart at a glance without one
	 * of them looking broken.
	 *
	 * An open deck is a third kind: deliberately class-less rather than merely
	 * unattached, so it says so in a blue chip and keeps the ordinary blue
	 * spine. Only a class deck that lost — or never had — its lesson stays
	 * violet.
	 *
	 * A draft is a fourth kind, and the only one that loses actions: its slug
	 * resolves to a URL TBTS_Rest answers with a 404, so Open, Copy link and
	 * QR code would each hand the teacher a link that silently fails for
	 * students. Edit and Delete are what a draft is for.
	 *
	 * @param object $set           Deck row with card_count.
	 * @param string $generator_url Page the Edit link points at.
	 */
	private function render_set_row( $set, $generator_url = '' ) {
		$draft       = self::is_draft( $set );
		$deck_url    = $draft ? '' : TBTS_DB::deck_url( $set );
		$open        = TBTS_DB::is_open_deck( $set );
		$lesson_name = ( ! $open && $set->lesson_id ) ? TBTS_Classes::lesson_title( (int) $set->lesson_id ) : '';
		$unattached  = ! $open && '' === $lesson_name;

		if ( $open ) {
			$chip_class = ' tbt-chip--open';
			$chip_text  = __( 'OPEN DECK', 'tbt-swipe' );
		} elseif ( $unattached ) {
			$chip_class = ' tbt-chip--none';
			$chip_text  = __( 'Unattached', 'tbt-swipe' );
		} else {
			$chip_class = '';
			$chip_text  = $lesson_name;
		}
		?>
		<?php
		/*
		 * data-draft is what tells the delete handler which confirmation to
		 * put up: a draft usually holds no cards, so the published wording
		 * overstates what is about to be lost.
		 */
		?>
		<div class="tbt-deck<?php echo $unattached ? ' tbt-deck--none' : ''; ?><?php echo $draft ? ' tbt-deck--draft' : ''; ?>"
			data-set-id="<?php echo esc_attr( (int) $set->id ); ?>"<?php echo $draft ? ' data-draft="1"' : ''; ?>>
			<div class="tbt-deck-body">
				<?php
				/*
				 * The three facts an edit can change are addressed by role, not
				 * by class name: saving an edit rewrites them in place rather
				 * than leaving the row describing the deck as it used to be.
				 */
				?>
				<div class="tbt-deck-title" data-role="deck-title"><?php echo esc_html( $set->title ); ?></div>
				<div class="tbt-deck-meta">
					<span class="tbt-chip<?php echo esc_attr( $chip_class ); ?>" data-role="deck-chip">
						<?php echo esc_html( $chip_text ); ?>
					</span>
					<span data-role="deck-cards"><?php echo esc_html( self::card_count_label( (int) $set->card_count ) ); ?></span>
					<span>·</span>
					<span><?php echo esc_html( mysql2date( get_option( 'date_format' ), $set->created ) ); ?></span>
				</div>
			</div>
			<div class="tbt-deck-actions">
				<?php if ( '' !== $deck_url ) : ?>
					<a class="tbt-btn tbt-btn--ghost tbt-btn--fill" href="<?php echo esc_url( $deck_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open', 'tbt-swipe' ); ?>
					</a>
				<?php endif; ?>
				<?php
				/*
				 * A real link, not a button: edit mode is a state of the page,
				 * so it survives a reload, a bookmark and a middle click. The
				 * builder reads the parameter back on load — see frontend.js.
				 */
				?>
				<a class="tbt-btn tbt-btn--ghost" data-role="edit"
					href="<?php echo esc_url( self::edit_url( (int) $set->id, $generator_url ) ); ?>">
					<?php esc_html_e( 'Edit', 'tbt-swipe' ); ?>
				</a>
				<?php if ( '' !== $deck_url ) : ?>
					<button type="button" class="tbt-btn tbt-btn--ghost" data-role="copy"
						data-url="<?php echo esc_url( $deck_url ); ?>"><?php esc_html_e( 'Copy link', 'tbt-swipe' ); ?></button>
					<button type="button" class="tbt-btn tbt-btn--ghost" data-role="qr"
						data-url="<?php echo esc_url( $deck_url ); ?>"
						data-title="<?php echo esc_attr( $set->title ); ?>"><?php esc_html_e( 'QR code', 'tbt-swipe' ); ?></button>
				<?php endif; ?>
				<button type="button" class="tbt-btn tbt-btn--ghost tbt-btn--danger" data-role="delete">
					<?php esc_html_e( 'Delete', 'tbt-swipe' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * This page with nothing marked for editing — where Cancel goes.
	 *
	 * A plain link rather than a JS teardown: leaving an edit is a fresh page,
	 * so there is no half-restored state to get wrong, and nothing is written
	 * on the way out.
	 *
	 * @return string
	 */
	public static function builder_url() {
		$base = get_permalink();
		return $base ? $base : home_url( '/' );
	}

	/**
	 * The current page with a deck marked for editing.
	 *
	 * Built on the generator page the library was given, falling back to the
	 * current page when it was given none — which is a page still carrying
	 * both shortcodes, where editing reloads where the teacher already is.
	 *
	 * @param int    $set_id        Deck to edit.
	 * @param string $generator_url Page holding the generator, or '' for the
	 *                              current page.
	 * @return string
	 */
	public static function edit_url( $set_id, $generator_url = '' ) {
		$base = '' !== (string) $generator_url ? (string) $generator_url : self::builder_url();
		$url  = add_query_arg( self::EDIT_PARAM, (int) $set_id, $base );

		/**
		 * Filter the URL the Edit button points at.
		 *
		 * @param string $url           Edit URL.
		 * @param int    $set_id        Deck being edited.
		 * @param string $generator_url Generator page the link was built on,
		 *                              or '' when the shortcode named none.
		 */
		return (string) apply_filters( 'tbts_edit_url', $url, (int) $set_id, (string) $generator_url );
	}

	/**
	 * A page URL from a shortcode attribute, or '' when there is nothing
	 * usable in it.
	 *
	 * A site-root-relative value is resolved through home_url() first: a site
	 * owner types "/decks/" into Divi, and esc_url_raw() judges absolute URLs.
	 * Every caller reads '' as "not set" rather than as a URL.
	 *
	 * @param string $value Raw attribute value.
	 * @return string
	 */
	private static function clean_url( string $value ): string {
		$value = trim( $value );

		/*
		 * Divi and wptexturize between them can hand the attribute back with
		 * its quotes still attached — curly ones especially, which the
		 * shortcode parser does not recognise as quoting and so leaves inside
		 * the value. Stripping them turns a silent misresolve into a working
		 * URL, and costs one pass.
		 */
		$value = trim( (string) preg_replace( '/^[\'"\x{201C}\x{201D}\x{2018}\x{2019}]+|[\'"\x{201C}\x{201D}\x{2018}\x{2019}]+$/u', '', $value ) );

		if ( '' === $value ) {
			return '';
		}

		// A single leading slash only: "//host/path" is protocol-relative and
		// already absolute enough for esc_url_raw() to judge.
		if ( '/' === $value[0] && ( ! isset( $value[1] ) || '/' !== $value[1] ) ) {
			$value = home_url( $value );
		}

		return (string) esc_url_raw( $value );
	}

	/**
	 * The page holding [tbt_swipe_generator].
	 *
	 * Three answers, most specific first: the shortcode attribute, the page
	 * chosen in settings, then the current page.
	 *
	 * The settings tier exists because the current-page fallback is only right
	 * for a page carrying both shortcodes. On a library-only page it resolved
	 * to the library itself, so Create wrote a draft and then landed the
	 * teacher back where they started, looking like a page refresh.
	 *
	 * @param string $attribute Raw generator attribute.
	 * @return string
	 */
	public static function generator_url( string $attribute = '' ): string {
		$url = self::clean_url( $attribute );
		if ( '' === $url ) {
			$url = self::page_option_url( 'tbts_generator_page_id' );
		}
		if ( '' === $url ) {
			$url = self::builder_url();
		}

		/**
		 * Filter the URL of the page holding [tbt_swipe_generator].
		 *
		 * @param string $url Generator page URL.
		 */
		return (string) apply_filters( 'tbts_generator_url', $url );
	}

	/**
	 * The page holding [tbt_swipe_sets], or '' when none is configured.
	 *
	 * No current-page fallback, unlike the generator: on a shared page a link
	 * back to the page you are on is noise, and after a discard it would
	 * return the teacher to the deck they just deleted.
	 *
	 * @param string $attribute Raw library attribute.
	 * @return string
	 */
	public static function library_url( string $attribute = '' ): string {
		$url = self::clean_url( $attribute );
		if ( '' === $url ) {
			$url = self::page_option_url( 'tbts_library_page_id' );
		}

		/**
		 * Filter the URL of the page holding [tbt_swipe_sets].
		 *
		 * @param string $url Library page URL, or '' when there is none.
		 */
		return (string) apply_filters( 'tbts_library_url', $url );
	}

	/**
	 * The permalink of a page chosen in settings, or '' when there is none.
	 *
	 * Published only, and the same check TBTS_DB::deck_url() makes: a page
	 * unpublished or deleted since it was picked is not a place to send
	 * anyone.
	 *
	 * @param string $option Option name holding the page ID.
	 * @return string
	 */
	private static function page_option_url( $option ) {
		$page_id = (int) get_option( $option, 0 );
		if ( ! $page_id || 'publish' !== get_post_status( $page_id ) ) {
			return '';
		}

		$url = get_permalink( $page_id );

		return $url ? (string) $url : '';
	}

	/**
	 * Whether the built-in Tool Hero is rendered, from a shortcode attribute.
	 *
	 * @param string $attribute Raw hero attribute.
	 * @return bool
	 */
	private static function show_hero( $attribute ) {
		$show_hero = 'yes' === strtolower( trim( (string) $attribute ) );

		/**
		 * Filter whether the built-in Tool Hero is rendered.
		 *
		 * Returning false suppresses it everywhere without editing pages.
		 *
		 * @param bool $show_hero Whether to render the hero.
		 */
		return (bool) apply_filters( 'tbts_show_hero', $show_hero );
	}

	/**
	 * The Tool Hero, shared by both surfaces.
	 *
	 * The same header the Matching Game renders, down to the logo URL. The
	 * title is uppercased by CSS, not in the string, so a translation is not
	 * forced to shout. Only the support lines differ between the two pages.
	 *
	 * @param string[] $lines Support lines, one <span> each.
	 */
	private function render_hero( array $lines ) {
		?>
		<header class="tbt-hero">
			<div class="tbt-hero-content">
				<p class="tbt-hero-eyebrow"><?php esc_html_e( 'The Blue Tree Teacher Tools', 'tbt-swipe' ); ?></p>
				<h1 class="tbt-hero-title"><?php esc_html_e( 'Swipe', 'tbt-swipe' ); ?></h1>
				<p class="tbt-hero-sub">
					<?php foreach ( $lines as $line ) : ?>
						<span><?php echo esc_html( $line ); ?></span>
					<?php endforeach; ?>
				</p>
			</div>
			<img class="tbt-hero-logo"
				src="https://thebluetree.pl/wp-content/uploads/2020/12/TBT-white-logo.png"
				alt="<?php esc_attr_e( 'The Blue Tree', 'tbt-swipe' ); ?>"
				loading="lazy" decoding="async">
		</header>
		<?php
	}

	/**
	 * Whether a deck is still a draft.
	 *
	 * One reading of the column, so the library and the row renderer cannot
	 * disagree about which decks have a working share link.
	 *
	 * @param object $set Deck row.
	 * @return bool
	 */
	private static function is_draft( $set ) {
		return 'published' !== (string) $set->status;
	}

	/**
	 * "1 card" / "3 cards".
	 *
	 * Public because the Notes bridge labels decks the same way; one wording,
	 * one place to change it.
	 *
	 * @param int $count Number of cards.
	 * @return string
	 */
	public static function card_count_label( $count ) {
		return sprintf(
			/* translators: %d: number of cards in a deck */
			_n( '%d card', '%d cards', $count, 'tbt-swipe' ),
			$count
		);
	}

	/**
	 * "1 deck" / "3 decks", for the group headings in the library.
	 *
	 * @param int $count Number of decks in the group.
	 * @return string
	 */
	public static function deck_count_label( $count ) {
		return sprintf(
			/* translators: %d: number of decks in a class group */
			_n( '%d deck', '%d decks', $count, 'tbt-swipe' ),
			$count
		);
	}

	/**
	 * Group sets by class, with the unattached group last.
	 *
	 * "No class" is rendered as an ordinary group — same styling as the rest.
	 * An unattached deck is a supported state, not an orphan to flag.
	 *
	 * Drafts come out first, in a group of their own. A deck the teacher has
	 * only just named has no class yet, so grouping it by class would put the
	 * newest thing on the page furthest from where they are looking. The
	 * heading names them, so the rows carry no separate draft chip.
	 *
	 * @param object[] $sets Sets with card_count.
	 * @return array[] Each array( 'title' => string, 'sets' => object[] ).
	 */
	private function group_by_class( $sets ) {
		$drafts     = array();
		$attached   = array();
		$unattached = array();

		foreach ( $sets as $set ) {
			if ( self::is_draft( $set ) ) {
				$drafts[] = $set;
				continue;
			}

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
					'title' => '' !== $title ? $title : __( 'Class', 'tbt-swipe' ),
					'sets'  => array(),
				);
			}
			$attached[ $class_id ]['sets'][] = $set;
		}

		$groups = array_values( $attached );

		if ( ! empty( $unattached ) ) {
			$groups[] = array(
				'title' => __( 'No class', 'tbt-swipe' ),
				'sets'  => $unattached,
			);
		}

		if ( ! empty( $drafts ) ) {
			array_unshift(
				$groups,
				array(
					'title' => __( 'Drafts', 'tbt-swipe' ),
					'sets'  => $drafts,
				)
			);
		}

		return $groups;
	}
}

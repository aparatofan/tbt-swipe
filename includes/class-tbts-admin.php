<?php
/**
 * Admin menu, set list screen and set editor screen.
 */

defined( 'ABSPATH' ) || exit;

class TBTS_Admin {

	private $hook_suffix = '';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function register_menu() {
		if ( defined( 'TBT_HUB_SLUG' ) ) {
			$this->hook_suffix = add_submenu_page(
				TBT_HUB_SLUG,
				__( 'TBT Swipe', 'tbt-swipe' ),
				__( 'TBT Swipe', 'tbt-swipe' ),
				TBTS_CAP,
				'tbt-swipe',
				array( $this, 'render_page' )
			);
		} else {
			$this->hook_suffix = add_menu_page(
				__( 'TBT Swipe', 'tbt-swipe' ),
				__( 'TBT Swipe', 'tbt-swipe' ),
				TBTS_CAP,
				'tbt-swipe',
				array( $this, 'render_page' ),
				'dashicons-images-alt2',
				58
			);
		}
	}

	/**
	 * Delete / duplicate via nonce-protected links on the list screen.
	 */
	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || 'tbt-swipe' !== $_GET['page'] || ! isset( $_GET['action'], $_GET['set'] ) ) {
			return;
		}

		$action = sanitize_key( $_GET['action'] );
		$set_id = absint( $_GET['set'] );

		if ( ! in_array( $action, array( 'delete', 'duplicate' ), true ) ) {
			return;
		}

		check_admin_referer( 'tbts_' . $action . '_' . $set_id );

		if ( ! TBTS_Capabilities::user_can_manage() ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tbt-swipe' ) );
		}

		// Capability alone is not enough: a manager must not reach another
		// user's set by guessing an ID. Administrators oversee everything.
		if ( ! TBTS_DB::user_can_edit_set( $set_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'tbt-swipe' ) );
		}

		if ( 'delete' === $action ) {
			TBTS_DB::delete_set( $set_id );
			wp_safe_redirect( add_query_arg( array( 'page' => 'tbt-swipe', 'deleted' => 1 ), admin_url( 'admin.php' ) ) );
			exit;
		}

		$new_id = TBTS_DB::duplicate_set( $set_id );
		if ( is_wp_error( $new_id ) ) {
			wp_die( esc_html( $new_id->get_error_message() ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'tbt-swipe', 'action' => 'edit', 'set' => $new_id ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Which owner's sets this user may see on the admin screen: their own,
	 * unless they are an administrator, who oversees every set. The frontend
	 * list is owner-scoped for everyone — see TBTS_Frontend.
	 *
	 * @return int|null Owner ID, or null for "all".
	 */
	private static function visible_owner_id() {
		return TBTS_Capabilities::user_can_manage_all() ? null : get_current_user_id();
	}

	/**
	 * Fetch a set the current user is allowed to open in the editor.
	 *
	 * @param int $id Set ID.
	 * @return object|null
	 */
	private static function get_visible_set( $id ) {
		if ( TBTS_Capabilities::user_can_manage_all() ) {
			return TBTS_DB::get_set( $id );
		}
		return TBTS_DB::get_set_for_owner( $id, get_current_user_id() );
	}

	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'tbts-admin', TBTS_URL . 'assets/css/admin.css', array(), TBTS_VERSION );
		wp_enqueue_script( 'tbts-qrcode', TBTS_URL . 'assets/js/lib/qrcode.min.js', array(), TBTS_VERSION, true );
		wp_enqueue_script( 'tbts-admin', TBTS_URL . 'assets/js/admin.js', array( 'tbts-qrcode' ), TBTS_VERSION, true );

		$set_data = null;
		if ( isset( $_GET['action'] ) && 'edit' === $_GET['action'] && isset( $_GET['set'] ) ) {
			$set = self::get_visible_set( absint( $_GET['set'] ) );
			if ( $set ) {
				$cards = array();
				foreach ( TBTS_DB::get_cards( $set->id ) as $card ) {
					$cards[] = array(
						'term'        => $card->term,
						'ipa'         => $card->ipa,
						'translation' => $card->translation,
						'example'     => (string) $card->example,
					);
				}
				$set_data = array(
					'id'      => (int) $set->id,
					'title'   => $set->title,
					'status'  => $set->status,
					'slug'    => $set->slug,
					'cards'   => $cards,
					'deckUrl' => TBTS_DB::deck_url( $set ),
				);
			}
		}

		wp_localize_script(
			'tbts-admin',
			'tbtsAdmin',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( TBTS_Ajax::NONCE_ACTION ),
				'minTerms' => 1,
				'maxTerms' => TBTS_Generator::max_cards_per_generation(),
				'set'      => $set_data,
				'i18n'     => array(
					'confirmDelete' => __( 'Delete this deck and all of its cards? This cannot be undone.', 'tbt-swipe' ),
					'tooFew'        => __( 'Add at least one term to generate.', 'tbt-swipe' ),
					'tooMany'       => sprintf(
						/* translators: %d: maximum number of terms per generation */
						__( 'Maximum %d terms — remove some lines.', 'tbt-swipe' ),
						TBTS_Generator::max_cards_per_generation()
					),
					'ready'         => __( 'Ready to generate.', 'tbt-swipe' ),
					'generating'    => __( 'Generating cards… this can take up to 30 seconds.', 'tbt-swipe' ),
					'saving'        => __( 'Saving…', 'tbt-swipe' ),
					'copied'        => __( 'Copied!', 'tbt-swipe' ),
					'needTitle'     => __( 'Please give the deck a title before saving.', 'tbt-swipe' ),
					'noCards'       => __( 'Nothing to save yet — generate cards first.', 'tbt-swipe' ),
					'networkError'  => __( 'Request failed. Please check your connection and try again.', 'tbt-swipe' ),
				),
			)
		);
	}

	public function render_page() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';

		if ( 'new' === $action || 'edit' === $action ) {
			$this->render_editor();
			return;
		}

		$this->render_list();
	}

	private function render_list() {
		$sets = TBTS_DB::get_sets( self::visible_owner_id() );
		$new_url = add_query_arg( array( 'page' => 'tbt-swipe', 'action' => 'new' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap tbts-wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'TBT Swipe', 'tbt-swipe' ); ?></h1>
			<a href="<?php echo esc_url( $new_url ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'tbt-swipe' ); ?></a>
			<hr class="wp-header-end">

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Deck deleted.', 'tbt-swipe' ); ?></p></div>
			<?php endif; ?>

			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Title', 'tbt-swipe' ); ?></th>
						<th class="tbts-col-narrow"><?php esc_html_e( 'Cards', 'tbt-swipe' ); ?></th>
						<th class="tbts-col-narrow"><?php esc_html_e( 'Status', 'tbt-swipe' ); ?></th>
						<th><?php esc_html_e( 'Created', 'tbt-swipe' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'tbt-swipe' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php if ( empty( $sets ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No decks yet. Click "Add New" to create your first deck.', 'tbt-swipe' ); ?></td></tr>
				<?php else : ?>
					<?php foreach ( $sets as $set ) :
						$edit_url = add_query_arg( array( 'page' => 'tbt-swipe', 'action' => 'edit', 'set' => $set->id ), admin_url( 'admin.php' ) );
						$qr_url   = $edit_url . '#tbts-qr-panel';
						$dup_url  = wp_nonce_url(
							add_query_arg( array( 'page' => 'tbt-swipe', 'action' => 'duplicate', 'set' => $set->id ), admin_url( 'admin.php' ) ),
							'tbts_duplicate_' . $set->id
						);
						$del_url  = wp_nonce_url(
							add_query_arg( array( 'page' => 'tbt-swipe', 'action' => 'delete', 'set' => $set->id ), admin_url( 'admin.php' ) ),
							'tbts_delete_' . $set->id
						);
						?>
						<tr>
							<td><a href="<?php echo esc_url( $edit_url ); ?>"><strong><?php echo esc_html( $set->title ); ?></strong></a></td>
							<td><?php echo esc_html( $set->card_count ); ?></td>
							<td>
								<?php if ( 'published' === $set->status ) : ?>
									<span class="tbts-status tbts-status-published"><?php esc_html_e( 'Published', 'tbt-swipe' ); ?></span>
								<?php else : ?>
									<span class="tbts-status tbts-status-draft"><?php esc_html_e( 'Draft', 'tbt-swipe' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $set->created ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'tbt-swipe' ); ?></a> |
								<a href="<?php echo esc_url( $qr_url ); ?>"><?php esc_html_e( 'QR', 'tbt-swipe' ); ?></a> |
								<a href="<?php echo esc_url( $dup_url ); ?>"><?php esc_html_e( 'Duplicate', 'tbt-swipe' ); ?></a> |
								<a href="<?php echo esc_url( $del_url ); ?>" class="tbts-delete tbts-danger"><?php esc_html_e( 'Delete', 'tbt-swipe' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_editor() {
		$set   = null;
		if ( isset( $_GET['set'] ) ) {
			$set = self::get_visible_set( absint( $_GET['set'] ) );
		}

		$is_edit  = (bool) $set;
		$title    = $is_edit ? $set->title : '';
		$status   = $is_edit ? $set->status : 'draft';
		$deck_url = $is_edit ? TBTS_DB::deck_url( $set ) : '';
		$page_set = (int) get_option( 'tbts_deck_page_id', 0 ) > 0;
		$list_url = add_query_arg( 'page', 'tbt-swipe', admin_url( 'admin.php' ) );
		?>
		<div class="wrap tbts-wrap tbts-editor">
			<h1><?php echo $is_edit ? esc_html__( 'Edit Deck', 'tbt-swipe' ) : esc_html__( 'New Deck', 'tbt-swipe' ); ?></h1>
			<p><a href="<?php echo esc_url( $list_url ); ?>">&larr; <?php esc_html_e( 'Back to all decks', 'tbt-swipe' ); ?></a></p>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Deck saved.', 'tbt-swipe' ); ?></p></div>
			<?php endif; ?>

			<div id="tbts-error" class="notice notice-error" hidden><p></p></div>

			<div class="tbts-panel">
				<label for="tbts-title" class="tbts-label"><?php esc_html_e( 'Title', 'tbt-swipe' ); ?></label>
				<input type="text" id="tbts-title" class="regular-text" value="<?php echo esc_attr( $title ); ?>"
					placeholder="<?php esc_attr_e( 'e.g. Unit 4 — Travel vocabulary', 'tbt-swipe' ); ?>">
			</div>

			<div class="tbts-panel">
				<h2><?php esc_html_e( 'Step 1 — Terms', 'tbt-swipe' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %d: maximum number of terms per generation */
						esc_html__( 'Paste up to %d English items, one per line.', 'tbt-swipe' ),
						(int) TBTS_Generator::max_cards_per_generation()
					);
					?>
				</p>
				<textarea id="tbts-terms" rows="10" class="large-text code" spellcheck="false"></textarea>
				<p><span id="tbts-term-count" class="tbts-count">0</span> — <span id="tbts-term-hint"></span></p>
				<p>
					<button type="button" class="button button-primary" id="tbts-generate" disabled>
						<?php esc_html_e( 'Generate cards', 'tbt-swipe' ); ?>
					</button>
					<span class="spinner" id="tbts-generate-spinner"></span>
					<span id="tbts-generate-status" class="tbts-muted"></span>
				</p>
			</div>

			<div class="tbts-panel" id="tbts-review-panel" hidden>
				<h2><?php esc_html_e( 'Step 2 — Review & edit', 'tbt-swipe' ); ?></h2>
				<table class="widefat tbts-review-table" id="tbts-review">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Term', 'tbt-swipe' ); ?></th>
							<th><?php esc_html_e( 'IPA', 'tbt-swipe' ); ?></th>
							<th><?php esc_html_e( 'Translation', 'tbt-swipe' ); ?></th>
							<th><?php esc_html_e( 'Example', 'tbt-swipe' ); ?></th>
							<th class="tbts-col-tools">&#8597;</th>
							<th class="tbts-col-tools">&#10005;</th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
				<p><button type="button" class="button" id="tbts-add-row"><?php esc_html_e( '+ Add row', 'tbt-swipe' ); ?></button></p>
			</div>

			<div class="tbts-panel">
				<h2><?php esc_html_e( 'Step 3 — Save', 'tbt-swipe' ); ?></h2>
				<label for="tbts-status"><?php esc_html_e( 'Status', 'tbt-swipe' ); ?></label>
				<select id="tbts-status">
					<option value="draft" <?php selected( $status, 'draft' ); ?>><?php esc_html_e( 'Draft', 'tbt-swipe' ); ?></option>
					<option value="published" <?php selected( $status, 'published' ); ?>><?php esc_html_e( 'Published', 'tbt-swipe' ); ?></option>
				</select>
				<button type="button" class="button button-primary" id="tbts-save"><?php esc_html_e( 'Save deck', 'tbt-swipe' ); ?></button>
				<span class="spinner" id="tbts-save-spinner"></span>
			</div>

			<?php if ( $is_edit ) : ?>
				<div class="tbts-panel" id="tbts-qr-panel">
					<h2><?php esc_html_e( 'QR code', 'tbt-swipe' ); ?></h2>
					<?php if ( 'published' !== $set->status ) : ?>
						<p><?php esc_html_e( 'Publish the deck to get a scannable QR code.', 'tbt-swipe' ); ?></p>
					<?php elseif ( ! $page_set || '' === $deck_url ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: settings page link */
								esc_html__( 'Choose the deck page under %s first, so the plugin knows which URL to encode.', 'tbt-swipe' ),
								'<a href="' . esc_url( add_query_arg( 'page', 'tbt-swipe-settings', admin_url( 'admin.php' ) ) ) . '">' . esc_html__( 'TBT Swipe → Settings', 'tbt-swipe' ) . '</a>'
							);
							?>
						</p>
					<?php else : ?>
						<p>
							<?php esc_html_e( 'Deck URL:', 'tbt-swipe' ); ?>
							<input type="text" readonly id="tbts-deck-url" class="regular-text code" value="<?php echo esc_url( $deck_url ); ?>">
							<button type="button" class="button" id="tbts-copy-url"><?php esc_html_e( 'Copy URL', 'tbt-swipe' ); ?></button>
						</p>
						<div id="tbts-qr" class="tbts-qr" data-url="<?php echo esc_url( $deck_url ); ?>"></div>
						<p><button type="button" class="button" id="tbts-download-qr"><?php esc_html_e( 'Download PNG', 'tbt-swipe' ); ?></button></p>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}
}

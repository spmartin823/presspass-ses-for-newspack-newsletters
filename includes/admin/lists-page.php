<?php
/**
 * Lists screen.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

function presspass_ses_lists_url(): string {
	return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=presspass-ses-lists' );
}

class PressPass_SES_Lists_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'list',
				'plural'   => 'lists',
			)
		);
	}

	public function get_columns(): array {
		return array(
			'name'             => __( 'Name', 'presspass-ses-for-newspack-newsletters' ),
			'description'      => __( 'Description', 'presspass-ses-for-newspack-newsletters' ),
			'subscribed_count' => __( 'Subscribed', 'presspass-ses-for-newspack-newsletters' ),
		);
	}

	public function prepare_items(): void {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = presspass_ses_get_lists();
	}

	protected function column_name( $item ): string {
		$id      = (int) $item->id;
		$edit    = add_query_arg(
			array(
				'action' => 'edit',
				'list'   => $id,
			),
			presspass_ses_lists_url()
		);
		$delete  = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'presspass_ses_delete_list',
					'list'   => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'presspass_ses_delete_list'
		);
		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit ), esc_html__( 'Edit', 'presspass-ses-for-newspack-newsletters' ) ),
			'delete' => sprintf(
				'<a href="%s" class="submitdelete" onclick="%s">%s</a>',
				esc_url( $delete ),
				esc_attr( 'return confirm(' . wp_json_encode( __( 'Delete this list? Its subscribers are kept.', 'presspass-ses-for-newspack-newsletters' ) ) . ');' ),
				esc_html__( 'Delete', 'presspass-ses-for-newspack-newsletters' )
			),
		);
		return sprintf( '<strong><a href="%s">%s</a></strong>%s', esc_url( $edit ), esc_html( $item->name ), $this->row_actions( $actions ) );
	}

	protected function column_description( $item ): string {
		return esc_html( $item->description );
	}

	protected function column_subscribed_count( $item ): string {
		return esc_html( (string) (int) $item->subscribed_count );
	}

	public function no_items(): void {
		esc_html_e( 'No lists yet.', 'presspass-ses-for-newspack-newsletters' );
	}
}

function presspass_ses_render_lists_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only routing.
	$view = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( 'new' === $view || 'edit' === $view ) {
		presspass_ses_render_list_form();
		return;
	}

	$table = new PressPass_SES_Lists_Table();
	$table->prepare_items();
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Lists', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'action', 'new', presspass_ses_lists_url() ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'presspass-ses-for-newspack-newsletters' ); ?></a>
		<hr class="wp-header-end">
		<?php
		presspass_ses_render_notices(
			array(
				'saved'   => __( 'List saved.', 'presspass-ses-for-newspack-newsletters' ),
				'deleted' => __( 'List deleted.', 'presspass-ses-for-newspack-newsletters' ),
			)
		);
		$table->display();
		?>
	</div>
	<?php
}

function presspass_ses_render_list_form(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only routing.
	$id      = isset( $_GET['list'] ) ? (int) $_GET['list'] : 0;
	$list    = 0 === $id ? null : presspass_ses_get_list( $id );
	$is_edit = null !== $list;
	?>
	<div class="wrap">
		<h1><?php echo $is_edit ? esc_html__( 'Edit list', 'presspass-ses-for-newspack-newsletters' ) : esc_html__( 'Add list', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="presspass_ses_save_list">
			<input type="hidden" name="list_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'presspass_ses_save_list' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="presspass-ses-list-name"><?php esc_html_e( 'Name', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td><input class="regular-text" id="presspass-ses-list-name" name="name" type="text" value="<?php echo $is_edit ? esc_attr( $list->name ) : ''; ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="presspass-ses-list-description"><?php esc_html_e( 'Description', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td><textarea class="large-text" rows="3" id="presspass-ses-list-description" name="description"><?php echo $is_edit ? esc_textarea( $list->description ) : ''; ?></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save list', 'presspass-ses-for-newspack-newsletters' ) ); ?>
		</form>
	</div>
	<?php
}

function presspass_ses_handle_save_list(): void {
	presspass_ses_authorize_staff( 'presspass_ses_save_list' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce checked by presspass_ses_authorize_staff(); the form always posts every field.
	$id          = (int) $_POST['list_id'];
	$name        = sanitize_text_field( wp_unslash( $_POST['name'] ) );
	$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ) );
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated

	if ( 0 === $id ) {
		presspass_ses_create_list( $name, $description );
	} else {
		presspass_ses_update_list( $id, $name, $description );
	}

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'saved', presspass_ses_lists_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_save_list', 'presspass_ses_handle_save_list' );

function presspass_ses_handle_delete_list(): void {
	presspass_ses_authorize_staff( 'presspass_ses_delete_list' );

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce checked above; the link always has a list.
	presspass_ses_delete_list( (int) $_GET['list'] );

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'deleted', presspass_ses_lists_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_delete_list', 'presspass_ses_handle_delete_list' );

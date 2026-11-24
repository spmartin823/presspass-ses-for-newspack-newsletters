<?php
/**
 * Subscribers screen.
 *
 * @package PressPass_SES
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';

function presspass_ses_authorize_staff( string $nonce_action ): void {
	check_admin_referer( $nonce_action );
	if ( ! current_user_can( 'edit_others_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to do this.', 'presspass-ses-for-newspack-newsletters' ), '', 403 );
	}
}

function presspass_ses_subscribers_url(): string {
	return admin_url( 'edit.php?post_type=newspack_nl_cpt&page=presspass-ses-subscribers' );
}

function presspass_ses_render_notices( array $messages ): void {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flags set by our own redirects.
	if ( isset( $_GET['presspass_ses_notice'] ) ) {
		printf( '<div class="notice notice-success"><p>%s</p></div>', esc_html( $messages[ sanitize_key( wp_unslash( $_GET['presspass_ses_notice'] ) ) ] ) );
	}
	if ( isset( $_GET['presspass_ses_error'] ) ) {
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( sanitize_text_field( wp_unslash( $_GET['presspass_ses_error'] ) ) ) );
	}
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

function presspass_ses_subscribers_filters(): array {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	return array(
		'status'  => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
		'list_id' => isset( $_GET['list_id'] ) ? (int) $_GET['list_id'] : 0,
		'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
	);
	// phpcs:enable WordPress.Security.NonceVerification.Recommended
}

function presspass_ses_list_names_by_id(): array {
	$names = array();
	foreach ( presspass_ses_get_lists() as $list ) {
		$names[ (int) $list->id ] = $list->name;
	}
	return $names;
}

function presspass_ses_status_label( string $status ): string {
	$labels = array(
		PressPass_SES_Status::SUBSCRIBED   => __( 'Subscribed', 'presspass-ses-for-newspack-newsletters' ),
		PressPass_SES_Status::UNCONFIRMED  => __( 'Unconfirmed', 'presspass-ses-for-newspack-newsletters' ),
		PressPass_SES_Status::UNSUBSCRIBED => __( 'Unsubscribed', 'presspass-ses-for-newspack-newsletters' ),
		PressPass_SES_Status::BOUNCED      => __( 'Bounced', 'presspass-ses-for-newspack-newsletters' ),
	);
	return $labels[ $status ];
}

function presspass_ses_local_date( string $utc_datetime ): string {
	return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $utc_datetime . ' UTC' ) );
}

class PressPass_SES_Subscribers_Table extends WP_List_Table {

	private array $list_names;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
			)
		);
		$this->list_names = presspass_ses_list_names_by_id();
	}

	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox">',
			'email'      => __( 'Email', 'presspass-ses-for-newspack-newsletters' ),
			'name'       => __( 'Name', 'presspass-ses-for-newspack-newsletters' ),
			'status'     => __( 'Status', 'presspass-ses-for-newspack-newsletters' ),
			'lists'      => __( 'Lists', 'presspass-ses-for-newspack-newsletters' ),
			'created_at' => __( 'Subscribed on', 'presspass-ses-for-newspack-newsletters' ),
		);
	}

	public function prepare_items(): void {
		$filters               = presspass_ses_subscribers_filters();
		$result                = presspass_ses_query_subscribers( $filters['status'], $filters['list_id'], $filters['search'], 20, $this->get_pagenum() );
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->items           = $result['subscribers'];
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => 20,
			)
		);
	}

	protected function get_views(): array {
		$filters = presspass_ses_subscribers_filters();
		$counts  = presspass_ses_count_by_status( $filters['list_id'] );
		$tabs    = array(
			''                                 => __( 'All', 'presspass-ses-for-newspack-newsletters' ),
			PressPass_SES_Status::SUBSCRIBED   => presspass_ses_status_label( PressPass_SES_Status::SUBSCRIBED ),
			PressPass_SES_Status::UNCONFIRMED  => presspass_ses_status_label( PressPass_SES_Status::UNCONFIRMED ),
			PressPass_SES_Status::UNSUBSCRIBED => presspass_ses_status_label( PressPass_SES_Status::UNSUBSCRIBED ),
			PressPass_SES_Status::BOUNCED      => presspass_ses_status_label( PressPass_SES_Status::BOUNCED ),
		);
		$views   = array();
		foreach ( $tabs as $status => $label ) {
			$url             = add_query_arg(
				array(
					'status'  => $status,
					'list_id' => $filters['list_id'],
					's'       => rawurlencode( $filters['search'] ),
				),
				presspass_ses_subscribers_url()
			);
			$views[ $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$status === $filters['status'] ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				'' === $status ? (int) $counts['all'] : (int) $counts[ $status ]
			);
		}
		return $views;
	}

	public function bulk_actions( $which = '' ): void {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<label for="bulk-action-selector-top" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'presspass-ses-for-newspack-newsletters' ); ?></label>
		<select name="bulk_action" id="bulk-action-selector-top">
			<option value=""><?php esc_html_e( 'Bulk actions', 'presspass-ses-for-newspack-newsletters' ); ?></option>
			<option value="add_to_list"><?php esc_html_e( 'Add to list…', 'presspass-ses-for-newspack-newsletters' ); ?></option>
			<option value="remove_from_list"><?php esc_html_e( 'Remove from list…', 'presspass-ses-for-newspack-newsletters' ); ?></option>
			<option value="unsubscribe"><?php esc_html_e( 'Unsubscribe', 'presspass-ses-for-newspack-newsletters' ); ?></option>
			<option value="delete"><?php esc_html_e( 'Delete', 'presspass-ses-for-newspack-newsletters' ); ?></option>
		</select>
		<?php
		submit_button( __( 'Apply', 'presspass-ses-for-newspack-newsletters' ), 'action', '', false );
	}

	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		?>
		<div class="alignleft actions">
			<label for="presspass-ses-bulk-list" class="screen-reader-text"><?php esc_html_e( 'List for the add/remove bulk actions', 'presspass-ses-for-newspack-newsletters' ); ?></label>
			<select name="bulk_list_id" id="presspass-ses-bulk-list">
				<option value="0"><?php esc_html_e( 'Choose a list…', 'presspass-ses-for-newspack-newsletters' ); ?></option>
				<?php foreach ( $this->list_names as $list_id => $list_name ) : ?>
					<option value="<?php echo esc_attr( $list_id ); ?>"><?php echo esc_html( $list_name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="subscriber[]" value="%d">', (int) $item->id );
	}

	protected function column_email( $item ): string {
		$id       = (int) $item->id;
		$edit_url = add_query_arg(
			array(
				'action'     => 'edit',
				'subscriber' => $id,
			),
			presspass_ses_subscribers_url()
		);
		$actions  = array(
			'edit' => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'presspass-ses-for-newspack-newsletters' ) ),
		);
		if ( PressPass_SES_Status::UNSUBSCRIBED !== $item->status ) {
			$actions['unsubscribe'] = sprintf( '<a href="%s">%s</a>', esc_url( $this->row_action_url( 'unsubscribe', $id ) ), esc_html__( 'Unsubscribe', 'presspass-ses-for-newspack-newsletters' ) );
		}
		$actions['delete'] = sprintf(
			'<a href="%s" class="submitdelete" onclick="%s">%s</a>',
			esc_url( $this->row_action_url( 'delete', $id ) ),
			esc_attr( 'return confirm(' . wp_json_encode( __( 'Delete this subscriber?', 'presspass-ses-for-newspack-newsletters' ) ) . ');' ),
			esc_html__( 'Delete', 'presspass-ses-for-newspack-newsletters' )
		);
		return sprintf( '<strong><a href="%s">%s</a></strong>%s', esc_url( $edit_url ), esc_html( $item->email ), $this->row_actions( $actions ) );
	}

	private function row_action_url( string $bulk_action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'           => 'presspass_ses_bulk_subscribers',
					'bulk_action'      => $bulk_action,
					'subscriber[]'     => $id,
					'_wp_http_referer' => rawurlencode( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- WordPress always sets REQUEST_URI on an admin screen.
				),
				admin_url( 'admin-post.php' )
			),
			'bulk-subscribers'
		);
	}

	protected function column_name( $item ): string {
		return esc_html( trim( $item->first_name . ' ' . $item->last_name ) );
	}

	protected function column_status( $item ): string {
		return esc_html( presspass_ses_status_label( $item->status ) );
	}

	protected function column_lists( $item ): string {
		$names = array();
		foreach ( presspass_ses_get_subscriber_list_ids( (int) $item->id ) as $list_id ) {
			$names[] = $this->list_names[ $list_id ];
		}
		return esc_html( implode( ', ', $names ) );
	}

	protected function column_created_at( $item ): string {
		return esc_html( presspass_ses_local_date( $item->created_at ) );
	}

	public function no_items(): void {
		esc_html_e( 'No subscribers found.', 'presspass-ses-for-newspack-newsletters' );
	}
}

function presspass_ses_render_subscribers_page(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only routing.
	$view = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	if ( 'new' === $view || 'edit' === $view ) {
		presspass_ses_render_subscriber_form();
		return;
	}

	$table   = new PressPass_SES_Subscribers_Table();
	$table->prepare_items();
	$filters = presspass_ses_subscribers_filters();
	$export  = wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'presspass_ses_export_subscribers',
				'status'  => $filters['status'],
				'list_id' => $filters['list_id'],
				's'       => rawurlencode( $filters['search'] ),
			),
			admin_url( 'admin-post.php' )
		),
		'presspass_ses_export_subscribers'
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<a href="<?php echo esc_url( add_query_arg( 'action', 'new', presspass_ses_subscribers_url() ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add New', 'presspass-ses-for-newspack-newsletters' ); ?></a>
		<a href="<?php echo esc_url( $export ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'presspass-ses-for-newspack-newsletters' ); ?></a>
		<hr class="wp-header-end">
		<?php
		presspass_ses_render_notices(
			array(
				'saved'     => __( 'Subscriber saved.', 'presspass-ses-for-newspack-newsletters' ),
				'bulk_done' => __( 'Bulk action applied.', 'presspass-ses-for-newspack-newsletters' ),
			)
		);
		do_action( 'presspass_ses_subscribers_page_top' );
		$table->views();
		?>
		<form method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
			<input type="hidden" name="post_type" value="newspack_nl_cpt">
			<input type="hidden" name="page" value="presspass-ses-subscribers">
			<input type="hidden" name="status" value="<?php echo esc_attr( $filters['status'] ); ?>">
			<p class="search-box">
				<label class="screen-reader-text" for="presspass-ses-filter-list"><?php esc_html_e( 'Filter by list', 'presspass-ses-for-newspack-newsletters' ); ?></label>
				<select name="list_id" id="presspass-ses-filter-list">
					<option value="0"><?php esc_html_e( 'All lists', 'presspass-ses-for-newspack-newsletters' ); ?></option>
					<?php foreach ( presspass_ses_list_names_by_id() as $list_id => $list_name ) : ?>
						<option value="<?php echo esc_attr( $list_id ); ?>" <?php selected( $filters['list_id'], $list_id ); ?>><?php echo esc_html( $list_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<label class="screen-reader-text" for="presspass-ses-search"><?php esc_html_e( 'Search subscribers', 'presspass-ses-for-newspack-newsletters' ); ?></label>
				<input type="search" id="presspass-ses-search" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>">
				<?php submit_button( __( 'Search', 'presspass-ses-for-newspack-newsletters' ), '', '', false ); ?>
			</p>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="<?php echo esc_attr( 'return this.bulk_action.value !== "delete" || confirm(' . wp_json_encode( __( 'Delete the selected subscribers?', 'presspass-ses-for-newspack-newsletters' ) ) . ');' ); ?>">
			<input type="hidden" name="action" value="presspass_ses_bulk_subscribers">
			<?php $table->display(); ?>
		</form>
	</div>
	<?php
}

function presspass_ses_render_subscriber_form(): void {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only routing.
	$id         = isset( $_GET['subscriber'] ) ? (int) $_GET['subscriber'] : 0;
	$subscriber = 0 === $id ? null : presspass_ses_get_subscriber( $id );
	$is_edit    = null !== $subscriber;
	$list_ids   = $is_edit ? presspass_ses_get_subscriber_list_ids( $id ) : array();
	?>
	<div class="wrap">
		<h1><?php echo $is_edit ? esc_html__( 'Edit subscriber', 'presspass-ses-for-newspack-newsletters' ) : esc_html__( 'Add subscriber', 'presspass-ses-for-newspack-newsletters' ); ?></h1>
		<?php presspass_ses_render_notices( array() ); ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="presspass_ses_save_subscriber">
			<input type="hidden" name="subscriber_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( 'presspass_ses_save_subscriber' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="presspass-ses-email"><?php esc_html_e( 'Email', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td>
						<?php if ( $is_edit ) : ?>
							<input class="regular-text" id="presspass-ses-email" type="email" value="<?php echo esc_attr( $subscriber->email ); ?>" readonly>
						<?php else : ?>
							<input class="regular-text" id="presspass-ses-email" name="email" type="email" required>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="presspass-ses-first-name"><?php esc_html_e( 'First name', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td><input class="regular-text" id="presspass-ses-first-name" name="first_name" type="text" value="<?php echo $is_edit ? esc_attr( $subscriber->first_name ) : ''; ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="presspass-ses-last-name"><?php esc_html_e( 'Last name', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
					<td><input class="regular-text" id="presspass-ses-last-name" name="last_name" type="text" value="<?php echo $is_edit ? esc_attr( $subscriber->last_name ) : ''; ?>"></td>
				</tr>
				<?php if ( $is_edit ) : ?>
					<tr>
						<th scope="row"><label for="presspass-ses-status"><?php esc_html_e( 'Status', 'presspass-ses-for-newspack-newsletters' ); ?></label></th>
						<td>
							<select id="presspass-ses-status" name="status">
								<?php foreach ( array( PressPass_SES_Status::SUBSCRIBED, PressPass_SES_Status::UNCONFIRMED, PressPass_SES_Status::UNSUBSCRIBED, PressPass_SES_Status::BOUNCED ) as $status ) : ?>
									<option value="<?php echo esc_attr( $status ); ?>" <?php selected( $subscriber->status, $status ); ?>><?php echo esc_html( presspass_ses_status_label( $status ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Lists', 'presspass-ses-for-newspack-newsletters' ); ?></th>
					<td>
						<?php foreach ( presspass_ses_list_names_by_id() as $list_id => $list_name ) : ?>
							<label><input type="checkbox" name="list_ids[]" value="<?php echo esc_attr( $list_id ); ?>" <?php checked( in_array( $list_id, $list_ids, true ) ); ?>> <?php echo esc_html( $list_name ); ?></label><br>
						<?php endforeach; ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save subscriber', 'presspass-ses-for-newspack-newsletters' ) ); ?>
		</form>
	</div>
	<?php
}

function presspass_ses_handle_save_subscriber(): void {
	presspass_ses_authorize_staff( 'presspass_ses_save_subscriber' );

	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked by presspass_ses_authorize_staff(); the form always posts these fields; list_ids are cast to int below.
	$id         = (int) $_POST['subscriber_id'];
	$first_name = sanitize_text_field( wp_unslash( $_POST['first_name'] ) );
	$last_name  = sanitize_text_field( wp_unslash( $_POST['last_name'] ) );
	$list_ids   = isset( $_POST['list_ids'] ) ? array_map( 'intval', wp_unslash( $_POST['list_ids'] ) ) : array();
	$is_edit    = 0 !== $id;

	if ( ! $is_edit ) {
		$email = sanitize_email( wp_unslash( $_POST['email'] ) );
		$error = '';
		if ( ! is_email( $email ) ) {
			$error = __( 'Please enter a valid email address.', 'presspass-ses-for-newspack-newsletters' );
		} elseif ( null !== presspass_ses_get_subscriber_by_email( $email ) ) {
			$error = __( 'That email is already a subscriber.', 'presspass-ses-for-newspack-newsletters' );
		}
		if ( '' !== $error ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'action'              => 'new',
						'presspass_ses_error' => rawurlencode( $error ),
					),
					presspass_ses_subscribers_url()
				)
			);
			exit;
		}
		$id = presspass_ses_insert_subscriber( $email, $first_name, $last_name, PressPass_SES_Status::SUBSCRIBED, 'admin' );
	} else {
		presspass_ses_update_subscriber(
			$id,
			array(
				'first_name' => $first_name,
				'last_name'  => $last_name,
			)
		);
	}

	$current_list_ids = presspass_ses_get_subscriber_list_ids( $id );
	foreach ( array_diff( $list_ids, $current_list_ids ) as $list_id ) {
		presspass_ses_add_to_list( $id, $list_id );
	}
	foreach ( array_diff( $current_list_ids, $list_ids ) as $list_id ) {
		presspass_ses_remove_from_list( $id, $list_id );
	}

	if ( $is_edit ) {
		$status = sanitize_key( wp_unslash( $_POST['status'] ) );
		if ( $status !== presspass_ses_get_subscriber( $id )->status ) {
			presspass_ses_set_status( $id, $status );
		}
	}
	// phpcs:enable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'saved', presspass_ses_subscribers_url() ) );
	exit;
}
add_action( 'admin_post_presspass_ses_save_subscriber', 'presspass_ses_handle_save_subscriber' );

function presspass_ses_handle_bulk_subscribers(): void {
	presspass_ses_authorize_staff( 'bulk-subscribers' );

	// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked above; ids are cast to int.
	$redirect = remove_query_arg( array( 'presspass_ses_notice', 'presspass_ses_error' ), wp_get_referer() );
	$action   = isset( $_REQUEST['bulk_action'] ) ? sanitize_key( wp_unslash( $_REQUEST['bulk_action'] ) ) : '';
	$ids      = isset( $_REQUEST['subscriber'] ) ? array_map( 'intval', wp_unslash( $_REQUEST['subscriber'] ) ) : array();
	$list_id  = isset( $_REQUEST['bulk_list_id'] ) ? (int) $_REQUEST['bulk_list_id'] : 0;
	// phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

	// The table sits inside this form, so pressing Enter in its page-number box posts here with no action.
	if ( '' === $action ) {
		wp_safe_redirect( add_query_arg( 'paged', absint( $_REQUEST['paged'] ), $redirect ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotValidated -- nonce checked above; the table's pagination always posts paged.
		exit;
	}

	$error = '';
	if ( array() === $ids ) {
		$error = __( 'Select at least one subscriber.', 'presspass-ses-for-newspack-newsletters' );
	} elseif ( in_array( $action, array( 'add_to_list', 'remove_from_list' ), true ) && 0 === $list_id ) {
		$error = __( 'Choose a list first.', 'presspass-ses-for-newspack-newsletters' );
	}
	if ( '' !== $error ) {
		wp_safe_redirect( add_query_arg( 'presspass_ses_error', rawurlencode( $error ), $redirect ) );
		exit;
	}

	foreach ( $ids as $id ) {
		if ( 'add_to_list' === $action ) {
			presspass_ses_add_to_list( $id, $list_id );
		} elseif ( 'remove_from_list' === $action ) {
			presspass_ses_remove_from_list( $id, $list_id );
		} elseif ( 'unsubscribe' === $action ) {
			if ( PressPass_SES_Status::UNSUBSCRIBED !== presspass_ses_get_subscriber( $id )->status ) {
				presspass_ses_set_status( $id, PressPass_SES_Status::UNSUBSCRIBED );
			}
		} elseif ( 'delete' === $action ) {
			presspass_ses_delete_subscriber( $id );
		}
	}

	wp_safe_redirect( add_query_arg( 'presspass_ses_notice', 'bulk_done', $redirect ) );
	exit;
}
add_action( 'admin_post_presspass_ses_bulk_subscribers', 'presspass_ses_handle_bulk_subscribers' );

// Spreadsheet apps run a cell starting with one of these as a formula; signup names are public input.
function presspass_ses_csv_cell( string $value ): string {
	if ( in_array( substr( $value, 0, 1 ), array( '=', '+', '-', '@' ), true ) ) {
		return "'" . $value;
	}
	return $value;
}

function presspass_ses_handle_export_subscribers(): void {
	presspass_ses_authorize_staff( 'presspass_ses_export_subscribers' );

	$filters    = presspass_ses_subscribers_filters();
	$list_names = presspass_ses_list_names_by_id();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="subscribers-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$output = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming the download to php://output.
	fputcsv( $output, array( 'email', 'first_name', 'last_name', 'status', 'lists', 'created_at' ), ',', '"', '' );
	$page = 1;
	do {
		$result = presspass_ses_query_subscribers( $filters['status'], $filters['list_id'], $filters['search'], 500, $page );
		foreach ( $result['subscribers'] as $subscriber ) {
			$names = array();
			foreach ( presspass_ses_get_subscriber_list_ids( (int) $subscriber->id ) as $list_id ) {
				$names[] = $list_names[ $list_id ];
			}
			fputcsv( $output, array_map( 'presspass_ses_csv_cell', array( $subscriber->email, $subscriber->first_name, $subscriber->last_name, $subscriber->status, implode( '|', $names ), $subscriber->created_at ) ), ',', '"', '' );
		}
		++$page;
	} while ( count( $result['subscribers'] ) === 500 );
	fclose( $output ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream.
	exit;
}
add_action( 'admin_post_presspass_ses_export_subscribers', 'presspass_ses_handle_export_subscribers' );

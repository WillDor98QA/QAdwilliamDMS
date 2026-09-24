<?php
/**
 * Every state-changing admin request (MP §33): POST to admin-post.php with a
 * per-action nonce. The handler calls the service, which enforces permissions,
 * object access and business rules. Expected errors become notices;
 * unexpected ones are logged and shown with a reference only.
 *
 * Handlers return the URL to redirect to, so tests can call them directly;
 * dispatch() adds the nonce check and the redirect.
 *
 * @package DMS
 */

namespace DMS\Admin;

use DMS\Errors\DmsException;
use DMS\Errors\ValidationException;
use DMS\Plugin;

defined( 'ABSPATH' ) || exit;

class AdminActions {

	public const PREFIX = 'dms_';

	/** Action => handler method. */
	private const HANDLERS = array(
		'registration'         => 'registration',
		'bulk'                 => 'bulk',
		'export'               => 'export',
		'export_download'      => 'export_download',
		'assign_pending'       => 'assign_pending',
		'user_save'            => 'user_save',
		'user_status'          => 'user_status',
		'role_save'            => 'role_save',
		'role_delete'          => 'role_delete',
		'form_save'            => 'form_save',
		'audit_export'         => 'audit_export',
		'import_upload'        => 'import_upload',
		'import_confirm'       => 'import_confirm',
		'import_rollback'      => 'import_rollback',
		'import_template'      => 'import_template',
		'import_error_report'  => 'import_error_report',
		'notification_retry'   => 'notification_retry',
		'email_templates_save' => 'email_templates_save',
		'analytics_export'     => 'analytics_export',
		'report_export'        => 'report_export',
	);

	public function __construct( private Plugin $plugin ) {
	}

	public function register(): void {
		foreach ( array_keys( self::HANDLERS ) as $action ) {
			add_action( 'admin_post_' . self::PREFIX . $action, fn() => $this->dispatch( $action ) );
		}
	}

	/** Hidden inputs for a form posting to $action. */
	public static function fields( string $action ): string {
		return sprintf( '<input type="hidden" name="action" value="%1$s">%2$s', esc_attr( self::PREFIX . $action ), wp_nonce_field( self::PREFIX . $action, '_dms_nonce', true, false ) );
	}

	/** Empty form used by admin.js to post bulk selections after confirmation. */
	public static function hidden_form( string $action, array $values ): void {
		printf( '<form method="post" action="%1$s" data-dms-post-form="%2$s" hidden>%3$s', esc_url( admin_url( 'admin-post.php' ) ), esc_attr( $action ), self::fields( $action ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		foreach ( $values as $name => $value ) {
			printf( '<input type="hidden" name="%1$s" value="%2$s">', esc_attr( $name ), esc_attr( (string) $value ) );
		}
		echo '</form>';
	}

	public function dispatch( string $action ): void {
		check_admin_referer( self::PREFIX . $action, '_dms_nonce' );
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above; handlers validate every value.
		$url  = $this->handle( $action, is_array( $post ) ? $post : array() );
		if ( null !== $url ) {
			wp_safe_redirect( $url );
		}
		exit;
	}

	/**
	 * Runs a handler, converting errors to notices.
	 *
	 * @param array<string,mixed> $post
	 * @return string|null Redirect URL, or null when the handler already sent a response (downloads).
	 */
	public function handle( string $action, array $post ): ?string {
		$method = self::HANDLERS[ $action ] ?? null;
		if ( null === $method ) {
			wp_die( esc_html__( 'Unknown action.', 'dms' ), 400 );
		}
		$referer = wp_get_referer();
		$back    = false !== $referer ? $referer : admin_url( 'admin.php?page=dms-dashboard' );
		try {
			return $this->{$method}( $post, $back );
		} catch ( DmsException $e ) {
			Notices::from_exception( $e );
		} catch ( \Throwable $e ) {
			$reference = $this->plugin->logger()->error(
				'Admin action failed',
				array(
					'action' => $action,
					'error'  => $e->getMessage(),
					'file'   => $e->getFile() . ':' . $e->getLine(),
				)
			);
			/* translators: %s: error reference */
			Notices::add( 'error', sprintf( __( 'Something went wrong. Reference: %s', 'dms' ), $reference ) );
		}
		return $back;
	}

	// ------------------------------------------------------------ handlers
	// Every handler shares the signature (array $post, string $back) so handle() can dispatch uniformly.
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	private function registration( array $p, string $back ): string {
		$id = absint( $p['registration_id'] ?? 0 );
		$wf = $this->plugin->workflow();
		switch ( (string) ( $p['op'] ?? '' ) ) {
			case 'start_review':
				$wf->start_review( $id );
				Notices::success( __( 'Review started.', 'dms' ) );
				break;
			case 'edit':
				$changes = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', (array) ( $p['changes'] ?? array() ) );
				$diff    = $wf->edit( $id, $changes, (string) ( $p['reason'] ?? '' ) );
				Notices::success( array() === $diff ? __( 'No changes to save.', 'dms' ) : __( 'Changes saved.', 'dms' ) );
				break;
			case 'note':
				$wf->add_note( $id, (string) ( $p['note'] ?? '' ) );
				Notices::success( __( 'Note added.', 'dms' ) );
				break;
			case 'approve':
				$wf->approve( $id );
				Notices::success( __( 'Registration approved.', 'dms' ) );
				return AdminMenu::registration_url( $id, 'approved' );
			case 'disapprove':
				$wf->disapprove( $id, (string) ( $p['reason'] ?? '' ) );
				Notices::success( __( 'Registration disapproved and moved to the Bin.', 'dms' ) );
				return AdminMenu::area_url( 'under_review' );
			case 'assign':
				$this->plugin->assignments()->assign( $id, absint( $p['officer_id'] ?? 0 ), (string) ( $p['reason'] ?? '' ) );
				Notices::success( __( 'Registration assigned.', 'dms' ) );
				break;
			case 'reassign':
				$this->plugin->assignments()->reassign( $id, absint( $p['officer_id'] ?? 0 ), (string) ( $p['reason'] ?? '' ) );
				Notices::success( __( 'Registration reassigned.', 'dms' ) );
				break;
			case 'restore':
				$wf->restore( $id );
				Notices::success( __( 'Registration restored to the Holding Area.', 'dms' ) );
				return AdminMenu::registration_url( $id, 'holding' );
			case 'delete':
				if ( empty( $p['confirmed'] ) ) {
					throw new ValidationException( array( 'confirm' => __( 'Please confirm the permanent deletion.', 'dms' ) ) );
				}
				$wf->delete_permanently( $id );
				Notices::success( __( 'Registration permanently deleted.', 'dms' ) );
				return AdminMenu::area_url( 'bin' );
			default:
				throw new ValidationException( array( 'op' => __( 'Unknown action.', 'dms' ) ) );
		}
		return $back;
	}

	private function bulk( array $p, string $back ): ?string {
		$action = sanitize_key( (string) ( $p['bulk_action'] ?? '' ) );
		if ( str_starts_with( $action, 'export_' ) ) {
			$p['format'] = substr( $action, 7 );
			return $this->export( $p, $back );
		}
		$result = $this->plugin->bulk()->execute(
			$action,
			(array) ( $p['ids'] ?? array() ),
			array(
				'officer_id' => $p['officer_id'] ?? 0,
				'reason'     => (string) ( $p['reason'] ?? '' ),
			),
			! empty( $p['confirmed'] )
		);
		$done   = count( $result['processed'] );
		if ( $done > 0 ) {
			/* translators: %d: number of records */
			Notices::success( sprintf( _n( '%d registration processed.', '%d registrations processed.', $done, 'dms' ), $done ) );
		}
		if ( array() !== $result['failed'] ) {
			$details = array();
			foreach ( $result['failed'] as $id => $message ) {
				$record    = $this->plugin->registrations()->find( (int) $id );
				$details[] = ( $record ? $record->registration_number : '#' . $id ) . ': ' . $message;
			}
			/* translators: %d: number of records */
			Notices::add( 'error', sprintf( _n( '%d registration could not be processed:', '%d registrations could not be processed:', count( $details ), 'dms' ), count( $details ) ), $details );
		}
		return AdminMenu::area_url( sanitize_key( (string) ( $p['area'] ?? 'holding' ) ) );
	}

	private function export( array $p, string $back ): ?string {
		$filters = json_decode( (string) ( $p['filters'] ?? '' ), true );
		$area    = sanitize_key( (string) ( $p['area'] ?? '' ) );
		$result  = $this->plugin->exports()->request( get_current_user_id(), $area, sanitize_key( (string) ( $p['format'] ?? 'csv' ) ), is_array( $filters ) ? $filters : array(), (array) ( $p['ids'] ?? array() ) );
		if ( 'queued' === $result['mode'] ) {
			/* translators: %d: number of rows */
			Notices::success( sprintf( __( 'Your export of %d rows is being prepared. It will appear on this page when ready.', 'dms' ), $result['rows'] ) );
			return admin_url( 'admin.php?page=dms-exports' );
		}
		$this->stream( $result['path'], $result['filename'], true );
		return null;
	}

	private function export_download( array $p, string $back ): ?string {
		$file = $this->plugin->exports()->download( get_current_user_id(), (string) ( $p['job_key'] ?? '' ) );
		$this->stream( $file['path'], $file['filename'], false );
		return null;
	}

	private function assign_pending( array $p, string $back ): string {
		$this->plugin->authorizer()->require( 'assignment.assign' );
		$result = $this->plugin->assignments()->assign_pending();
		/* translators: 1: assigned count, 2: still unassigned count */
		Notices::success( sprintf( __( 'Assigned %1$d registration(s). %2$d still have no available officer.', 'dms' ), $result['assigned'], $result['unassigned'] ) );
		return $back;
	}

	private function user_save( array $p, string $back ): string {
		$user_id = absint( $p['user_id'] ?? 0 );
		$input   = array(
			'first_name' => (string) ( $p['first_name'] ?? '' ),
			'last_name'  => (string) ( $p['last_name'] ?? '' ),
			'email'      => (string) ( $p['email'] ?? '' ),
			'role_ids'   => array_map( 'absint', (array) ( $p['role_ids'] ?? array() ) ),
			'region_ids' => array_map( 'absint', (array) ( $p['region_ids'] ?? array() ) ),
		);
		if ( 0 === $user_id ) {
			$input['username'] = (string) ( $p['username'] ?? '' );
			$input['password'] = (string) ( $p['password'] ?? '' );
			$user_id           = $this->plugin->user_service()->create( $input );
			Notices::success( __( 'User created.', 'dms' ) );
		} else {
			$this->plugin->user_service()->update( $user_id, $input );
			Notices::success( __( 'User updated.', 'dms' ) );
		}
		return admin_url( 'admin.php?page=dms-users&user=' . $user_id );
	}

	private function user_status( array $p, string $back ): string {
		$user_id = absint( $p['user_id'] ?? 0 );
		if ( 'disable' === ( $p['status'] ?? '' ) ) {
			$outstanding = $this->plugin->user_service()->disable( $user_id, (string) ( $p['reason'] ?? '' ) );
			/* translators: %d: outstanding registrations */
			Notices::success( sprintf( __( 'User disabled. %d outstanding registration(s) should be reassigned.', 'dms' ), $outstanding ) );
		} else {
			$this->plugin->user_service()->enable( $user_id );
			Notices::success( __( 'User enabled.', 'dms' ) );
		}
		return $back;
	}

	private function role_save( array $p, string $back ): string {
		$role_id     = absint( $p['role_id'] ?? 0 );
		$permissions = array_values( array_map( 'strval', (array) ( $p['permissions'] ?? array() ) ) );
		$roles       = $this->plugin->role_service();
		if ( 0 === $role_id ) {
			$role_id = $roles->create( (string) ( $p['name'] ?? '' ), (string) ( $p['description'] ?? '' ), $permissions );
			Notices::success( __( 'Role created.', 'dms' ) );
		} else {
			$roles->update(
				$role_id,
				array(
					'name'        => (string) ( $p['name'] ?? '' ),
					'description' => (string) ( $p['description'] ?? '' ),
					'status'      => (string) ( $p['status'] ?? 'ACTIVE' ),
				)
			);
			if ( current_user_can( 'roles.assign_permissions' ) ) {
				$roles->set_permissions( $role_id, $permissions );
			}
			Notices::success( __( 'Role saved.', 'dms' ) );
		}
		return admin_url( 'admin.php?page=dms-roles&role=' . $role_id );
	}

	private function role_delete( array $p, string $back ): string {
		$this->plugin->role_service()->delete( absint( $p['role_id'] ?? 0 ) );
		Notices::success( __( 'Role deleted.', 'dms' ) );
		return admin_url( 'admin.php?page=dms-roles' );
	}

	private function form_save( array $p, string $back ): string {
		$custom = array_values( array_filter( (array) ( $p['custom'] ?? array() ), static fn( $c ): bool => is_array( $c ) && ( '' !== trim( (string) ( $c['key'] ?? '' ) ) || '' !== trim( (string) ( $c['label'] ?? '' ) ) ) && empty( $c['remove'] ) ) );
		$this->plugin->form_config()->save(
			array(
				'fields' => (array) ( $p['fields'] ?? array() ),
				'custom' => $custom,
			)
		);
		Notices::success( __( 'Form saved.', 'dms' ) );
		return admin_url( 'admin.php?page=dms-form-builder' );
	}

	private function audit_export( array $p, string $back ): ?string {
		$this->plugin->authorizer()->require( 'audit.view', 'audit.export' );
		$filters             = json_decode( (string) ( $p['filters'] ?? '' ), true );
		$filters             = is_array( $filters ) ? $filters : array();
		$filters['per_page'] = \DMS\Audit\AuditLogQuery::MAX_PER_PAGE;
		$path                = wp_tempnam( 'dms-audit' );
		$writer              = new \DMS\Export\CsvWriter( $path );
		$writer->add_sheet( 'Audit', array( 'ID', 'Time (UTC)', 'Action', 'Registration', 'Object', 'Object ID', 'Old Status', 'New Status', 'Actor', 'Actor Type', 'Reason', 'Details' ) );
		$rows = 0;
		for ( $page = 1; $page <= 100; $page++ ) {
			$filters['page'] = $page;
			$result          = $this->plugin->audit_log()->search( $filters );
			foreach ( $result['items'] as $e ) {
				$writer->add_row( array( $e->id, $e->created_at, $e->action, $e->registration_number, $e->object_type, $e->object_id, $e->old_status, $e->new_status, $e->actor_name, $e->actor_type, $e->reason, $e->metadata ) );
				++$rows;
			}
			if ( count( $result['items'] ) < \DMS\Audit\AuditLogQuery::MAX_PER_PAGE ) {
				break;
			}
		}
		$writer->finish();
		$this->plugin->audit()->record(
			\DMS\Audit\AuditAction::EXPORTED,
			array(
				'object_type' => 'audit',
				'metadata'    => array(
					'format'  => 'csv',
					'filters' => $filters,
					'rows'    => $rows,
				),
			)
		);
		$this->stream( $path, 'audit-log-' . gmdate( 'Ymd-His' ) . '.csv', true );
		return null;
	}

	/**
	 * Upload handler. The file comes from $_FILES; ImportService validates type,
	 * size and content, so only the upload metadata is read here.
	 */
	private function import_upload( array $p, string $back ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce checked in dispatch(); file validated by ImportService.
		$file = $p['_files']['workbook'] ?? ( $_FILES['workbook'] ?? null );
		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'] ) ) {
			throw new ValidationException( array( 'file' => __( 'Choose the completed workbook to upload.', 'dms' ) ) );
		}
		$tmp = (string) $file['tmp_name'];
		if ( ! isset( $p['_files'] ) && ! is_uploaded_file( $tmp ) ) {
			throw new ValidationException( array( 'file' => __( 'The file could not be uploaded. Please try again.', 'dms' ) ) );
		}
		try {
			$batch_id = $this->plugin->imports()->upload( $tmp, (string) $file['name'], (int) ( $file['size'] ?? 0 ), (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) );
		} catch ( \DMS\Imports\ImportFileException $e ) {
			Notices::from_exception( $e );
			$batch = $this->plugin->import_batches()->history( 1, 1 )['items'][0] ?? null;
			return null !== $batch ? \DMS\Admin\Pages\ElectoralDataPage::url( array( 'batch' => $batch->id ) ) : $back;
		}
		$batch = $this->plugin->import_batches()->get( $batch_id );
		if ( \DMS\Imports\ImportStatus::VALIDATED === $batch->status ) {
			Notices::success( __( 'The workbook passed all checks. Review the preview, then confirm the import.', 'dms' ) );
		} else {
			/* translators: %d: number of errors */
			Notices::add( 'error', sprintf( _n( 'The workbook has %d error. Nothing was imported.', 'The workbook has %d errors. Nothing was imported.', (int) $batch->error_count, 'dms' ), (int) $batch->error_count ) );
		}
		return \DMS\Admin\Pages\ElectoralDataPage::url( array( 'batch' => $batch_id ) );
	}

	private function import_confirm( array $p, string $back ): string {
		$batch_id = absint( $p['batch_id'] ?? 0 );
		$this->plugin->imports()->confirm( $batch_id );
		Notices::success( __( 'Import completed.', 'dms' ) );
		return \DMS\Admin\Pages\ElectoralDataPage::url( array( 'batch' => $batch_id ) );
	}

	private function import_rollback( array $p, string $back ): string {
		$batch_id = absint( $p['batch_id'] ?? 0 );
		$result   = $this->plugin->imports()->rollback( $batch_id, ! empty( $p['confirmed'] ) );
		if ( $result['safe'] ) {
			/* translators: 1: removed count, 2: restored count */
			Notices::success( sprintf( __( 'Import rolled back: %1$d record(s) removed, %2$d restored.', 'dms' ), $result['remove'], $result['restore'] ) );
		} else {
			Notices::add( 'error', __( 'Rollback blocked: other data now depends on this import. Nothing was changed.', 'dms' ), array_map( static fn( array $b ): string => $b['code'] . ': ' . $b['reason'], array_slice( $result['blockers'], 0, 20 ) ) );
		}
		return \DMS\Admin\Pages\ElectoralDataPage::url( array( 'batch' => $batch_id ) );
	}

	private function import_template( array $p, string $back ): ?string {
		$with_data = ! empty( $p['with_data'] );
		$path      = $this->plugin->imports()->write_template( $with_data );
		if ( $with_data ) {
			$this->plugin->audit()->record(
				\DMS\Audit\AuditAction::EXPORTED,
				array(
					'object_type' => 'electoral_data',
					'metadata'    => array( 'format' => 'xlsx' ),
				)
			);
		}
		$this->stream( $path, $with_data ? 'electoral-data-' . gmdate( 'Ymd' ) . '.xlsx' : 'Ghana Electoral Data Template.xlsx', true );
		return null;
	}

	private function import_error_report( array $p, string $back ): ?string {
		$this->plugin->authorizer()->require( 'imports.view_history' );
		$batch   = $this->plugin->import_batches()->get( absint( $p['batch_id'] ?? 0 ) );
		$summary = json_decode( (string) $batch->summary, true );
		$path    = wp_tempnam( 'dms-import-errors' );
		$writer  = new \DMS\Export\CsvWriter( $path );
		$writer->add_sheet( 'Errors', array( 'Sheet', 'Row', 'Code', 'Problem' ) );
		foreach ( (array) ( $summary['errors'] ?? array() ) as $e ) {
			$writer->add_row( array( \DMS\Imports\WorkbookSpec::sheets()[ $e['level'] ]['sheet'] ?? $e['level'], $e['row'], $e['code'], $e['message'] ) );
		}
		if ( ! empty( $summary['file_error'] ) ) {
			$writer->add_row( array( '', '', '', $summary['file_error'] ) );
		}
		$writer->finish();
		$this->stream( $path, $batch->import_reference . '-errors.csv', true );
		return null;
	}

	private function notification_retry( array $p, string $back ): string {
		$service = $this->plugin->notifications();
		if ( ! empty( $p['all_failed'] ) ) {
			$this->plugin->authorizer()->require( 'notifications.retry' );
			$sent   = 0;
			$failed = 0;
			$page   = $service->search(
				array(
					'status'   => 'failed',
					'per_page' => 100,
				)
			);
			foreach ( $page['items'] as $n ) {
				if ( null === $n->recipient_email ) {
					continue;
				}
				$service->retry( (int) $n->id ) ? ++$sent : ++$failed;
			}
			/* translators: 1: sent count, 2: still failing count */
			Notices::success( sprintf( __( 'Retried failed emails: %1$d sent, %2$d still failing.', 'dms' ), $sent, $failed ) );
			return $back;
		}
		$ok = $service->retry( absint( $p['notification_id'] ?? 0 ) );
		if ( $ok ) {
			Notices::success( __( 'Email sent.', 'dms' ) );
		} else {
			Notices::add( 'error', __( 'The email could not be sent. Check the site\'s email configuration; the error is shown in the log.', 'dms' ) );
		}
		return $back;
	}

	private function email_templates_save( array $p, string $back ): string {
		$templates = array();
		foreach ( (array) ( $p['templates'] ?? array() ) as $event => $values ) {
			$templates[ (string) $event ] = array(
				'subject' => (string) ( $values['subject'] ?? '' ),
				'body'    => (string) ( $values['body'] ?? '' ),
			);
		}
		try {
			$this->plugin->email_templates()->save( $templates );
		} catch ( ValidationException $e ) {
			// Keep what was typed so it can be corrected rather than re-entered (UI-03).
			set_transient( \DMS\Admin\Pages\NotificationsPage::DRAFT_TRANSIENT . get_current_user_id(), $templates, MINUTE_IN_SECONDS );
			throw $e;
		}
		Notices::success( __( 'Email templates saved.', 'dms' ) );
		return admin_url( 'admin.php?page=' . \DMS\Admin\Pages\NotificationsPage::SLUG . '&tab=templates' );
	}

	private function analytics_export( array $p, string $back ): ?string {
		$filters = json_decode( (string) ( $p['filters'] ?? '' ), true );
		$file    = $this->plugin->reports()->export_analytics( is_array( $filters ) ? $filters : array(), sanitize_key( (string) ( $p['format'] ?? 'csv' ) ) );
		$this->stream( $file['path'], $file['filename'], true );
		return null;
	}

	private function report_export( array $p, string $back ): ?string {
		$filters = json_decode( (string) ( $p['filters'] ?? '' ), true );
		$file    = $this->plugin->reports()->export( sanitize_key( (string) ( $p['report'] ?? '' ) ), is_array( $filters ) ? $filters : array(), sanitize_key( (string) ( $p['format'] ?? 'csv' ) ) );
		$this->stream( $file['path'], $file['filename'], true );
		return null;
	}

	// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed

	private function stream( string $path, string $filename, bool $delete ): void {
		nocache_headers();
		header( 'Content-Type: ' . ( str_ends_with( $filename, '.xlsx' ) ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' : 'text/csv; charset=utf-8' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . (string) filesize( $path ) );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		if ( $delete ) {
			wp_delete_file( $path );
		}
	}
}

<?php
/**
 * Plugin composition root: builds services once and wires WordPress hooks.
 *
 * A small hand-written container keeps dependencies explicit without adding a
 * DI framework (MP §4, §45).
 *
 * @package DMS
 */

namespace DMS;

use DMS\Admin\AdminActions;
use DMS\Admin\AdminMenu;
use DMS\Admin\Pages\AssignmentsPage;
use DMS\Admin\Pages\AuditPage;
use DMS\Admin\Pages\DashboardPage;
use DMS\Admin\Pages\ExportsPage;
use DMS\Admin\Pages\FormBuilderPage;
use DMS\Admin\Pages\RegistrationsPage;
use DMS\Admin\Pages\RolesPage;
use DMS\Admin\Pages\UsersPage;
use DMS\Admin\SettingsPage;
use DMS\Audit\AuditLogQuery;
use DMS\Bulk\BulkActionService;
use DMS\Export\ExportService;
use DMS\Forms\FormConfigService;
use DMS\Registrations\RegistrationListService;
use DMS\Assignments\AssignmentExceptionRepository;
use DMS\Assignments\AssignmentService;
use DMS\Cron\Scheduler;
use DMS\Registrations\AccessPolicy;
use DMS\Retention\RetentionService;
use DMS\Workflow\RegistrationWorkflow;
use DMS\Api\PublicRegistrationController;
use DMS\Api\RestResponder;
use DMS\Audit\AuditAction;
use DMS\Forms\FormDefinition;
use DMS\Forms\FormValidator;
use DMS\Notifications\EmailTemplates;
use DMS\Notifications\NotificationService;
use DMS\Otp\OtpService;
use DMS\Public\RegistrationForm;
use DMS\Registrations\RegistrationSubmissionService;
use DMS\Security\RateLimiter;
use DMS\Security\TurnstileVerifier;
use DMS\Sms\SmsConfiguration;
use DMS\Sms\SmsGatewayRegistry;
use DMS\Support\SecretStore;
use DMS\Support\SettingsService;
use DMS\Audit\AuditService;
use DMS\Database\Migrations\M003SeedRoles;
use DMS\Database\Migrator;
use DMS\Database\SequenceGenerator;
use DMS\Electoral\ElectoralRepository;
use DMS\Imports\ImportBatchRepository;
use DMS\Imports\ImportService;
use DMS\Imports\ImportValidator;
use DMS\Admin\Pages\ElectoralDataPage;
use DMS\Registrations\RegistrationRepository;
use DMS\Permissions\RoleService;
use DMS\Support\Authorizer;
use DMS\Users\OfficerRegionRepository;
use DMS\Users\UserService;
use DMS\Permissions\CapabilityManager;
use DMS\Permissions\PermissionRegistry;
use DMS\Permissions\RoleRepository;
use DMS\Support\Clock;
use DMS\Support\Logger;
use DMS\Support\RequestContext;
use DMS\Support\Settings;
use DMS\Users\UserProfileRepository;
use DMS\Workflow\StateMachine;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?self $instance = null;

	/** @var array<string,object> */
	private array $services = array();

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Test helper: discard built services (e.g. after freezing the clock). */
	public static function reset(): void {
		self::$instance = null;
	}

	public static function boot(): void {
		$plugin = self::instance();
		$plugin->maybe_migrate();
		$plugin->capabilities()->register();
		$plugin->public_controller()->register();
		$plugin->registration_form()->register();
		$plugin->scheduler()->register();
		add_action( 'dms_registration_submitted', array( $plugin, 'on_registration_submitted' ) );
		add_action( 'dms_officer_availability_changed', array( $plugin, 'on_officer_availability_changed' ) );
		add_action( ExportService::CRON_HOOK, array( $plugin->exports(), 'process' ) );
		if ( is_admin() ) {
			$plugin->settings_page()->register();
			$plugin->admin_menu()->register();
			$plugin->admin_actions()->register();
		}
	}

	public static function activate(): void {
		$plugin = self::instance();
		$plugin->migrator()->migrate();
		$plugin->bootstrap_administrator( get_current_user_id() );
	}

	public static function deactivate(): void {
		Scheduler::unschedule();
	}

	/** New public registration → automatic assignment (ARCH §72). */
	public function on_registration_submitted( int $registration_id ): void {
		$this->assignments()->auto_assign( $registration_id );
	}

	/** An officer became available → assign waiting registrations in their regions (ARCH §72.4). */
	public function on_officer_availability_changed( int $user_id ): void {
		$regions = $this->officer_regions()->active_region_ids( $user_id );
		if ( array() !== $regions && $this->capabilities()->user_has_permission( $user_id, 'assignment.receive' ) ) {
			$this->assignments()->assign_pending( $regions );
		}
	}

	/**
	 * Gives the activating site administrator the protected Administrator role
	 * when nobody holds it yet, so the application is never unmanageable.
	 * Does nothing if the role already has a member.
	 */
	public function bootstrap_administrator( int $user_id ): bool {
		if ( $user_id <= 0 || ! user_can( $user_id, 'activate_plugins' ) ) {
			return false;
		}
		if ( $this->roles()->count_system_role_members() > 0 ) {
			return false;
		}
		$role = $this->roles()->find_by_slug( M003SeedRoles::ADMINISTRATOR_SLUG );
		if ( ! $role ) {
			return false;
		}
		$this->profiles()->ensure( $user_id );
		$this->roles()->assign_user( $user_id, (int) $role->id );
		$this->audit()->record(
			AuditAction::USER_ROLES_CHANGED,
			array(
				'object_type' => 'user',
				'object_id'   => $user_id,
				'reason'      => 'Plugin activation: initial Administrator',
				'metadata'    => array( 'added_roles' => array( $role->slug ) ),
			)
		);
		return true;
	}

	private function maybe_migrate(): void {
		if ( ! $this->migrator()->needs_migration() ) {
			return;
		}
		try {
			$this->migrator()->migrate();
		} catch ( \Throwable $e ) {
			// Already logged with a reference by the Migrator. Surface to administrators only.
			add_action(
				'admin_notices',
				static function () use ( $e ): void {
					if ( current_user_can( 'activate_plugins' ) ) {
						printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $e->getMessage() ) );
					}
				}
			);
		}
	}

	// ---- Services ---------------------------------------------------------

	public function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	public function clock(): Clock {
		return $this->service( Clock::class, static fn() => new Clock() );
	}

	public function logger(): Logger {
		return $this->service( Logger::class, static fn() => new Logger() );
	}

	public function context(): RequestContext {
		return $this->service( RequestContext::class, static fn() => new RequestContext() );
	}

	public function settings(): Settings {
		return $this->service( Settings::class, static fn() => new Settings() );
	}

	public function migrator(): Migrator {
		return $this->service( Migrator::class, fn() => new Migrator( $this->db(), $this->logger() ) );
	}

	public function permissions(): PermissionRegistry {
		return $this->service( PermissionRegistry::class, static fn() => new PermissionRegistry() );
	}

	public function roles(): RoleRepository {
		return $this->service( RoleRepository::class, fn() => new RoleRepository( $this->db(), $this->clock() ) );
	}

	public function profiles(): UserProfileRepository {
		return $this->service( UserProfileRepository::class, fn() => new UserProfileRepository( $this->db(), $this->clock() ) );
	}

	public function capabilities(): CapabilityManager {
		return $this->service( CapabilityManager::class, fn() => new CapabilityManager( $this->permissions(), $this->roles(), $this->profiles() ) );
	}

	public function audit(): AuditService {
		return $this->service( AuditService::class, fn() => new AuditService( $this->db(), $this->clock(), $this->context() ) );
	}

	public function state_machine(): StateMachine {
		return $this->service( StateMachine::class, static fn() => new StateMachine() );
	}

	public function authorizer(): Authorizer {
		return $this->service( Authorizer::class, static fn() => new Authorizer() );
	}

	public function sequences(): SequenceGenerator {
		return $this->service( SequenceGenerator::class, fn() => new SequenceGenerator( $this->db(), $this->clock() ) );
	}

	public function electoral(): ElectoralRepository {
		return $this->service( ElectoralRepository::class, fn() => new ElectoralRepository( $this->db(), $this->clock() ) );
	}

	public function registrations(): RegistrationRepository {
		return $this->service( RegistrationRepository::class, fn() => new RegistrationRepository( $this->db(), $this->clock(), $this->sequences(), $this->electoral() ) );
	}

	public function officer_regions(): OfficerRegionRepository {
		return $this->service( OfficerRegionRepository::class, fn() => new OfficerRegionRepository( $this->db(), $this->clock() ) );
	}

	public function import_batches(): ImportBatchRepository {
		return $this->service( ImportBatchRepository::class, fn() => new ImportBatchRepository( $this->db(), $this->clock(), $this->sequences() ) );
	}

	public function role_service(): RoleService {
		return $this->service(
			RoleService::class,
			fn() => new RoleService( $this->db(), $this->roles(), $this->permissions(), $this->capabilities(), $this->audit(), $this->authorizer(), $this->context() )
		);
	}

	public function user_service(): UserService {
		return $this->service(
			UserService::class,
			fn() => new UserService( $this->db(), $this->roles(), $this->profiles(), $this->officer_regions(), $this->electoral(), $this->capabilities(), $this->audit(), $this->authorizer(), $this->context() )
		);
	}

	public function secrets(): SecretStore {
		return $this->service( SecretStore::class, static fn() => new SecretStore() );
	}

	public function sms_gateways(): SmsGatewayRegistry {
		return $this->service( SmsGatewayRegistry::class, fn() => new SmsGatewayRegistry( $this->secrets(), $this->settings() ) );
	}

	public function sms(): SmsConfiguration {
		return $this->service( SmsConfiguration::class, fn() => new SmsConfiguration( $this->settings(), $this->sms_gateways() ) );
	}

	public function rate_limiter(): RateLimiter {
		return $this->service( RateLimiter::class, fn() => new RateLimiter( $this->db(), $this->clock() ) );
	}

	public function turnstile(): TurnstileVerifier {
		return $this->service( TurnstileVerifier::class, fn() => new TurnstileVerifier( $this->settings(), $this->secrets(), $this->logger() ) );
	}

	public function settings_service(): SettingsService {
		return $this->service(
			SettingsService::class,
			fn() => new SettingsService( $this->settings(), $this->secrets(), $this->sms_gateways(), $this->sms(), $this->turnstile(), $this->audit(), $this->authorizer() )
		);
	}

	public function form_definition(): FormDefinition {
		return $this->service( FormDefinition::class, static fn() => new FormDefinition() );
	}

	public function form_validator(): FormValidator {
		return $this->service( FormValidator::class, fn() => new FormValidator( $this->form_definition() ) );
	}

	public function otp(): OtpService {
		return $this->service(
			OtpService::class,
			fn() => new OtpService( $this->db(), $this->clock(), $this->settings(), $this->sms(), $this->rate_limiter(), $this->audit(), $this->logger(), $this->context() )
		);
	}

	public function notifications(): NotificationService {
		return $this->service( NotificationService::class, fn() => new NotificationService( $this->db(), $this->clock(), $this->settings(), $this->audit(), $this->logger(), $this->email_templates(), $this->authorizer() ) );
	}

	public function submissions(): RegistrationSubmissionService {
		return $this->service(
			RegistrationSubmissionService::class,
			fn() => new RegistrationSubmissionService(
				$this->db(),
				$this->clock(),
				$this->settings(),
				$this->form_validator(),
				$this->electoral(),
				$this->registrations(),
				$this->otp(),
				$this->turnstile(),
				$this->rate_limiter(),
				$this->notifications(),
				$this->audit(),
				$this->logger(),
				$this->context()
			)
		);
	}

	public function public_controller(): PublicRegistrationController {
		return $this->service(
			PublicRegistrationController::class,
			fn() => new PublicRegistrationController( $this->submissions(), $this->electoral(), $this->form_definition(), $this->turnstile(), $this->rate_limiter(), $this->settings(), $this->context(), new RestResponder( $this->logger() ) )
		);
	}

	public function registration_form(): RegistrationForm {
		return $this->service( RegistrationForm::class, fn() => new RegistrationForm( $this->form_definition(), $this->electoral(), $this->settings(), $this->turnstile() ) );
	}

	public function settings_page(): SettingsPage {
		return $this->service(
			SettingsPage::class,
			fn() => new SettingsPage( $this->settings(), $this->settings_service(), $this->secrets(), $this->sms_gateways(), $this->sms(), $this->turnstile() )
		);
	}

	public function access_policy(): AccessPolicy {
		return $this->service( AccessPolicy::class, static fn() => new AccessPolicy() );
	}

	public function assignment_exceptions(): AssignmentExceptionRepository {
		return $this->service( AssignmentExceptionRepository::class, fn() => new AssignmentExceptionRepository( $this->db(), $this->clock() ) );
	}

	public function assignments(): AssignmentService {
		return $this->service(
			AssignmentService::class,
			fn() => new AssignmentService(
				$this->db(),
				$this->clock(),
				$this->registrations(),
				$this->electoral(),
				$this->officer_regions(),
				$this->profiles(),
				$this->capabilities(),
				$this->assignment_exceptions(),
				$this->state_machine(),
				$this->notifications(),
				$this->audit(),
				$this->authorizer(),
				$this->logger(),
				$this->context()
			)
		);
	}

	public function workflow(): RegistrationWorkflow {
		return $this->service(
			RegistrationWorkflow::class,
			fn() => new RegistrationWorkflow(
				$this->db(),
				$this->clock(),
				$this->registrations(),
				$this->state_machine(),
				$this->access_policy(),
				$this->form_validator(),
				$this->assignments(),
				$this->assignment_exceptions(),
				$this->notifications(),
				$this->audit(),
				$this->authorizer(),
				$this->logger(),
				$this->context()
			)
		);
	}

	public function retention(): RetentionService {
		return $this->service(
			RetentionService::class,
			fn() => new RetentionService( $this->db(), $this->clock(), $this->settings(), $this->registrations(), $this->workflow(), $this->rate_limiter(), $this->logger() )
		);
	}

	public function lists(): RegistrationListService {
		return $this->service( RegistrationListService::class, fn() => new RegistrationListService( $this->registrations(), $this->access_policy() ) );
	}

	public function bulk(): BulkActionService {
		return $this->service( BulkActionService::class, fn() => new BulkActionService( $this->workflow(), $this->assignments(), $this->audit(), $this->authorizer(), $this->logger() ) );
	}

	public function exports(): ExportService {
		return $this->service(
			ExportService::class,
			fn() => new ExportService( $this->db(), $this->clock(), $this->settings(), $this->lists(), $this->registrations(), $this->form_definition(), $this->audit(), $this->logger() )
		);
	}

	public function form_config(): FormConfigService {
		return $this->service( FormConfigService::class, fn() => new FormConfigService( $this->audit(), $this->authorizer() ) );
	}

	public function audit_log(): AuditLogQuery {
		return $this->service( AuditLogQuery::class, fn() => new AuditLogQuery( $this->db() ) );
	}

	public function import_validator(): ImportValidator {
		return $this->service( ImportValidator::class, fn() => new ImportValidator( $this->db(), $this->electoral() ) );
	}

	public function imports(): ImportService {
		return $this->service(
			ImportService::class,
			fn() => new ImportService( $this->db(), $this->clock(), $this->settings(), $this->electoral(), $this->import_batches(), $this->import_validator(), $this->audit(), $this->authorizer(), $this->logger(), $this->context() )
		);
	}

	public function page_electoral(): ElectoralDataPage {
		return $this->service( ElectoralDataPage::class, fn() => new ElectoralDataPage( $this ) );
	}

	public function page_notifications(): \DMS\Admin\Pages\NotificationsPage {
		return $this->service( \DMS\Admin\Pages\NotificationsPage::class, fn() => new \DMS\Admin\Pages\NotificationsPage( $this ) );
	}

	public function analytics(): \DMS\Analytics\AnalyticsService {
		return $this->service( \DMS\Analytics\AnalyticsService::class, fn() => new \DMS\Analytics\AnalyticsService( $this->db(), $this->clock(), $this->electoral() ) );
	}

	public function reports(): \DMS\Analytics\ReportService {
		return $this->service( \DMS\Analytics\ReportService::class, fn() => new \DMS\Analytics\ReportService( $this->db(), $this->analytics(), $this->audit(), $this->authorizer() ) );
	}

	public function page_analytics(): \DMS\Admin\Pages\AnalyticsPage {
		return $this->service( \DMS\Admin\Pages\AnalyticsPage::class, fn() => new \DMS\Admin\Pages\AnalyticsPage( $this ) );
	}

	public function page_reports(): \DMS\Admin\Pages\ReportsPage {
		return $this->service( \DMS\Admin\Pages\ReportsPage::class, fn() => new \DMS\Admin\Pages\ReportsPage( $this ) );
	}

	public function email_templates(): EmailTemplates {
		return $this->service( EmailTemplates::class, fn() => new EmailTemplates( $this->audit(), $this->authorizer() ) );
	}

	public function admin_menu(): AdminMenu {
		return $this->service( AdminMenu::class, fn() => new AdminMenu( $this ) );
	}

	public function admin_actions(): AdminActions {
		return $this->service( AdminActions::class, fn() => new AdminActions( $this ) );
	}

	public function page_dashboard(): DashboardPage {
		return $this->service( DashboardPage::class, fn() => new DashboardPage( $this ) );
	}

	public function page_registrations(): RegistrationsPage {
		return $this->service( RegistrationsPage::class, fn() => new RegistrationsPage( $this ) );
	}

	public function page_assignments(): AssignmentsPage {
		return $this->service( AssignmentsPage::class, fn() => new AssignmentsPage( $this ) );
	}

	public function page_exports(): ExportsPage {
		return $this->service( ExportsPage::class, fn() => new ExportsPage( $this ) );
	}

	public function page_users(): UsersPage {
		return $this->service( UsersPage::class, fn() => new UsersPage( $this ) );
	}

	public function page_roles(): RolesPage {
		return $this->service( RolesPage::class, fn() => new RolesPage( $this ) );
	}

	public function page_audit(): AuditPage {
		return $this->service( AuditPage::class, fn() => new AuditPage( $this ) );
	}

	public function page_form_builder(): FormBuilderPage {
		return $this->service( FormBuilderPage::class, fn() => new FormBuilderPage( $this ) );
	}

	public function scheduler(): Scheduler {
		return $this->service( Scheduler::class, fn() => new Scheduler( $this->retention(), $this->assignments(), $this->logger() ) );
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @param callable():T    $factory
	 * @return T
	 */
	private function service( string $id, callable $factory ): object {
		return $this->services[ $id ] ??= $factory();
	}
}

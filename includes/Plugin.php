<?php
/**
 * Entry point: checks the dependencies and wires the components.
 *
 * @package WCSubscriptionTeamAccess
 */

declare( strict_types=1 );

namespace WCSubscriptionTeamAccess;

use WCSubscriptionTeamAccess\Admin\AdminPage;
use WCSubscriptionTeamAccess\Admin\SubscriptionMetaBox;
use WCSubscriptionTeamAccess\CLI\Command;
use WCSubscriptionTeamAccess\Repository\GrantRepository;
use WCSubscriptionTeamAccess\Repository\Logger;
use WCSubscriptionTeamAccess\Repository\SeatRepository;

/**
 * Service container (single instance).
 */
final class Plugin {

	private static ?self $instance = null;

	private SeatRepository $seats;
	private GrantRepository $grants;
	private Logger $logger;
	private QuotaManager $quotas;
	private SubscriptionManager $subscriptions;
	private MembershipManager $memberships;
	private Reconciler $reconciler;
	private InvitationController $invitations;
	private Emails $emails;
	private TeamManager $team;

	/** Boots on "plugins_loaded". */
	public static function boot(): void {
		add_action( 'init', array( self::class, 'load_textdomain' ), 0 );

		$missing = self::missing_dependencies();
		if ( $missing ) {
			add_action(
				'admin_notices',
				static function () use ( $missing ): void {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: list of missing plugins. */
								__( 'WC Subscription Team Access is inactive: the following plugins are required: %s.', 'wc-subscription-team-access' ),
								implode( ', ', $missing )
							)
						)
					);
				}
			);
			return;
		}

		self::instance()->register();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function load_textdomain(): void {
		load_plugin_textdomain( 'wc-subscription-team-access', false, dirname( plugin_basename( WCSTA_FILE ) ) . '/languages' );
	}

	/**
	 * Missing required plugins.
	 *
	 * @return string[]
	 */
	public static function missing_dependencies(): array {
		$missing = array();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$missing[] = 'WooCommerce';
		}
		if ( ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_get_subscription' ) ) {
			$missing[] = 'WooCommerce Subscriptions';
		}
		if ( ! function_exists( 'wc_memberships' ) ) {
			$missing[] = 'WooCommerce Memberships';
		}
		return $missing;
	}

	private function __construct() {
		$this->seats         = new SeatRepository();
		$this->grants        = new GrantRepository();
		$this->logger        = new Logger();
		$this->quotas        = new QuotaManager( $this->logger );
		$this->subscriptions = new SubscriptionManager( $this->quotas, $this->seats );
		$this->memberships   = new MembershipManager( $this->grants, $this->logger );
		$this->reconciler    = new Reconciler( $this->seats, $this->grants, $this->subscriptions, $this->quotas, $this->memberships, $this->logger );
		$this->invitations   = new InvitationController();
		$this->emails        = new Emails( $this->invitations );
		$this->team          = new TeamManager( $this->seats, $this->subscriptions, $this->reconciler, $this->emails, $this->logger );
		$this->invitations->set_team( $this->team );
	}

	private function register(): void {
		Installer::maybe_upgrade();
		add_action( 'init', array( Installer::class, 'maybe_flush_rewrite_rules' ), 99 );

		( new ProductSettings() )->register();
		$this->quotas->register();
		$this->memberships->register();
		( new SubscriptionEvents( $this->reconciler, $this->quotas, $this->seats, $this->subscriptions ) )->register();
		$this->team->register();
		$this->invitations->register();
		$this->emails->register();
		( new AccountEndpoint( $this->team, $this->subscriptions ) )->register();
		( new Scheduler( $this->reconciler ) )->register();
		( new Privacy( $this->seats, $this->team ) )->register();

		if ( is_admin() ) {
			( new AdminPage( $this->team, $this->reconciler, $this->subscriptions, $this->quotas, $this->seats, $this->grants, $this->memberships, $this->logger ) )->register();
			( new SubscriptionMetaBox( $this->team, $this->quotas ) )->register();
			( new \WCSubscriptionTeamAccess\Admin\Help() )->register();
			add_filter(
				'woocommerce_get_settings_pages',
				static function ( $pages ) {
					$pages   = is_array( $pages ) ? $pages : array();
					$pages[] = new \WCSubscriptionTeamAccess\Admin\SettingsPage();
					return $pages;
				}
			);
			add_filter( 'plugin_action_links_' . plugin_basename( WCSTA_FILE ), array( self::class, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'team-access', new Command( $this->team, $this->reconciler, $this->subscriptions, $this->quotas, $this->seats ) );
		}
	}

	/**
	 * "Settings", "Shared subscriptions" and "Help" links in the plugins list.
	 *
	 * @param string[] $links Links.
	 * @return string[]
	 */
	public static function action_links( $links ): array {
		$links = is_array( $links ) ? $links : array();
		array_unshift(
			$links,
			'<a href="' . esc_url( \WCSubscriptionTeamAccess\Admin\SettingsPage::url() ) . '">' . esc_html__( 'Settings', 'wc-subscription-team-access' ) . '</a>',
			'<a href="' . esc_url( \WCSubscriptionTeamAccess\Admin\AdminPage::url() ) . '">' . esc_html__( 'Shared subscriptions', 'wc-subscription-team-access' ) . '</a>'
		);
		$links[] = '<a href="' . esc_url( \WCSubscriptionTeamAccess\Admin\Help::DOCUMENTATION_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Documentation', 'wc-subscription-team-access' ) . '</a>';
		return $links;
	}

	public function seats(): SeatRepository {
		return $this->seats;
	}

	public function grants(): GrantRepository {
		return $this->grants;
	}

	public function logger(): Logger {
		return $this->logger;
	}

	public function quotas(): QuotaManager {
		return $this->quotas;
	}

	public function subscriptions(): SubscriptionManager {
		return $this->subscriptions;
	}

	public function memberships(): MembershipManager {
		return $this->memberships;
	}

	public function reconciler(): Reconciler {
		return $this->reconciler;
	}

	public function emails(): Emails {
		return $this->emails;
	}

	public function team(): TeamManager {
		return $this->team;
	}

	public function invitations(): InvitationController {
		return $this->invitations;
	}
}

<?php

namespace Kinglet\Admin;

/**
 * Class PageBase
 */
abstract class PageBase {

	/**
	 * Hook generated by add_menu_page() or add_submenu_page(). Must be assigned
	 * when menu page function is executed.
	 *
	 * @var string
	 */
	public $pageHook;

	/**
	 * Help ensure that an action was executed by the router.
	 *
	 * @var bool
	 */
	private $routed = FALSE;

	/**
	 * Where messages are stored in the options table.
	 *
	 * @var string
	 */
	protected $messagesOptionName = 'kinglet_admin_messages';

	/**
	 * Array of stored messages.
	 *
	 * @var array
	 */
	public $messages = [];

	/**
	 * This page's title.
	 *
	 * @return string
	 */
	abstract function title();

	/**
	 * This page's description.
	 *
	 * @return string
	 */
	abstract function description();

	/**
	 * This page's unique slug.
	 *
	 * @return string
	 */
	abstract function slug();

	/**
	 * Override in child to produce page output.
	 *
	 * @return string
	 */
	abstract function page();

	/**
	 * PageBase constructor.
	 */
	public function __construct() {
		if ( is_callable( [ $this, 'scripts' ] ) ) {
			add_action( 'admin_enqueue_scripts', [ $this, 'scripts' ] );
		}

		$this->loadMessages();
	}

	/**
	 * Load the stored messages.
	 */
	public function loadMessages() {
		$this->messages = get_option( $this->messagesOptionName, [] );
	}

	/**
	 * Save the messages.
	 */
	protected function saveMessages() {
		update_option( $this->messagesOptionName, $this->messages, FALSE );
	}

	/**
	 * Add a new message for the current user.
	 *
	 * @param $message
	 * @param $type
	 */
	public function addMessage( $message, $type ) {
		$uid = wp_get_current_user()->ID;
		$hash = md5( $message . $type );

		$this->messages[ $uid ][ $hash ] = [
			'message' => $message,
			'type' => $type,
			'timestamp' => time(),
		];

		$this->saveMessages();
	}

	/**
	 * Get messages for the current user and clear them.
	 *
	 * @return array
	 */
	public function getMessages() {
		$uid = wp_get_current_user()->ID;

		if ( ! empty( $this->messages[ $uid ] ) ) {
			$messages = array_values( $this->messages[ $uid ] );
			unset( $this->messages[ $uid ] );
			$this->saveMessages();

			return $messages;
		}

		return [];
	}

	/**
	 * Capability required to access this page.
	 *
	 * @return string
	 */
	public function capability() {
		return 'manage_options';
	}

	/**
	 * This page's menu title.
	 *
	 * @return string
	 */
	public function menuTitle() {
		return $this->title();
	}

	/**
	 * Array of action_name => callable pairs
	 *
	 * @return array
	 */
	public function actions() {
		return [];
	}

	/**
	 * Helper function to determine if the user is on this admin page.
	 *
	 * @return bool
	 */
	public function onPage() {
		$screen = get_current_screen();

		return $screen->id == $this->pageHook;
	}

	/**
	 * Parent page base.
	 *
	 * @return string
	 */
	public function parentSlug() {
		return 'admin.php';
	}

	/**
	 * Helper function to get the relative page path.
	 *
	 * @return string
	 */
	public function pagePath() {
		$delimiter = stripos( $this->parentSlug(), '?' ) === FALSE ? '?' : '&';

		return $this->parentSlug() . $delimiter . 'page=' . $this->slug();
	}

	/**
	 * Helper function to get the full page url.
	 *
	 * @return string
	 */
	public function pageUrl() {
		return admin_url( $this->pagePath() );
	}

	/**
	 * Add the page as a top-level admin menu item in the admin dashboard.
	 *
	 * @param string $icon_url
	 * @param null $position
	 */
	public function addToMenu( $icon_url = '', $position = null ) {
		$this->pageHook = add_menu_page(
			$this->title(),
			$this->menuTitle(),
			$this->capability(),
			$this->slug(),
			[ $this, 'route' ],
			$icon_url,
			$position
		);
	}

	/**
	 * @param \Kinglet\Admin\PageBase|string $parent
	 *   The parent page object, or the parent's slug.
	 * @param null $position
	 */
	public function addToSubMenu( $parent, $position = null ) {
		if ( $parent instanceof PageBase ) {
			$parent = $parent->slug();
		}
		$this->pageHook = add_submenu_page(
			$parent,
			$this->title(),
			$this->menuTitle(),
			$this->capability(),
			$this->slug(),
			[ $this, 'route' ]
		);
	}

	/**
	 * Standard action path creation.
	 *
	 * @param $action
	 *
	 * @return string
	 */
	public function actionPath( $action ) {
		return wp_nonce_url( "{$this->pagePath()}&action={$action}&noheader=true", $this->slug() . $action );
	}

	/**
	 * Determine an appropriate redirect path.
	 *
	 * @return string
	 */
	public function redirectPath() {
		return ! empty( $_SERVER['HTTP_REFERER'] ) ? $_SERVER['HTTP_REFERER'] : $this->pageUrl();
	}

	/**
	 * Route the page navigation
	 */
	public function route() {
		// If an action is requested, route to the action.
		if ( ! empty( $_GET['action'] ) ) {
			$this->routeAction( $_GET['action'] );
		}

		// Otherwise, show the page contents.
		$this->showPage();
	}

	/**
	 * Handle a router action.
	 *
	 * @param $action
	 */
	public function routeAction( $action ) {
		$redirect = $this->redirectPath();
		$actions = $this->actions();
		$valid_action = $action && ! empty( $actions[ $action ] ) && is_callable( $actions[ $action ] );

		// All actions should be nonced and verified.
		$nonce = ! empty( $_REQUEST['_wpnonce'] ) ? $_REQUEST['_wpnonce'] : FALSE;
		$verified = FALSE;

		if ( $nonce ) {
			$verified = wp_verify_nonce( $nonce, $this->slug() . $action );
		}

		// If we have a valid action and a verified nonce, execute the action.
		if ( $valid_action && $nonce && $verified ) {
			$this->routed = TRUE;
			$result = call_user_func( $actions[ $action ] );

			if ( is_array( $result ) && ! empty( $result['redirect'] ) ) {
				$redirect = $result['redirect'];
			}

			if ( is_array( $result ) && ! empty( $result['message'] ) ) {
				$this->addMessage( $result['message'], $result['type'] );
			}

			wp_safe_redirect( $redirect );
			exit;
		}

		// If invalid action or unverifiable nonce, back to page without execution.
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Manually validate that an action was executed by the router, otherwise
	 * bail before executing the action.
	 *
	 * Place at the top of any action on a page to protect it from arbitrary
	 * execution.
	 */
	public function validateAction() {
		$valid = FALSE;

		if ( $this->routed && $this->onPage() ) {
			$valid = TRUE;
		}

		if ( ! $valid ) {
			wp_safe_redirect( $this->redirectPath() );
			exit;
		}
	}

	/**
	 * Output the page.
	 */
	public function showPage() {
		$messages = $this->getMessages();
		$descriptions = $this->description();
		if ( ! empty( $descriptions ) && ! is_array( $descriptions ) ) {
			$descriptions = [ $descriptions ];
		}
		?>
		<div class="wrap <?php print $this->slug() ?>-wrapper">
			<h2><?php print $this->title(); ?></h2>

			<?php if ( ! empty( $messages ) ): ?>
				<div id="message">
					<?php foreach ( $messages as $message ): ?>
						<div class="<?php print $message['type'] ?> notice is-dismissible">
							<p><?php print $message['message'] ?></p>
						</div>
					<?php endforeach ?>
				</div>
			<?php endif ?>

			<?php if ( ! empty( $descriptions ) ): ?>
				<div class="box description">
					<?php foreach ( $descriptions as $description ): ?>
						<p><?php print $description ?></p>
					<?php endforeach ?>
				</div>
			<?php endif ?>

			<div class="<?php print $this->slug() ?>-content">
				<?php print $this->page() ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Standard action result array
	 *
	 * @param string $message
	 * @param null $redirect
	 * @param string $type
	 *
	 * @return array
	 */
	public function result( $message, $redirect = NULL, $type = 'updated' ) {
		$result = [
			'message' => $message,
			'type' => $type,
		];

		if ( $redirect ) {
			$result['redirect'] = $redirect;
		}

		return $result;
	}

	/**
	 * An error result.
	 *
	 * @param $message
	 * @param null $redirect
	 *
	 * @return array
	 */
	public function error( $message = NULL, $redirect = NULL ) {
		if ( ! $message ) {
			$message = __( 'Something went wrong, please refresh the page and try again.' );
		}

		return $this->result( $message, $redirect, 'error' );
	}

	/**
	 * Very simple debug.
	 *
	 * @param [...$v] Any number of values to debug.
	 *
	 * @return string
	 */
	public function d() {
		ob_start();
		foreach (func_get_args() as $value) {
			if (function_exists('dump')) {
				dump($value);
			}
			else {
				echo "<pre>" . print_r( $value, 1 ) . "</pre>";
			}
		}
		echo ob_get_clean();
	}

}

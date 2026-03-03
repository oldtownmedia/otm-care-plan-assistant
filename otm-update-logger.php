<?php
/**
 * Plugin Name: OTM Care Plan Assistant
 * Plugin URI: https://meetotm.com
 * Description: Tracks updates across your WordPress site and stores them for reporting. Runs automatically in the background.
 * Author: OTM
 * Version: 1.0.1
 * License: GPL-2.0+
 * Text Domain: otm-care-plan-assistant
 *
 * Logs core, plugin, and theme updates to a custom database table and exposes
 * records via authenticated REST API for aggregation by external care plan services.
 *
 * @package OTM_Update_Logger
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'OTM_UL_VERSION', '1.0.1' );
define( 'OTM_UL_OPTION_KEY', 'otm_ul_api_key' );

/**
 * GitHub repo URL for update checks. Change this to your repo when publishing.
 *
 * @var string
 */
define( 'OTM_UL_GITHUB_REPO', 'https://github.com/oldtownmedia/otm-care-plan-assistant/' );

/**
 * Initialize Plugin Update Checker for GitHub-based updates.
 */
function otm_ul_init_update_checker() {
	if ( ! is_admin() && ! wp_doing_cron() ) {
		return;
	}

	$puc_file = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
	if ( ! file_exists( $puc_file ) ) {
		return;
	}

	require $puc_file;

	if ( ! class_exists( 'YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
		return;
	}

	$update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		OTM_UL_GITHUB_REPO,
		__FILE__,
		'otm-update-logger'
	);

	$update_checker->setBranch( 'main' );
	$update_checker->getVcsApi()->enableReleaseAssets();
}
add_action( 'plugins_loaded', 'otm_ul_init_update_checker', 5 );

/**
 * Get the custom table name with prefix.
 *
 * @return string
 */
function otm_ul_get_table_name() {
	global $wpdb;
	return $wpdb->prefix . 'otm_update_log';
}

/**
 * Activation: create table and generate API key if missing.
 */
function otm_ul_activate() {
	global $wpdb;
	$table_name = otm_ul_get_table_name();
	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE $table_name (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		update_type varchar(20) NOT NULL,
		name varchar(255) NOT NULL,
		slug varchar(255) NOT NULL,
		old_version varchar(50) DEFAULT '',
		new_version varchar(50) NOT NULL,
		status varchar(20) NOT NULL DEFAULT 'success',
		updated_at datetime NOT NULL,
		PRIMARY KEY (id),
		KEY updated_at (updated_at),
		KEY update_type (update_type)
	) $charset_collate;";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );

	if ( ! get_option( OTM_UL_OPTION_KEY ) ) {
		update_option( OTM_UL_OPTION_KEY, wp_generate_password( 40, false ) );
	}
}
register_activation_hook( __FILE__, 'otm_ul_activate' );

/**
 * Insert a log entry with deduplication (skip if same slug/new_version within 60 seconds).
 *
 * @param string $update_type 'plugin', 'theme', or 'core'
 * @param string $name Human-readable name
 * @param string $slug Plugin/theme slug
 * @param string $old_version Version before update (empty when unavailable)
 * @param string $new_version Version after update
 * @param string $status 'success' or 'error'
 * @return bool True if inserted, false if skipped (duplicate)
 */
function otm_ul_insert_log( $update_type, $name, $slug, $old_version, $new_version, $status = 'success' ) {
	global $wpdb;
	$table = otm_ul_get_table_name();
	$cutoff = date( 'Y-m-d H:i:s', strtotime( '-60 seconds', current_time( 'timestamp' ) ) );

	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM $table WHERE update_type = %s AND slug = %s AND new_version = %s AND updated_at >= %s",
			$update_type,
			$slug,
			$new_version,
			$cutoff
		)
	);

	if ( $existing ) {
		return false;
	}

	$updated_at = current_time( 'mysql' );
	$wpdb->insert(
		$table,
		array(
			'update_type'  => $update_type,
			'name'        => $name,
			'slug'        => $slug,
			'old_version' => $old_version,
			'new_version' => $new_version,
			'status'      => $status,
			'updated_at'  => $updated_at,
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return true;
}

/**
 * Handle upgrader_process_complete (manual updates + InfiniteWP remote updates).
 *
 * @param WP_Upgrader $upgrader Upgrader instance
 * @param array       $hook_extra Update metadata
 */
function otm_ul_on_upgrader_complete( $upgrader, $hook_extra ) {
	if ( empty( $hook_extra['action'] ) || $hook_extra['action'] !== 'update' ) {
		return;
	}

	$type = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
	if ( ! in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
		return;
	}

	if ( $type === 'plugin' && ! empty( $hook_extra['plugins'] ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_dir_real = realpath( WP_PLUGIN_DIR );
		if ( ! $plugin_dir_real ) {
			return;
		}
		foreach ( (array) $hook_extra['plugins'] as $path ) {
			$path = str_replace( '\\', '/', $path );
			if ( strpos( $path, '..' ) !== false || strpos( $path, '/' ) === 0 ) {
				continue;
			}
			$full_path = WP_PLUGIN_DIR . '/' . $path;
			if ( ! file_exists( $full_path ) ) {
				continue;
			}
			$resolved = realpath( $full_path );
			if ( ! $resolved || strpos( $resolved, $plugin_dir_real ) !== 0 ) {
				continue;
			}
			$data = get_plugin_data( $full_path, false, false );
			$name = isset( $data['Name'] ) ? $data['Name'] : $path;
			$version = isset( $data['Version'] ) ? $data['Version'] : '';
			$slug = dirname( $path );
			if ( $slug === '.' ) {
				$slug = basename( $path, '.php' );
			}
			otm_ul_insert_log( 'plugin', $name, $slug, '', $version, 'success' );
		}
		return;
	}

	if ( $type === 'theme' && ! empty( $hook_extra['themes'] ) ) {
		foreach ( (array) $hook_extra['themes'] as $slug ) {
			$theme = wp_get_theme( $slug );
			if ( ! $theme->exists() ) {
				continue;
			}
			$name = $theme->get( 'Name' );
			$version = $theme->get( 'Version' );
			otm_ul_insert_log( 'theme', $name, $slug, '', $version, 'success' );
		}
		return;
	}

	if ( $type === 'core' ) {
		$version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : '';
		otm_ul_insert_log( 'core', 'WordPress Core', 'wordpress', '', $version, 'success' );
	}
}
add_action( 'upgrader_process_complete', 'otm_ul_on_upgrader_complete', 10, 2 );

/**
 * Handle automatic_updates_complete (WordPress 5.5+ background updates).
 *
 * @param array $results Update results keyed by 'plugin', 'theme', 'core'
 */
function otm_ul_on_automatic_updates_complete( $results ) {
	if ( ! is_array( $results ) ) {
		return;
	}

	$types = array( 'plugin', 'theme', 'core' );
	foreach ( $types as $type ) {
		if ( empty( $results[ $type ] ) || ! is_array( $results[ $type ] ) ) {
			continue;
		}
		foreach ( $results[ $type ] as $r ) {
			if ( ! isset( $r->item ) ) {
				continue;
			}
			$item = $r->item;
			$status = ( $r->result === true || ( isset( $r->result ) && ! is_wp_error( $r->result ) ) ) ? 'success' : 'error';

			$old_version = '';
			$new_version = '';
			$name = '';
			$slug = '';

			if ( $type === 'core' ) {
				$name = 'WordPress Core';
				$slug = 'wordpress';
				$old_version = isset( $item->current ) ? $item->current : '';
				$new_version = isset( $item->version ) ? $item->version : '';
			} else {
				$slug = isset( $item->slug ) ? $item->slug : '';
				$name = isset( $item->name ) ? $item->name : ( isset( $item->theme ) ? $item->theme : $slug );
				$name = $name ? $name : $slug;
				$old_version = isset( $item->current_version ) ? $item->current_version : ( isset( $item->current ) ? $item->current : '' );
				$new_version = isset( $item->new_version ) ? $item->new_version : ( isset( $item->version ) ? $item->version : '' );
			}

			if ( $slug && $new_version ) {
				otm_ul_insert_log( $type, $name, $slug, $old_version, $new_version, $status );
			}
		}
	}
}
add_action( 'automatic_updates_complete', 'otm_ul_on_automatic_updates_complete' );

/**
 * REST API: Check API key from request.
 *
 * @param WP_REST_Request $request Request object
 * @return bool|WP_Error True if valid, WP_Error with 401 if invalid
 */
function otm_ul_check_api_key( $request ) {
	$key = '';
	$auth = $request->get_header( 'Authorization' );
	if ( $auth && preg_match( '/^Bearer\s+(.+)$/i', $auth, $m ) ) {
		$key = trim( $m[1] );
	} elseif ( $request->get_param( 'api_key' ) !== null ) {
		$key = (string) $request->get_param( 'api_key' );
	}

	$stored = get_option( OTM_UL_OPTION_KEY, '' );
	if ( ! $stored || ! hash_equals( (string) $stored, (string) $key ) ) {
		return new WP_Error( 'rest_forbidden', __( 'Invalid or missing API key.', 'otm-update-logger' ), array( 'status' => 401 ) );
	}
	return true;
}

/**
 * Register REST API routes.
 */
function otm_ul_register_rest_routes() {
	register_rest_route(
		'otm-updates/v1',
		'/log',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'otm_ul_rest_log',
			'permission_callback' => 'otm_ul_check_api_key',
			'args'                => array(
				'since' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'until' => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'type'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'limit' => array(
					'type'              => 'integer',
					'default'           => 200,
					'minimum'           => 1,
					'maximum'           => 1000,
					'sanitize_callback' => 'absint',
				),
			),
		)
	);

	register_rest_route(
		'otm-updates/v1',
		'/info',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'otm_ul_rest_info',
			'permission_callback' => 'otm_ul_check_api_key',
		)
	);
}
add_action( 'rest_api_init', 'otm_ul_register_rest_routes' );

/**
 * REST callback: GET /otm-updates/v1/log
 *
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response
 */
function otm_ul_rest_log( $request ) {
	global $wpdb;
	$table = otm_ul_get_table_name();

	$where = array( '1=1' );
	$values = array();

	$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	$since = $request->get_param( 'since' );
	if ( $since ) {
		$since_dt = date_create( $since, $tz );
		if ( $since_dt ) {
			$where[] = 'updated_at >= %s';
			$values[] = $since_dt->format( 'Y-m-d H:i:s' );
		}
	}

	$until = $request->get_param( 'until' );
	if ( $until ) {
		$until_dt = date_create( $until, $tz );
		if ( $until_dt ) {
			$where[] = 'updated_at <= %s';
			$values[] = $until_dt->format( 'Y-m-d H:i:s' );
		}
	}

	$type = $request->get_param( 'type' );
	if ( $type && in_array( $type, array( 'plugin', 'theme', 'core' ), true ) ) {
		$where[] = 'update_type = %s';
		$values[] = $type;
	}

	$limit = (int) $request->get_param( 'limit' );
	$limit = max( 1, min( 1000, $limit ) );

	$sql = "SELECT id, update_type, name, slug, old_version, new_version, status, updated_at FROM $table WHERE " . implode( ' AND ', $where ) . " ORDER BY updated_at DESC LIMIT %d";
	$values[] = $limit;
	$sql = $wpdb->prepare( $sql, $values );

	$rows = $wpdb->get_results( $sql, ARRAY_A );
	$updates = array();
	foreach ( (array) $rows as $row ) {
		$updates[] = array(
			'id'         => (string) $row['id'],
			'update_type' => $row['update_type'],
			'name'       => $row['name'],
			'slug'       => $row['slug'],
			'old_version' => $row['old_version'],
			'new_version' => $row['new_version'],
			'status'     => $row['status'],
			'updated_at' => $row['updated_at'],
		);
	}

	return new WP_REST_Response(
		array(
			'site'   => home_url(),
			'name'   => get_bloginfo( 'name' ),
			'count'  => count( $updates ),
			'updates' => $updates,
		),
		200
	);
}

/**
 * REST callback: GET /otm-updates/v1/info
 *
 * @param WP_REST_Request $request Request object
 * @return WP_REST_Response
 */
function otm_ul_rest_info( $request ) {
	return new WP_REST_Response(
		array(
			'site'          => home_url(),
			'name'          => get_bloginfo( 'name' ),
			'wp_version'    => get_bloginfo( 'version' ),
			'php_version'   => PHP_VERSION,
			'plugin_version' => OTM_UL_VERSION,
			'status'        => 'connected',
		),
		200
	);
}

/**
 * Add admin menu under Tools.
 */
function otm_ul_admin_menu() {
	add_management_page(
		__( 'Care Plan Assistant', 'otm-update-logger' ),
		__( 'Care Plan Assistant', 'otm-update-logger' ),
		'manage_options',
		'otm-update-logger',
		'otm_ul_admin_page'
	);
}
add_action( 'admin_menu', 'otm_ul_admin_menu' );

/**
 * Handle admin actions: regenerate key, clear log.
 */
function otm_ul_admin_actions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( isset( $_POST['otm_ul_regenerate_key'] ) && check_admin_referer( 'otm_ul_regenerate_key' ) ) {
		update_option( OTM_UL_OPTION_KEY, wp_generate_password( 40, false ) );
		set_transient( 'otm_ul_key_regenerated', 1, 30 );
		wp_safe_redirect( admin_url( 'tools.php?page=otm-update-logger' ) );
		exit;
	}

	if ( isset( $_POST['otm_ul_clear_log'] ) && check_admin_referer( 'otm_ul_clear_log' ) ) {
		global $wpdb;
		$table = otm_ul_get_table_name();
		$wpdb->query( "TRUNCATE TABLE $table" );
		set_transient( 'otm_ul_log_cleared', 1, 30 );
		wp_safe_redirect( admin_url( 'tools.php?page=otm-update-logger' ) );
		exit;
	}
}
add_action( 'admin_init', 'otm_ul_admin_actions' );

/**
 * Render admin settings page.
 */
function otm_ul_admin_page() {
	global $wpdb;
	$table = otm_ul_get_table_name();

	if ( get_transient( 'otm_ul_key_regenerated' ) ) {
		delete_transient( 'otm_ul_key_regenerated' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'API key regenerated.', 'otm-update-logger' ) . '</p></div>';
	}
	if ( get_transient( 'otm_ul_log_cleared' ) ) {
		delete_transient( 'otm_ul_log_cleared' );
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Update log cleared.', 'otm-update-logger' ) . '</p></div>';
	}

	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
	$recent = $wpdb->get_results( "SELECT id, update_type, name, slug, old_version, new_version, status, updated_at FROM $table ORDER BY updated_at DESC LIMIT 25", ARRAY_A );

	$api_key = get_option( OTM_UL_OPTION_KEY, '' );
	$log_url = rest_url( 'otm-updates/v1/log' );
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'OTM Care Plan Assistant', 'otm-update-logger' ); ?></h1>

		<div class="card" style="max-width: 800px; padding: 20px; margin: 20px 0;">
			<h2><?php esc_html_e( 'API Endpoint', 'otm-update-logger' ); ?></h2>
			<p><code><?php echo esc_url( $log_url ); ?></code></p>

			<h2><?php esc_html_e( 'API Key', 'otm-update-logger' ); ?></h2>
			<p>
				<input type="text" readonly value="<?php echo esc_attr( $api_key ); ?>" onclick="this.select();" style="width: 100%; max-width: 400px; font-family: monospace;" />
			</p>
			<form method="post" onsubmit="return confirm('<?php echo esc_js( __( 'Regenerate API key? Any existing integrations will need the new key.', 'otm-update-logger' ) ); ?>');">
				<?php wp_nonce_field( 'otm_ul_regenerate_key' ); ?>
				<button type="submit" name="otm_ul_regenerate_key" class="button"><?php esc_html_e( 'Regenerate Key', 'otm-update-logger' ); ?></button>
			</form>

			<h2><?php esc_html_e( 'Query Parameters', 'otm-update-logger' ); ?></h2>
			<table class="widefat striped" style="max-width: 600px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Parameter', 'otm-update-logger' ); ?></th>
						<th><?php esc_html_e( 'Description', 'otm-update-logger' ); ?></th>
						<th><?php esc_html_e( 'Example', 'otm-update-logger' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr><td><code>since</code></td><td><?php esc_html_e( 'Records from this date forward', 'otm-update-logger' ); ?></td><td><code>2026-02-01</code></td></tr>
					<tr><td><code>until</code></td><td><?php esc_html_e( 'Records up to this date', 'otm-update-logger' ); ?></td><td><code>2026-02-28</code></td></tr>
					<tr><td><code>type</code></td><td><?php esc_html_e( 'Filter by type', 'otm-update-logger' ); ?></td><td><code>plugin</code>, <code>theme</code>, <code>core</code></td></tr>
					<tr><td><code>limit</code></td><td><?php esc_html_e( 'Max records (default 200, max 1000)', 'otm-update-logger' ); ?></td><td><code>100</code></td></tr>
				</tbody>
			</table>
			<p><?php esc_html_e( 'Authenticate via Authorization: Bearer {key} header or api_key query parameter.', 'otm-update-logger' ); ?></p>
		</div>

		<div class="card" style="max-width: 800px; padding: 20px; margin: 20px 0;">
			<h2><?php printf( esc_html__( 'Recent Updates (%d total)', 'otm-update-logger' ), $total ); ?></h2>
			<?php if ( empty( $recent ) ) : ?>
				<p><?php esc_html_e( 'No updates logged yet.', 'otm-update-logger' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'otm-update-logger' ); ?></th>
							<th><?php esc_html_e( 'Type', 'otm-update-logger' ); ?></th>
							<th><?php esc_html_e( 'Name', 'otm-update-logger' ); ?></th>
							<th><?php esc_html_e( 'Version', 'otm-update-logger' ); ?></th>
							<th><?php esc_html_e( 'Status', 'otm-update-logger' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent as $row ) : ?>
							<?php
							$version_display = ! empty( $row['old_version'] )
								? esc_html( $row['old_version'] ) . ' → ' . esc_html( $row['new_version'] )
								: esc_html( $row['new_version'] );
							?>
							<tr>
								<td><?php echo esc_html( $row['updated_at'] ); ?></td>
								<td><?php echo esc_html( $row['update_type'] ); ?></td>
								<td><?php echo esc_html( $row['name'] ); ?></td>
								<td><?php echo $version_display; ?></td>
								<td><?php echo esc_html( $row['status'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<form method="post" style="margin-top: 20px;" onsubmit="return confirm('<?php echo esc_js( __( 'Clear all update log entries? This cannot be undone.', 'otm-update-logger' ) ); ?>');">
				<?php wp_nonce_field( 'otm_ul_clear_log' ); ?>
				<button type="submit" name="otm_ul_clear_log" class="button button-secondary"><?php esc_html_e( 'Clear Log', 'otm-update-logger' ); ?></button>
			</form>
		</div>
	</div>
	<?php
}

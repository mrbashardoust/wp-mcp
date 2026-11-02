<?php
/**
 * Site-level tools: site info, options, raw database, files and PHP.
 */

defined( 'ABSPATH' ) || exit;

wp_mcp_tool(
	'site-info',
	array(
		'label'            => 'Site info',
		'description'      => 'WordPress/PHP versions, URLs, active theme, plugins, post types, taxonomies and REST namespaces. Call this first to orient yourself.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => new stdClass(),
		),
		'execute_callback' => function () {
			if ( ! function_exists( 'get_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			$active  = (array) get_option( 'active_plugins', array() );
			$plugins = array();
			foreach ( get_plugins() as $file => $data ) {
				$plugins[] = array(
					'file'    => $file,
					'name'    => $data['Name'],
					'version' => $data['Version'],
					'active'  => in_array( $file, $active, true ),
				);
			}
			$theme = wp_get_theme();

			return array(
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url(),
				'admin_url'   => admin_url(),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'environment' => wp_get_environment_type(),
				'multisite'   => is_multisite(),
				'locale'      => get_locale(),
				'theme'       => array(
					'name'       => $theme->get( 'Name' ),
					'stylesheet' => $theme->get_stylesheet(),
					'version'    => $theme->get( 'Version' ),
					'block'      => $theme->is_block_theme(),
				),
				'plugins'     => $plugins,
				'post_types'  => array_values( get_post_types( array( 'show_ui' => true ) ) ),
				'taxonomies'  => array_values( get_taxonomies( array( 'show_ui' => true ) ) ),
				'rest_namespaces' => rest_get_server()->get_namespaces(),
			);
		},
	)
);

$wp_mcp_any = array( 'type' => array( 'string', 'number', 'integer', 'boolean', 'array', 'object', 'null' ) );

wp_mcp_tool(
	'option-get',
	array(
		'label'            => 'Get option',
		'description'      => 'Read a wp_options value by name, or find option names with a search pattern (SQL LIKE, e.g. "woocommerce_%").',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'name'   => array( 'type' => 'string' ),
				'search' => array( 'type' => 'string' ),
			),
		),
		'execute_callback' => function ( $input = array() ) {
			global $wpdb;
			if ( isset( $input['search'] ) ) {
				return array(
					'names' => $wpdb->get_col(
						$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name LIMIT 500", $input['search'] )
					),
				);
			}
			if ( ! isset( $input['name'] ) ) {
				return new WP_Error( 'missing_name', 'Pass name or search.' );
			}
			$missing = new stdClass();
			$value   = get_option( $input['name'], $missing );
			return $value === $missing
				? new WP_Error( 'not_found', 'Option does not exist.' )
				: array( 'name' => $input['name'], 'value' => $value );
		},
	)
);

wp_mcp_tool(
	'option-update',
	array(
		'label'            => 'Update option',
		'description'      => 'Create or update a wp_options value. value=null deletes the option.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'name'  => array( 'type' => 'string' ),
				'value' => $wp_mcp_any,
			),
			'required'   => array( 'name', 'value' ),
		),
		'execute_callback' => function ( $input ) {
			if ( null === $input['value'] ) {
				return array( 'deleted' => delete_option( $input['name'] ) );
			}
			update_option( $input['name'], $input['value'] );
			return array( 'name' => $input['name'], 'value' => get_option( $input['name'] ) );
		},
	)
);

/** Replaces {prefix} with the site's table prefix. */
function wp_mcp_sql( string $sql ): string {
	global $wpdb;
	return str_replace( '{prefix}', $wpdb->prefix, $sql );
}

wp_mcp_tool(
	'db-query',
	array(
		'label'            => 'Database query (read)',
		'description'      => 'Run a read-only SQL statement (SELECT, SHOW, DESCRIBE, EXPLAIN, WITH). Write {prefix} for the table prefix, e.g. SELECT * FROM {prefix}posts LIMIT 10. Returns up to 1000 rows.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'sql' => array( 'type' => 'string' ),
			),
			'required'   => array( 'sql' ),
		),
		'execute_callback' => function ( $input ) {
			global $wpdb;
			$sql = wp_mcp_sql( $input['sql'] );
			// Allowlist the statement type: DDL would escape the read-only transaction via implicit commit.
			if ( ! preg_match( '/^\s*(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|WITH)\b/i', $sql ) || preg_match( '/\bINTO\s+(OUT|DUMP)FILE\b/i', $sql ) ) {
				return new WP_Error( 'not_read_only', 'Only SELECT, SHOW, DESCRIBE, EXPLAIN and WITH are allowed. Use db-execute for writes.' );
			}
			// wpdb echoes errors when WP_DEBUG is on, which corrupts the MCP response.
			$suppress = $wpdb->suppress_errors( true );
			$wpdb->query( 'START TRANSACTION READ ONLY' );
			// shortcut: the whole result is loaded before the 1000-row cap; add LIMIT for huge tables.
			$rows  = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$error = $wpdb->last_error;
			$wpdb->query( 'ROLLBACK' );
			$wpdb->suppress_errors( $suppress );
			if ( $error ) {
				return new WP_Error( 'db_error', $error );
			}
			return array(
				'count'     => count( $rows ),
				'truncated' => count( $rows ) > 1000,
				'rows'      => array_slice( $rows, 0, 1000 ),
			);
		},
	)
);

wp_mcp_tool(
	'db-execute',
	array(
		'label'            => 'Database execute (write)',
		'description'      => 'Run any single SQL statement (INSERT, UPDATE, DELETE, ALTER, ...). {prefix} = table prefix. Bypasses WordPress hooks; prefer the other tools when they can do the job.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'sql' => array( 'type' => 'string' ),
			),
			'required'   => array( 'sql' ),
		),
		'execute_callback' => function ( $input ) {
			global $wpdb;
			$suppress = $wpdb->suppress_errors( true );
			$result   = $wpdb->query( wp_mcp_sql( $input['sql'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->suppress_errors( $suppress );
			if ( false === $result ) {
				return new WP_Error( 'db_error', $wpdb->last_error );
			}
			wp_cache_flush();
			return array(
				'rows_affected' => $wpdb->rows_affected,
				'insert_id'     => $wpdb->insert_id,
			);
		},
	)
);

/**
 * Resolves a path relative to the WordPress root and refuses anything outside it.
 * For paths that don't exist yet, the nearest existing parent is checked.
 */
function wp_mcp_path( string $path ) {
	$root = realpath( ABSPATH );
	$full = $root . '/' . ltrim( str_replace( '\\', '/', $path ), '/' );

	$existing = $full;
	while ( ! file_exists( $existing ) && dirname( $existing ) !== $existing ) {
		$existing = dirname( $existing );
	}
	$real = realpath( $existing );
	$rest = substr( $full, strlen( $existing ) );

	if ( false === $real || false !== strpos( $rest, '..' ) || ( $real !== $root && 0 !== strpos( $real, $root . '/' ) ) ) {
		return new WP_Error( 'outside_root', 'Path must be inside the WordPress root.' );
	}
	return $real . $rest;
}

$wp_mcp_path_schema = array(
	'type'        => 'string',
	'description' => 'Path relative to the WordPress root, e.g. wp-content/themes/mytheme/style.css',
);

wp_mcp_tool(
	'file-list',
	array(
		'label'            => 'List files',
		'description'      => 'List a directory inside the WordPress root.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'path' => $wp_mcp_path_schema,
			),
		),
		'execute_callback' => function ( $input = array() ) {
			$dir = wp_mcp_path( $input['path'] ?? '' );
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			if ( ! is_dir( $dir ) ) {
				return new WP_Error( 'not_dir', 'Not a directory.' );
			}
			$items = array();
			foreach ( new DirectoryIterator( $dir ) as $item ) {
				if ( $item->isDot() ) {
					continue;
				}
				$items[] = array(
					'name'     => $item->getFilename(),
					'type'     => $item->isDir() ? 'dir' : 'file',
					'size'     => $item->isFile() ? $item->getSize() : null,
					'modified' => gmdate( 'c', $item->getMTime() ),
				);
			}
			usort( $items, fn( $a, $b ) => strcmp( $a['type'] . $a['name'], $b['type'] . $b['name'] ) );
			return array( 'items' => $items );
		},
	)
);

wp_mcp_tool(
	'file-read',
	array(
		'label'            => 'Read file',
		'description'      => 'Read a file inside the WordPress root (max 2 MB). Non-UTF-8 files are returned base64-encoded.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'path' => $wp_mcp_path_schema,
			),
			'required'   => array( 'path' ),
		),
		'execute_callback' => function ( $input ) {
			$file = wp_mcp_path( $input['path'] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( ! is_file( $file ) ) {
				return new WP_Error( 'not_found', 'File not found.' );
			}
			if ( filesize( $file ) > 2 * MB_IN_BYTES ) {
				return new WP_Error( 'too_large', 'File is larger than 2 MB.' );
			}
			$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$utf8    = wp_check_invalid_utf8( $content ) === $content;
			return array(
				'encoding' => $utf8 ? 'utf-8' : 'base64',
				'content'  => $utf8 ? $content : base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			);
		},
	)
);

wp_mcp_tool(
	'file-write',
	array(
		'label'            => 'Write file',
		'description'      => 'Create or overwrite a file inside the WordPress root (missing folders are created). A PHP syntax error in a theme or plugin file can take the site down.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'path'     => $wp_mcp_path_schema,
				'content'  => array( 'type' => 'string' ),
				'encoding' => array( 'type' => 'string', 'enum' => array( 'utf-8', 'base64' ), 'description' => 'Default utf-8.' ),
			),
			'required'   => array( 'path', 'content' ),
		),
		'execute_callback' => function ( $input ) {
			$file = wp_mcp_path( $input['path'] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			$content = 'base64' === ( $input['encoding'] ?? 'utf-8' ) ? base64_decode( $input['content'], true ) : $input['content']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
			if ( false === $content ) {
				return new WP_Error( 'bad_base64', 'Content is not valid base64.' );
			}
			if ( ! wp_mkdir_p( dirname( $file ) ) || false === file_put_contents( $file, $content ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'write_failed', 'Could not write file (check permissions).' );
			}
			return array( 'path' => $input['path'], 'bytes' => strlen( $content ) );
		},
	)
);

wp_mcp_tool(
	'file-delete',
	array(
		'label'            => 'Delete file',
		'description'      => 'Permanently delete a file (not a directory) inside the WordPress root.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'path' => $wp_mcp_path_schema,
			),
			'required'   => array( 'path' ),
		),
		'execute_callback' => function ( $input ) {
			$file = wp_mcp_path( $input['path'] );
			if ( is_wp_error( $file ) ) {
				return $file;
			}
			if ( ! is_file( $file ) || ! unlink( $file ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				return new WP_Error( 'delete_failed', 'File not found or could not be deleted.' );
			}
			return array( 'deleted' => $input['path'] );
		},
	)
);

wp_mcp_tool(
	'run-php',
	array(
		'label'            => 'Run PHP',
		'description'      => 'Last resort: run PHP code inside WordPress (no opening <?php tag). Returns echoed output and the return value. Use only when no other tool can do the job.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'code' => array( 'type' => 'string', 'description' => 'e.g. return wp_get_theme()->get( "Version" );' ),
			),
			'required'   => array( 'code' ),
		),
		'execute_callback' => function ( $input ) {
			ob_start();
			try {
				$result = eval( $input['code'] ); // phpcs:ignore Squiz.PHP.Eval -- the point of this tool; admin-only and destructive-flagged.
			} catch ( Throwable $e ) {
				ob_end_clean();
				return new WP_Error( 'php_error', get_class( $e ) . ': ' . $e->getMessage() . ' (line ' . $e->getLine() . ')' );
			}
			return array(
				'output' => ob_get_clean(),
				'return' => $result,
			);
		},
	)
);

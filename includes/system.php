<?php
/**
 * Site-level tools: site info (options, db, files, php come in Phase 3).
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

<?php
/**
 * Plugin Name:       WP MCP
 * Description:       Full-site MCP tools for AI agents, served through the MCP Adapter.
 * Version:           0.1.0
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Requires Plugins:  mcp-adapter
 * Author:            Mohammad Bashardoust
 * License:           GPL-2.0-or-later
 */

defined( 'ABSPATH' ) || exit;

const WP_MCP_CATEGORY = 'wp-mcp';

/**
 * Registers an admin-only ability exposed as an MCP tool on our server.
 * $args are wp_register_ability() args; 'readonly' => false marks the tool destructive.
 */
function wp_mcp_tool( string $name, array $args ): void {
	$readonly = $args['readonly'] ?? true;
	unset( $args['readonly'] );

	wp_register_ability(
		WP_MCP_CATEGORY . '/' . $name,
		$args + array(
			'category'            => WP_MCP_CATEGORY,
			'permission_callback' => 'wp_mcp_is_admin',
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array(
					'readonly'    => $readonly,
					'destructive' => ! $readonly,
				),
			),
		)
	);
}

function wp_mcp_is_admin(): bool {
	return current_user_can( 'manage_options' );
}

add_action(
	'wp_abilities_api_categories_init',
	function () {
		wp_register_ability_category(
			WP_MCP_CATEGORY,
			array(
				'label'       => 'WP MCP',
				'description' => 'Full-site management tools for AI agents.',
			)
		);
	}
);

add_action(
	'wp_abilities_api_init',
	function () {
		require __DIR__ . '/includes/system.php';
		require __DIR__ . '/includes/content.php';
		require __DIR__ . '/includes/rest.php';
	}
);

add_action(
	'mcp_adapter_init',
	function ( $adapter ) {
		$tools = array_keys(
			array_filter(
				wp_get_abilities(),
				fn( $ability ) => WP_MCP_CATEGORY === $ability->get_category()
			)
		);

		$adapter->create_server(
			'wp-mcp',
			'wp-mcp',
			'mcp',
			'WP MCP',
			'Full access to this WordPress site.',
			'0.1.0',
			array( \WP\MCP\Transport\HttpTransport::class ),
			\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
			null,
			$tools,
			array(),
			array(),
			'wp_mcp_is_admin'
		);
	}
);

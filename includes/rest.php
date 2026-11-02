<?php
/**
 * Generic REST tools: run any internal REST route as the current admin.
 * Covers users, comments, menus, settings, plugins, templates, WooCommerce (/wc/v3) and other plugins.
 */

defined( 'ABSPATH' ) || exit;

function wp_mcp_rest( string $method, array $input ): array {
	$request = new WP_REST_Request( $method, '/' . ltrim( $input['route'], '/' ) );
	$request->set_query_params( $input['query'] ?? array() );
	if ( isset( $input['body'] ) ) {
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $input['body'] ) );
	}

	$response = rest_do_request( $request );
	$server   = rest_get_server();
	$headers  = $response->get_headers();

	return array(
		'status' => $response->get_status(),
		'total'  => isset( $headers['X-WP-Total'] ) ? (int) $headers['X-WP-Total'] : null,
		'data'   => $server->response_to_data( $response, false ),
	);
}

$wp_mcp_rest_schema = array(
	'route' => array( 'type' => 'string', 'description' => 'Route without /wp-json and without query string, e.g. /wp/v2/users or /wc/v3/orders. Use site-info rest_namespaces to discover; GET /<namespace> lists its routes.' ),
	'query' => array(
		'type'                 => 'object',
		'description'          => 'Query parameters, e.g. {"per_page": 50, "context": "edit"}.',
		'additionalProperties' => true,
	),
);

wp_mcp_tool(
	'rest-get',
	array(
		'label'            => 'REST GET',
		'description'      => 'Read from any WordPress REST route as admin (users, comments, menus, settings, plugins, themes, templates, WooCommerce, other plugins).',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => $wp_mcp_rest_schema,
			'required'   => array( 'route' ),
		),
		'execute_callback' => fn( $input ) => wp_mcp_rest( 'GET', $input ),
	)
);

wp_mcp_tool(
	'rest-write',
	array(
		'label'            => 'REST write',
		'description'      => 'POST/PUT/PATCH/DELETE on any WordPress REST route as admin, e.g. create users, moderate comments, install/activate plugins (POST /wp/v2/plugins {"slug":"...","status":"active"}), update settings, edit WooCommerce products and orders.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => $wp_mcp_rest_schema + array(
				'method' => array( 'type' => 'string', 'enum' => array( 'POST', 'PUT', 'PATCH', 'DELETE' ) ),
				'body'   => array(
					'type'                 => 'object',
					'description'          => 'JSON body.',
					'additionalProperties' => true,
				),
			),
			'required'   => array( 'method', 'route' ),
		),
		'execute_callback' => fn( $input ) => wp_mcp_rest( $input['method'], $input ),
	)
);

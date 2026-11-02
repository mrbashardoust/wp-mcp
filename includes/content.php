<?php
/**
 * Content tools: posts of any type (incl. pages, CPTs, their meta and terms) and media.
 * Comments, menus, users, WooCommerce etc. go through rest-get / rest-write.
 */

defined( 'ABSPATH' ) || exit;

wp_mcp_tool(
	'posts-list',
	array(
		'label'            => 'List posts',
		'description'      => 'List posts of any post type (post, page, product, CPTs). Returns summaries; use post-get for content and meta.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'description' => 'Post type slug, or "any". Default "any".' ),
				'status'    => array( 'type' => 'string', 'description' => 'publish, draft, pending, private, future, trash, or "any". Default "any".' ),
				'search'    => array( 'type' => 'string' ),
				'parent'    => array( 'type' => 'integer' ),
				'orderby'   => array( 'type' => 'string', 'description' => 'date, modified, title, menu_order, ID. Default date.' ),
				'order'     => array( 'type' => 'string', 'enum' => array( 'ASC', 'DESC' ) ),
				'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
				'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
			),
		),
		'execute_callback' => function ( $input = array() ) {
			$args = array(
				'post_type'           => $input['post_type'] ?? 'any',
				'post_status'         => $input['status'] ?? 'any',
				'orderby'             => $input['orderby'] ?? 'date',
				'order'               => $input['order'] ?? 'DESC',
				'posts_per_page'      => $input['per_page'] ?? 20,
				'paged'               => $input['page'] ?? 1,
				'ignore_sticky_posts' => true,
			);
			if ( isset( $input['search'] ) ) {
				$args['s'] = $input['search'];
			}
			if ( isset( $input['parent'] ) ) {
				$args['post_parent'] = $input['parent'];
			}
			// 'any' skips trash; include it when explicitly asked.
			if ( 'trash' === $args['post_status'] ) {
				$args['post_status'] = array( 'trash' );
			}
			$query = new WP_Query( $args );

			return array(
				'total' => (int) $query->found_posts,
				'pages' => (int) $query->max_num_pages,
				'items' => array_map(
					fn( $post ) => array(
						'id'       => $post->ID,
						'type'     => $post->post_type,
						'status'   => $post->post_status,
						'title'    => $post->post_title,
						'slug'     => $post->post_name,
						'parent'   => $post->post_parent,
						'author'   => (int) $post->post_author,
						'date'     => $post->post_date,
						'modified' => $post->post_modified,
						'link'     => get_permalink( $post ),
					),
					$query->posts
				),
			);
		},
	)
);

wp_mcp_tool(
	'post-get',
	array(
		'label'            => 'Get post',
		'description'      => 'Get one post of any type with raw content, all meta (incl. SEO plugin fields) and terms.',
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'id' ),
		),
		'execute_callback' => function ( $input ) {
			$post = get_post( $input['id'] );
			if ( ! $post ) {
				return new WP_Error( 'not_found', 'Post not found.' );
			}
			$meta = array();
			foreach ( get_post_meta( $post->ID ) as $key => $values ) {
				$values       = array_map( 'maybe_unserialize', $values );
				$meta[ $key ] = 1 === count( $values ) ? $values[0] : $values;
			}
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$terms[ $taxonomy ] = wp_get_post_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			}

			return array(
				'post'           => $post->to_array(),
				'link'           => get_permalink( $post ),
				'featured_media' => (int) get_post_thumbnail_id( $post ),
				'meta'           => $meta,
				'terms'          => $terms,
			);
		},
	)
);

wp_mcp_tool(
	'post-save',
	array(
		'label'            => 'Create or update post',
		'description'      => 'Create (no id) or update (with id) a post of any type, including meta and terms. Only given fields change. For WooCommerce products prefer rest-write on /wc/v3/products so Woo keeps its lookup tables in sync.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'id'             => array( 'type' => 'integer', 'description' => 'Omit to create.' ),
				'post_type'      => array( 'type' => 'string', 'description' => 'Default "post" on create.' ),
				'title'          => array( 'type' => 'string' ),
				'content'        => array( 'type' => 'string', 'description' => 'Raw content (block markup or HTML).' ),
				'excerpt'        => array( 'type' => 'string' ),
				'status'         => array( 'type' => 'string', 'description' => 'draft (default on create), publish, pending, private, future.' ),
				'slug'           => array( 'type' => 'string' ),
				'parent'         => array( 'type' => 'integer' ),
				'menu_order'     => array( 'type' => 'integer' ),
				'author'         => array( 'type' => 'integer' ),
				'date'           => array( 'type' => 'string', 'description' => 'Y-m-d H:i:s, site timezone.' ),
				'featured_media' => array( 'type' => 'integer', 'description' => 'Attachment ID; 0 removes.' ),
				'meta'           => array(
					'type'                 => 'object',
					'description'          => 'Meta key => value. null deletes the key.',
					'additionalProperties' => true,
				),
				'terms'          => array(
					'type'                 => 'object',
					'description'          => 'Taxonomy => list of term names or IDs. Replaces existing terms of that taxonomy.',
					'additionalProperties' => array( 'type' => 'array' ),
				),
			),
		),
		'execute_callback' => function ( $input ) {
			$map  = array(
				'id'         => 'ID',
				'post_type'  => 'post_type',
				'title'      => 'post_title',
				'content'    => 'post_content',
				'excerpt'    => 'post_excerpt',
				'status'     => 'post_status',
				'slug'       => 'post_name',
				'parent'     => 'post_parent',
				'menu_order' => 'menu_order',
				'author'     => 'post_author',
				'date'       => 'post_date',
			);
			$data = array();
			foreach ( $map as $from => $to ) {
				if ( isset( $input[ $from ] ) ) {
					$data[ $to ] = $input[ $from ];
				}
			}

			if ( isset( $data['ID'] ) ) {
				if ( ! get_post( $data['ID'] ) ) {
					return new WP_Error( 'not_found', 'Post not found.' );
				}
				$id = wp_update_post( wp_slash( $data ), true );
			} else {
				$id = wp_insert_post( wp_slash( $data ), true );
			}
			if ( is_wp_error( $id ) ) {
				return $id;
			}

			foreach ( $input['meta'] ?? array() as $key => $value ) {
				null === $value ? delete_post_meta( $id, $key ) : update_post_meta( $id, $key, wp_slash( $value ) );
			}
			foreach ( $input['terms'] ?? array() as $taxonomy => $terms ) {
				$result = wp_set_object_terms( $id, $terms, $taxonomy );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
			if ( isset( $input['featured_media'] ) ) {
				$input['featured_media'] ? set_post_thumbnail( $id, $input['featured_media'] ) : delete_post_thumbnail( $id );
			}

			return array(
				'id'     => $id,
				'status' => get_post_status( $id ),
				'link'   => get_permalink( $id ),
			);
		},
	)
);

wp_mcp_tool(
	'post-delete',
	array(
		'label'            => 'Delete post',
		'description'      => 'Move a post of any type to trash, or delete it permanently with force=true.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'id'    => array( 'type' => 'integer' ),
				'force' => array( 'type' => 'boolean', 'description' => 'Skip trash and delete permanently. Default false.' ),
			),
			'required'   => array( 'id' ),
		),
		'execute_callback' => function ( $input ) {
			$force  = $input['force'] ?? false;
			$result = $force ? wp_delete_post( $input['id'], true ) : wp_trash_post( $input['id'] );
			if ( ! $result ) {
				return new WP_Error( 'delete_failed', 'Post not found or could not be deleted.' );
			}
			return array(
				'id'      => $input['id'],
				'deleted' => $force ? 'permanently' : 'trashed',
			);
		},
	)
);

wp_mcp_tool(
	'media-upload',
	array(
		'label'            => 'Upload media from URL',
		'description'      => 'Download a file from a URL into the media library.',
		'readonly'         => false,
		'input_schema'     => array(
			'type'       => 'object',
			'properties' => array(
				'url'     => array( 'type' => 'string', 'format' => 'uri' ),
				'title'   => array( 'type' => 'string' ),
				'alt'     => array( 'type' => 'string' ),
				'post_id' => array( 'type' => 'integer', 'description' => 'Attach to this post.' ),
			),
			'required'   => array( 'url' ),
		),
		'execute_callback' => function ( $input ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$tmp = download_url( $input['url'] );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$file = array(
				'name'     => wp_basename( (string) wp_parse_url( $input['url'], PHP_URL_PATH ) ),
				'tmp_name' => $tmp,
			);
			$id   = media_handle_sideload( $file, $input['post_id'] ?? 0, $input['title'] ?? null );
			if ( is_wp_error( $id ) ) {
				wp_delete_file( $tmp );
				return $id;
			}
			if ( isset( $input['alt'] ) ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $input['alt'] );
			}

			return array(
				'id'  => $id,
				'url' => wp_get_attachment_url( $id ),
			);
		},
	)
);

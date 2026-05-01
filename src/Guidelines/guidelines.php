<?php
/**
 * Guideline public API helpers.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'wp_guideline_types' ) ) {
	/**
	 * Returns registered guideline types keyed by slug.
	 *
	 * @return array<string, array{title: string}> Slug-keyed guideline type definitions.
	 */
	function wp_guideline_types(): array {
		/**
		 * Filters the guideline types available on this site.
		 *
		 * @param array<string, array{title: string}> $types Slug-keyed guideline type definitions.
		 */
		return apply_filters(
			'wp_guideline_types',
			array(
				'artifact' => array(
					'title' => __( 'Artifact', 'agents-api' ),
				),
				'content'  => array(
					'title' => __( 'Content', 'agents-api' ),
				),
			)
		);
	}
}

if ( ! function_exists( '_wp_guidelines_ensure_default_type_term' ) ) {
	/**
	 * Assigns the artifact type to guideline posts saved without a type.
	 *
	 * @access private
	 *
	 * @param int $post_id Saved post ID.
	 */
	function _wp_guidelines_ensure_default_type_term( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$terms = get_the_terms( $post_id, WP_Guidelines_Substrate::TAXONOMY );
		if ( is_wp_error( $terms ) || ! empty( $terms ) ) {
			return;
		}

		$term = term_exists( 'artifact', WP_Guidelines_Substrate::TAXONOMY );
		if ( ! $term ) {
			$term = wp_insert_term( 'artifact', WP_Guidelines_Substrate::TAXONOMY );
			if ( is_wp_error( $term ) ) {
				return;
			}
		}

		wp_set_object_terms( $post_id, (int) $term['term_id'], WP_Guidelines_Substrate::TAXONOMY );
	}
}

if ( ! function_exists( '_wp_guidelines_maybe_map_term_label' ) ) {
	/**
	 * Maps lazily-created guideline type slugs to human-readable labels.
	 *
	 * @access private
	 *
	 * @param array<string, mixed> $data     Term data to be inserted.
	 * @param string               $taxonomy Taxonomy slug.
	 * @return array<string, mixed> Possibly modified term data.
	 */
	function _wp_guidelines_maybe_map_term_label( array $data, string $taxonomy ): array {
		if ( WP_Guidelines_Substrate::TAXONOMY !== $taxonomy ) {
			return $data;
		}

		if ( ! isset( $data['name'], $data['slug'] ) || $data['name'] !== $data['slug'] ) {
			return $data;
		}

		$types = wp_guideline_types();
		if ( isset( $types[ $data['slug'] ] ) ) {
			$data['name'] = $types[ $data['slug'] ]['title'];
		}

		return $data;
	}
}

<?php
/**
 * Effective agent resolver.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the per-invocation effective agent for scoped operations.
 *
 * This deliberately models request/job/session identity, not global mutable
 * "active agent" state. Consumers should pass explicit operation input when
 * available, then principal or persisted context from the current invocation.
 */
final class WP_Agent_Effective_Agent_Resolver {

	/**
	 * Resolve the effective agent slug from the best available context.
	 *
	 * Resolution order:
	 * 1) Explicit operation input: `agent_slug` / `effective_agent_id`.
	 * 2) Execution principal: `principal->effective_agent_id`.
	 * 3) Persisted invocation context: `persisted_agent_slug` / `persisted_effective_agent_id`.
	 * 4) Owner fallback only when exactly one registered/candidate agent matches.
	 *
	 * @param array<string,mixed> $context Resolution context.
	 * @return string Effective agent slug, or an empty string when no candidate exists.
	 * @throws \InvalidArgumentException When owner fallback is ambiguous.
	 */
	public static function resolve( array $context = array() ): string {
		$explicit = self::first_slug(
			array(
				$context['agent_slug'] ?? null,
				$context['effective_agent_id'] ?? null,
			)
		);
		if ( '' !== $explicit ) {
			return $explicit;
		}

		$principal = $context['principal'] ?? null;
		if ( ! $principal instanceof WP_Agent_Execution_Principal && ! empty( $context['resolve_principal'] ) ) {
			$principal_context = isset( $context['principal_context'] ) && is_array( $context['principal_context'] ) ? $context['principal_context'] : array();
			$principal         = WP_Agent_Execution_Principal::resolve( $principal_context );
		}

		if ( $principal instanceof WP_Agent_Execution_Principal ) {
			$principal_slug = self::normalize_slug( $principal->effective_agent_id );
			if ( '' !== $principal_slug ) {
				return $principal_slug;
			}
		}

		$persisted = self::first_slug(
			array(
				$context['persisted_agent_slug'] ?? null,
				$context['persisted_effective_agent_id'] ?? null,
			)
		);
		if ( '' !== $persisted ) {
			return $persisted;
		}

		$owner_user_id = (int) ( $context['owner_user_id'] ?? 0 );
		if ( $owner_user_id <= 0 ) {
			return '';
		}

		$candidates = isset( $context['owner_agent_slugs'] ) && is_array( $context['owner_agent_slugs'] )
			? self::normalize_slug_list( $context['owner_agent_slugs'] )
			: self::registered_agent_slugs_for_owner( $owner_user_id );

		if ( 0 === count( $candidates ) ) {
			return '';
		}

		if ( 1 === count( $candidates ) ) {
			return $candidates[0];
		}

		throw new \InvalidArgumentException(
			'invalid_effective_agent_resolution: owner_user_id is ambiguous; provide an explicit agent_slug or execution principal. Candidates: ' . implode( ', ', $candidates )
		);
	}

	/**
	 * Return the first non-empty normalized slug.
	 *
	 * @param array<int,mixed> $values Candidate values.
	 * @return string Normalized slug.
	 */
	private static function first_slug( array $values ): string {
		foreach ( $values as $value ) {
			$slug = is_scalar( $value ) ? self::normalize_slug( (string) $value ) : '';
			if ( '' !== $slug ) {
				return $slug;
			}
		}

		return '';
	}

	/**
	 * Normalize an agent slug.
	 *
	 * @param string $slug Raw slug.
	 * @return string Normalized slug.
	 */
	private static function normalize_slug( string $slug ): string {
		if ( function_exists( 'sanitize_title' ) ) {
			return sanitize_title( $slug );
		}

		$slug = strtolower( $slug );
		$slug = preg_replace( '/[^a-z0-9]+/', '-', $slug );

		return trim( (string) $slug, '-' );
	}

	/**
	 * Normalize and dedupe candidate slug list.
	 *
	 * @param array<int,mixed> $slugs Raw slugs.
	 * @return string[] Normalized slugs.
	 */
	private static function normalize_slug_list( array $slugs ): array {
		$normalized = array();
		foreach ( $slugs as $slug ) {
			if ( ! is_scalar( $slug ) ) {
				continue;
			}

			$slug = self::normalize_slug( (string) $slug );
			if ( '' !== $slug ) {
				$normalized[ $slug ] = true;
			}
		}

		return array_keys( $normalized );
	}

	/**
	 * Resolve registered agent slugs whose owner resolver matches the user.
	 *
	 * @param int $owner_user_id Owner WordPress user ID.
	 * @return string[] Matching registered agent slugs.
	 */
	private static function registered_agent_slugs_for_owner( int $owner_user_id ): array {
		if ( ! class_exists( '\WP_Agents_Registry' ) ) {
			return array();
		}

		$registry = \WP_Agents_Registry::get_instance();
		if ( null === $registry ) {
			return array();
		}

		$matches = array();
		foreach ( $registry->get_all_registered() as $agent ) {
			if ( ! $agent instanceof \WP_Agent ) {
				continue;
			}

			$owner_resolver = $agent->get_owner_resolver();
			if ( ! is_callable( $owner_resolver ) ) {
				continue;
			}

			if ( $owner_user_id === (int) call_user_func( $owner_resolver ) ) {
				$matches[] = $agent->get_slug();
			}
		}

		return self::normalize_slug_list( $matches );
	}
}

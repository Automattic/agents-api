<?php
/**
 * WP_Agent_Access_Grant value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Access_Grant' ) ) {
	/**
	 * Role-based access grant between a WordPress user and an agent.
	 */
	final class WP_Agent_Access_Grant {

		public const ROLE_ADMIN    = 'admin';
		public const ROLE_OPERATOR = 'operator';
		public const ROLE_VIEWER   = 'viewer';

		/**
		 * @param string          $agent_id            Registered/effective agent identifier.
		 * @param int             $user_id             WordPress user ID receiving access.
		 * @param string          $role                Access role.
		 * @param string|null     $workspace_id        Optional host workspace/scope identifier.
		 * @param int|null        $grant_id            Optional store-owned grant ID.
		 * @param int|null        $granted_by_user_id  Optional WordPress user ID that created the grant.
		 * @param string|null     $granted_at          Optional UTC datetime string.
		 * @param array<string,mixed> $metadata         Host-owned metadata.
		 */
		public function __construct(
			public readonly string $agent_id,
			public readonly int $user_id,
			public readonly string $role = self::ROLE_VIEWER,
			public readonly ?string $workspace_id = null,
			public readonly ?int $grant_id = null,
			public readonly ?int $granted_by_user_id = null,
			public readonly ?string $granted_at = null,
			public readonly array $metadata = array(),
		) {
			if ( '' === trim( $this->agent_id ) ) {
				throw self::invalid( 'agent_id', 'must be a non-empty string' );
			}

			if ( $this->user_id <= 0 ) {
				throw self::invalid( 'user_id', 'must be a positive integer' );
			}

			if ( null !== $this->grant_id && $this->grant_id <= 0 ) {
				throw self::invalid( 'grant_id', 'must be null or a positive integer' );
			}

			if ( null !== $this->granted_by_user_id && $this->granted_by_user_id <= 0 ) {
				throw self::invalid( 'granted_by_user_id', 'must be null or a positive integer' );
			}

			if ( ! self::is_valid_role( $this->role ) ) {
				throw self::invalid( 'role', 'must be admin, operator, or viewer' );
			}

			if ( false === self::json_encode( $this->metadata ) ) {
				throw self::invalid( 'metadata', 'must be JSON serializable' );
			}
		}

		/**
		 * Return all valid access roles from lowest to highest privilege.
		 *
		 * @return string[]
		 */
		public static function roles(): array {
			return array( self::ROLE_VIEWER, self::ROLE_OPERATOR, self::ROLE_ADMIN );
		}

		/**
		 * Determine whether a role is valid.
		 *
		 * @param string $role Role value.
		 */
		public static function is_valid_role( string $role ): bool {
			return in_array( $role, self::roles(), true );
		}

		/**
		 * Build a grant from a raw array.
		 *
		 * @param array<string,mixed> $grant Raw grant fields.
		 */
		public static function from_array( array $grant ): self {
			return new self(
				isset( $grant['agent_id'] ) ? (string) $grant['agent_id'] : '',
				isset( $grant['user_id'] ) ? (int) $grant['user_id'] : 0,
				isset( $grant['role'] ) ? (string) $grant['role'] : self::ROLE_VIEWER,
				array_key_exists( 'workspace_id', $grant ) && null !== $grant['workspace_id'] ? (string) $grant['workspace_id'] : null,
				isset( $grant['grant_id'] ) ? (int) $grant['grant_id'] : null,
				isset( $grant['granted_by_user_id'] ) ? (int) $grant['granted_by_user_id'] : null,
				array_key_exists( 'granted_at', $grant ) && null !== $grant['granted_at'] ? (string) $grant['granted_at'] : null,
				isset( $grant['metadata'] ) && is_array( $grant['metadata'] ) ? $grant['metadata'] : array()
			);
		}

		/**
		 * Whether this grant's role meets or exceeds the required role.
		 */
		public function role_meets( string $minimum_role ): bool {
			$roles          = self::roles();
			$actual_index   = array_search( $this->role, $roles, true );
			$required_index = array_search( $minimum_role, $roles, true );

			return false !== $actual_index && false !== $required_index && $actual_index >= $required_index;
		}

		/**
		 * Export the grant to a stable JSON-friendly shape.
		 *
		 * @return array<string,mixed>
		 */
		public function to_array(): array {
			return array(
				'grant_id'           => $this->grant_id,
				'agent_id'           => $this->agent_id,
				'user_id'            => $this->user_id,
				'role'               => $this->role,
				'workspace_id'       => $this->workspace_id,
				'granted_by_user_id' => $this->granted_by_user_id,
				'granted_at'         => $this->granted_at,
				'metadata'           => $this->metadata,
			);
		}

		/**
		 * Encode JSON without throwing on older PHP configurations.
		 *
		 * @param mixed $value Value to encode.
		 * @return string|false
		 */
		private static function json_encode( $value ) {
			try {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure value object also runs outside WordPress in smoke tests.
				return json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR );
			} catch ( JsonException $e ) {
				return false;
			}
		}

		/**
		 * Build a machine-readable validation exception.
		 */
		private static function invalid( string $path, string $reason ): InvalidArgumentException {
			return new InvalidArgumentException( 'invalid_wp_agent_access_grant: ' . $path . ' ' . $reason );
		}
	}
}

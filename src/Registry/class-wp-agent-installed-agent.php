<?php
/**
 * WP_Agent_Installed_Agent value object.
 *
 * @package AgentsAPI
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_Agent_Installed_Agent' ) ) {
	/**
	 * Describes durable installed agent state without defining storage.
	 */
	final class WP_Agent_Installed_Agent {

		private string $id;
		private string $agent_slug;
		private ?int $owner_user_id;
		private string $instance_key;
		/** @var array<string,mixed> */
		private array $config;
		/** @var array<string,mixed> */
		private array $meta;
		private string $status;
		private ?string $package_slug;
		private ?string $package_version;
		private ?string $created_at;
		private ?string $updated_at;

		/**
		 * Constructor.
		 *
		 * @param array<string,mixed> $args Installed agent state.
		 */
		public function __construct( array $args ) {
			$this->id              = self::prepare_required_string( $args['id'] ?? '', 'Installed agent id cannot be empty.' );
			$this->agent_slug      = self::prepare_slug( $args['agent_slug'] ?? '', 'Installed agent slug cannot be empty.' );
			$this->owner_user_id   = isset( $args['owner_user_id'] ) && null !== $args['owner_user_id'] ? max( 0, (int) $args['owner_user_id'] ) : null;
			$this->instance_key    = self::prepare_instance_key( $args['instance_key'] ?? 'default' );
			$this->config          = is_array( $args['config'] ?? null ) ? $args['config'] : array();
			$this->meta            = is_array( $args['meta'] ?? null ) ? $args['meta'] : array();
			$this->status          = self::prepare_status( $args['status'] ?? 'installed' );
			$this->package_slug    = isset( $args['package_slug'] ) && '' !== trim( (string) $args['package_slug'] ) ? self::prepare_slug( $args['package_slug'], 'Installed agent package slug cannot be empty.' ) : null;
			$this->package_version = isset( $args['package_version'] ) && '' !== trim( (string) $args['package_version'] ) ? trim( (string) $args['package_version'] ) : null;
			$this->created_at      = isset( $args['created_at'] ) && '' !== trim( (string) $args['created_at'] ) ? trim( (string) $args['created_at'] ) : null;
			$this->updated_at      = isset( $args['updated_at'] ) && '' !== trim( (string) $args['updated_at'] ) ? trim( (string) $args['updated_at'] ) : null;
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_agent_slug(): string {
			return $this->agent_slug;
		}

		public function get_owner_user_id(): ?int {
			return $this->owner_user_id;
		}

		public function get_instance_key(): string {
			return $this->instance_key;
		}

		/** @return array<string,mixed> */
		public function get_config(): array {
			return $this->config;
		}

		/** @return array<string,mixed> */
		public function get_meta(): array {
			return $this->meta;
		}

		public function get_status(): string {
			return $this->status;
		}

		public function get_package_slug(): ?string {
			return $this->package_slug;
		}

		public function get_package_version(): ?string {
			return $this->package_version;
		}

		public function get_created_at(): ?string {
			return $this->created_at;
		}

		public function get_updated_at(): ?string {
			return $this->updated_at;
		}

		public function key(): string {
			$owner = null === $this->owner_user_id ? 'none' : (string) $this->owner_user_id;
			return $this->agent_slug . ':' . $owner . ':' . $this->instance_key;
		}

		/** @return array<string,mixed> */
		public function to_array(): array {
			return array(
				'id'              => $this->id,
				'agent_slug'      => $this->agent_slug,
				'owner_user_id'   => $this->owner_user_id,
				'instance_key'    => $this->instance_key,
				'config'          => $this->config,
				'meta'            => $this->meta,
				'status'          => $this->status,
				'package_slug'    => $this->package_slug,
				'package_version' => $this->package_version,
				'created_at'      => $this->created_at,
				'updated_at'      => $this->updated_at,
			);
		}

		private static function prepare_required_string( $value, string $message ): string {
			$value = trim( (string) $value );
			if ( '' === $value ) {
				throw new InvalidArgumentException( $message );
			}

			return $value;
		}

		private static function prepare_slug( $value, string $message ): string {
			$value = sanitize_title( (string) $value );
			if ( '' === $value ) {
				throw new InvalidArgumentException( $message );
			}

			return $value;
		}

		private static function prepare_instance_key( $value ): string {
			$value = trim( strtolower( str_replace( '\\', '/', (string) $value ) ) );
			$value = preg_replace( '#\s*/\s*#', '/', $value );
			$value = preg_replace( '#/+#', '/', $value );
			return '' === $value ? 'default' : $value;
		}

		private static function prepare_status( $status ): string {
			$status  = sanitize_title( (string) $status );
			$allowed = array( 'installed', 'updated', 'disabled', 'removed', 'projected' );
			if ( ! in_array( $status, $allowed, true ) ) {
				throw new InvalidArgumentException( 'Installed agent status is invalid.' );
			}

			return $status;
		}
	}
}

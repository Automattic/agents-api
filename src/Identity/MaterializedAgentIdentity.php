<?php
/**
 * Materialized agent identity value object.
 *
 * @package AgentsAPI
 */

namespace AgentsAPI\Identity;

defined( 'ABSPATH' ) || exit;

/**
 * Durable agent instance identity.
 */
final class MaterializedAgentIdentity {

	/**
	 * Durable identity ID.
	 *
	 * @var int
	 */
	private int $id;

	/**
	 * Registered agent slug.
	 *
	 * @var string
	 */
	private string $slug;

	/**
	 * Human-readable label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * Owner WordPress user ID.
	 *
	 * @var int
	 */
	private int $owner_user_id;

	/**
	 * Persisted agent configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Created timestamp, when known.
	 *
	 * @var string|null
	 */
	private ?string $created_at;

	/**
	 * Updated timestamp, when known.
	 *
	 * @var string|null
	 */
	private ?string $updated_at;

	/**
	 * Constructor.
	 *
	 * @param int                  $id            Durable identity ID.
	 * @param string               $slug          Registered agent slug.
	 * @param string               $label         Human-readable label.
	 * @param int                  $owner_user_id Owner WordPress user ID.
	 * @param array<string, mixed> $config        Persisted agent configuration.
	 * @param string|null          $created_at    Created timestamp, when known.
	 * @param string|null          $updated_at    Updated timestamp, when known.
	 */
	public function __construct( int $id, string $slug, string $label, int $owner_user_id, array $config = array(), ?string $created_at = null, ?string $updated_at = null ) {
		$slug = sanitize_title( $slug );
		if ( $id <= 0 ) {
			throw new \InvalidArgumentException( 'Materialized agent identity ID must be positive.' );
		}
		if ( '' === $slug ) {
			throw new \InvalidArgumentException( 'Materialized agent identity slug cannot be empty.' );
		}
		if ( $owner_user_id <= 0 ) {
			throw new \InvalidArgumentException( 'Materialized agent identity owner user ID must be positive.' );
		}

		$this->id            = $id;
		$this->slug          = $slug;
		$this->label         = '' !== $label ? $label : $slug;
		$this->owner_user_id = $owner_user_id;
		$this->config        = $config;
		$this->created_at    = $created_at;
		$this->updated_at    = $updated_at;
	}

	/**
	 * Create an identity from a storage row.
	 *
	 * @param array<string, mixed> $row Storage row.
	 * @return self
	 */
	public static function from_array( array $row ): self {
		$config = $row['config'] ?? array();
		if ( is_string( $config ) && '' !== $config ) {
			$decoded = json_decode( $config, true );
			$config  = is_array( $decoded ) ? $decoded : array();
		}

		return new self(
			(int) ( $row['id'] ?? 0 ),
			(string) ( $row['slug'] ?? '' ),
			(string) ( $row['label'] ?? '' ),
			(int) ( $row['owner_user_id'] ?? 0 ),
			is_array( $config ) ? $config : array(),
			isset( $row['created_at'] ) ? (string) $row['created_at'] : null,
			isset( $row['updated_at'] ) ? (string) $row['updated_at'] : null
		);
	}

	/**
	 * Get the durable identity ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Get the registered agent slug.
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return $this->slug;
	}

	/**
	 * Get the human-readable label.
	 *
	 * @return string
	 */
	public function get_label(): string {
		return $this->label;
	}

	/**
	 * Get the owner WordPress user ID.
	 *
	 * @return int
	 */
	public function get_owner_user_id(): int {
		return $this->owner_user_id;
	}

	/**
	 * Get persisted agent configuration.
	 *
	 * @return array<string, mixed>
	 */
	public function get_config(): array {
		return $this->config;
	}

	/**
	 * Get created timestamp, when known.
	 *
	 * @return string|null
	 */
	public function get_created_at(): ?string {
		return $this->created_at;
	}

	/**
	 * Get updated timestamp, when known.
	 *
	 * @return string|null
	 */
	public function get_updated_at(): ?string {
		return $this->updated_at;
	}

	/**
	 * Convert the identity to generic array form.
	 *
	 * @return array{id:int,slug:string,label:string,owner_user_id:int,config:array<string,mixed>,created_at:string|null,updated_at:string|null}
	 */
	public function to_array(): array {
		return array(
			'id'            => $this->id,
			'slug'          => $this->slug,
			'label'         => $this->label,
			'owner_user_id' => $this->owner_user_id,
			'config'        => $this->config,
			'created_at'    => $this->created_at,
			'updated_at'    => $this->updated_at,
		);
	}
}

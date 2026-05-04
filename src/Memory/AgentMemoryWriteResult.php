<?php
/**
 * Agent Memory Write Result
 *
 * Store-neutral value object returned by AgentMemoryStoreInterface::write()
 * and ::delete().
 *
 * @package AgentsAPI
 * @since   next
 */

namespace AgentsAPI\Core\FilesRepository;

defined( 'ABSPATH' ) || exit;

final class AgentMemoryWriteResult {

	/**
	 * @param bool                     $success                     Whether the operation succeeded.
	 * @param string                   $hash                        Hash (sha1) of the post-write content.
	 * @param int                      $bytes                       Post-write content length in bytes.
	 * @param string|null              $error                       Machine-readable error code on failure.
	 * @param AgentMemoryMetadata|null $metadata                    Metadata persisted with the write, or null if unavailable.
	 * @param string[]                 $unsupported_metadata_fields Metadata fields the store could not persist.
	 */
	public function __construct(
		public readonly bool $success,
		public readonly string $hash,
		public readonly int $bytes,
		public readonly ?string $error,
		public readonly ?AgentMemoryMetadata $metadata = null,
		public readonly array $unsupported_metadata_fields = array(),
	) {}

	/**
	 * @param string[] $unsupported_metadata_fields Metadata fields the store could not persist.
	 */
	public static function ok( string $hash, int $bytes, ?AgentMemoryMetadata $metadata = null, array $unsupported_metadata_fields = array() ): self {
		return new self( true, $hash, $bytes, null, $metadata, $unsupported_metadata_fields );
	}

	public static function failure( string $error ): self {
		return new self( false, '', 0, $error );
	}
}

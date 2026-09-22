<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\DTO\FtsMatch;
use LatticeDB\FFI\LatticeLibrary;

/**
 * BM25 full-text search over declared (label, property) indexes.
 *
 * Declare an index once with createIndex(); from then on every write of that
 * property on a node with that label is indexed automatically. Searching a
 * label/property pair without an index throws (ErrorCode::Unsupported).
 */
class FullTextSearch
{
    public function __construct(
        private readonly FFI $ffi,
        private readonly CData $dbHandle,
        private readonly ?CData $txnHandle = null,
    ) {}

    /** Fails with a lock timeout while a write transaction is active. */
    public function createIndex(string $label, string $property): void
    {
        $err = $this->ffi->lattice_node_fts_index_create($this->dbHandle, $label, $property);
        LatticeLibrary::checkError($this->ffi, $err, "Failed to create FTS index on {$label}.{$property}");
    }

    public function dropIndex(string $label, string $property): void
    {
        $err = $this->ffi->lattice_node_fts_index_drop($this->dbHandle, $label, $property);
        LatticeLibrary::checkError($this->ffi, $err, "Failed to drop FTS index on {$label}.{$property}");
    }

    public function indexExists(string $label, string $property): bool
    {
        $exists = $this->ffi->new('bool');
        $err = $this->ffi->lattice_node_fts_index_exists($this->dbHandle, $label, $property, FFI::addr($exists));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to check FTS index');
        return (bool) $exists->cdata;
    }

    /**
     * Inside a transaction the search also sees that transaction's uncommitted writes.
     * @return FtsMatch[]
     */
    public function search(string $label, string $property, string $query, int $limit = 10): array
    {
        $resultPtr = $this->ffi->new('lattice_fts_result*');
        $err = $this->txnHandle !== null
            ? $this->ffi->lattice_fts_search_txn($this->txnHandle, $label, $property, $query, strlen($query), $limit, FFI::addr($resultPtr))
            : $this->ffi->lattice_fts_search($this->dbHandle, $label, $property, $query, strlen($query), $limit, FFI::addr($resultPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Full-text search failed');

        return $this->collectResults($resultPtr);
    }

    /** @return FtsMatch[] */
    public function searchFuzzy(
        string $label,
        string $property,
        string $query,
        int $limit = 10,
        int $maxDistance = 2,
        int $minTermLength = 4,
    ): array {
        $resultPtr = $this->ffi->new('lattice_fts_result*');
        $err = $this->txnHandle !== null
            ? $this->ffi->lattice_fts_search_fuzzy_txn(
                $this->txnHandle, $label, $property, $query, strlen($query),
                $limit, $maxDistance, $minTermLength,
                FFI::addr($resultPtr),
            )
            : $this->ffi->lattice_fts_search_fuzzy(
                $this->dbHandle, $label, $property, $query, strlen($query),
                $limit, $maxDistance, $minTermLength,
                FFI::addr($resultPtr),
            );
        LatticeLibrary::checkError($this->ffi, $err, 'Fuzzy search failed');

        return $this->collectResults($resultPtr);
    }

    /** @return FtsMatch[] */
    private function collectResults(CData $resultPtr): array
    {
        $count = $this->ffi->lattice_fts_result_count($resultPtr);
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $nodeId = $this->ffi->new('lattice_node_id');
            $score = $this->ffi->new('float');
            $err = $this->ffi->lattice_fts_result_get($resultPtr, $i, FFI::addr($nodeId), FFI::addr($score));
            LatticeLibrary::checkError($this->ffi, $err, 'Failed to get FTS result');

            $matches[] = new FtsMatch((int) $nodeId->cdata, (float) $score->cdata);
        }

        $this->ffi->lattice_fts_result_free($resultPtr);
        return $matches;
    }
}

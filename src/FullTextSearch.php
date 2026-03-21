<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\DTO\FtsMatch;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\FFI\LatticeLibrary;

class FullTextSearch
{
    public function __construct(
        private readonly FFI $ffi,
        private readonly CData $dbHandle,
        private readonly ?CData $txnHandle = null,
    ) {}

    public function index(int $nodeId, string $text): void
    {
        $txn = $this->requireTxn();
        $err = $this->ffi->lattice_fts_index($txn, $nodeId, $text, strlen($text));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to index text');
    }

    /** @return FtsMatch[] */
    public function search(string $query, int $limit = 10): array
    {
        $resultPtr = $this->ffi->new('lattice_fts_result*');
        $err = $this->ffi->lattice_fts_search($this->dbHandle, $query, strlen($query), $limit, FFI::addr($resultPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Full-text search failed');

        return $this->collectResults($resultPtr);
    }

    /** @return FtsMatch[] */
    public function searchFuzzy(string $query, int $limit = 10, int $maxDistance = 2, int $minTermLength = 4): array
    {
        $resultPtr = $this->ffi->new('lattice_fts_result*');
        $err = $this->ffi->lattice_fts_search_fuzzy(
            $this->dbHandle, $query, strlen($query),
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

    private function requireTxn(): CData
    {
        if ($this->txnHandle === null) {
            throw new TransactionException(
                'FTS indexing requires a transaction.',
                ErrorCode::Error,
            );
        }
        return $this->txnHandle;
    }
}

<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\DTO\VectorMatch;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\FFI\LatticeLibrary;

class VectorSearch
{
    public function __construct(
        private readonly FFI $ffi,
        private readonly CData $dbHandle,
        private readonly ?CData $txnHandle = null,
    ) {}

    /**
     * @param float[] $vector
     * @return VectorMatch[]
     */
    public function search(array $vector, int $k = 10, int $efSearch = 0): array
    {
        $dims = count($vector);
        $floatArr = $this->ffi->new("float[{$dims}]");
        foreach ($vector as $i => $v) {
            $floatArr[$i] = $v;
        }

        $resultPtr = $this->ffi->new('lattice_vector_result*');
        $err = $this->ffi->lattice_vector_search($this->dbHandle, $floatArr, $dims, $k, $efSearch, FFI::addr($resultPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Vector search failed');

        $count = $this->ffi->lattice_vector_result_count($resultPtr);
        $matches = [];

        for ($i = 0; $i < $count; $i++) {
            $nodeId = $this->ffi->new('lattice_node_id');
            $distance = $this->ffi->new('float');
            $err = $this->ffi->lattice_vector_result_get($resultPtr, $i, FFI::addr($nodeId), FFI::addr($distance));
            LatticeLibrary::checkError($this->ffi, $err, 'Failed to get vector result');

            $matches[] = new VectorMatch((int) $nodeId->cdata, (float) $distance->cdata);
        }

        $this->ffi->lattice_vector_result_free($resultPtr);
        return $matches;
    }

    /** @param float[] $vector */
    public function setVector(int $nodeId, string $key, array $vector): void
    {
        $txn = $this->requireTxn();
        $dims = count($vector);
        $floatArr = $this->ffi->new("float[{$dims}]");
        foreach ($vector as $i => $v) {
            $floatArr[$i] = $v;
        }

        $err = $this->ffi->lattice_node_set_vector($txn, $nodeId, $key, $floatArr, $dims);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to set vector');
    }

    /**
     * @param array<array{label: string, vector: float[]}> $nodes
     * @return int[] node IDs
     */
    public function batchInsert(array $nodes): array
    {
        $txn = $this->requireTxn();
        $count = count($nodes);

        $nodesArr = $this->ffi->new("lattice_node_with_vector[{$count}]");
        $floatBuffers = []; // Keep references to prevent GC

        foreach ($nodes as $i => $node) {
            $dims = count($node['vector']);
            $floatArr = $this->ffi->new("float[{$dims}]");
            foreach ($node['vector'] as $j => $v) {
                $floatArr[$j] = $v;
            }
            $floatBuffers[] = $floatArr;

            $nodesArr[$i]->label = $node['label'];
            $nodesArr[$i]->vector = $floatArr;
            $nodesArr[$i]->dimensions = $dims;
        }

        $nodeIds = $this->ffi->new("lattice_node_id[{$count}]");
        $countOut = $this->ffi->new('uint32_t');
        $err = $this->ffi->lattice_batch_insert($txn, $nodesArr, $count, $nodeIds, FFI::addr($countOut));
        LatticeLibrary::checkError($this->ffi, $err, 'Batch insert failed');

        $result = [];
        for ($i = 0; $i < (int) $countOut->cdata; $i++) {
            $result[] = (int) $nodeIds[$i];
        }
        return $result;
    }

    private function requireTxn(): CData
    {
        if ($this->txnHandle === null) {
            throw new TransactionException(
                'This vector operation requires a transaction.',
                ErrorCode::Error,
            );
        }
        return $this->txnHandle;
    }
}

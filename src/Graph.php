<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\DTO\EdgeDTO;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\FFI\LatticeLibrary;

class Graph
{
    public function __construct(
        private readonly FFI $ffi,
        private readonly ?CData $txnHandle = null,
    ) {}

    public function createNode(string $label, array $properties = []): int
    {
        $txn = $this->requireTxn();
        $nodeId = $this->ffi->new('lattice_node_id');
        $err = $this->ffi->lattice_node_create($txn, $label, FFI::addr($nodeId));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to create node');

        $id = (int) $nodeId->cdata;

        foreach ($properties as $key => $value) {
            $this->setProperty($id, $key, $value);
        }

        return $id;
    }

    public function addLabel(int $nodeId, string $label): void
    {
        $err = $this->ffi->lattice_node_add_label($this->requireTxn(), $nodeId, $label);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to add label');
    }

    public function removeLabel(int $nodeId, string $label): void
    {
        $err = $this->ffi->lattice_node_remove_label($this->requireTxn(), $nodeId, $label);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to remove label');
    }

    /** @return string[] */
    public function getLabels(int $nodeId): array
    {
        $labelsPtr = $this->ffi->new('char*');
        $err = $this->ffi->lattice_node_get_labels($this->requireTxn(), $nodeId, FFI::addr($labelsPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to get labels');

        $labels = LatticeLibrary::toPhpString($labelsPtr);
        $this->ffi->lattice_free_string($labelsPtr);

        if ($labels === '') {
            return [];
        }
        return explode(',', $labels);
    }

    public function setProperty(int $nodeId, string $key, mixed $value): void
    {
        [$val, $bufs] = LatticeLibrary::phpToValue($this->ffi, $value);
        $err = $this->ffi->lattice_node_set_property($this->requireTxn(), $nodeId, $key, FFI::addr($val));
        LatticeLibrary::freeBuffers($bufs);
        LatticeLibrary::checkError($this->ffi, $err, "Failed to set property '{$key}'");
    }

    public function getProperty(int $nodeId, string $key): mixed
    {
        $val = $this->ffi->new('lattice_value');
        $err = $this->ffi->lattice_node_get_property($this->requireTxn(), $nodeId, $key, FFI::addr($val));
        LatticeLibrary::checkError($this->ffi, $err, "Failed to get property '{$key}'");

        $result = LatticeLibrary::valueToPhp($this->ffi, $val);
        $this->ffi->lattice_value_free(FFI::addr($val));
        return $result;
    }

    public function nodeExists(int $nodeId): bool
    {
        $exists = $this->ffi->new('bool');
        $err = $this->ffi->lattice_node_exists($this->requireTxn(), $nodeId, FFI::addr($exists));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to check node existence');
        return (bool) $exists->cdata;
    }

    public function deleteNode(int $nodeId): void
    {
        $err = $this->ffi->lattice_node_delete($this->requireTxn(), $nodeId);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to delete node');
    }

    public function createEdge(int $sourceId, int $targetId, string $type, array $properties = []): int
    {
        $edgeId = $this->ffi->new('lattice_edge_id');
        $err = $this->ffi->lattice_edge_create($this->requireTxn(), $sourceId, $targetId, $type, FFI::addr($edgeId));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to create edge');

        $id = (int) $edgeId->cdata;

        foreach ($properties as $key => $value) {
            $this->setEdgeProperty($id, $key, $value);
        }

        return $id;
    }

    public function setEdgeProperty(int $edgeId, string $key, mixed $value): void
    {
        [$val, $bufs] = LatticeLibrary::phpToValue($this->ffi, $value);
        $err = $this->ffi->lattice_edge_set_property($this->requireTxn(), $edgeId, $key, FFI::addr($val));
        LatticeLibrary::freeBuffers($bufs);
        LatticeLibrary::checkError($this->ffi, $err, "Failed to set edge property '{$key}'");
    }

    public function getEdgeProperty(int $edgeId, string $key): mixed
    {
        $val = $this->ffi->new('lattice_value');
        $err = $this->ffi->lattice_edge_get_property($this->requireTxn(), $edgeId, $key, FFI::addr($val));
        LatticeLibrary::checkError($this->ffi, $err, "Failed to get edge property '{$key}'");

        $result = LatticeLibrary::valueToPhp($this->ffi, $val);
        $this->ffi->lattice_value_free(FFI::addr($val));
        return $result;
    }

    public function removeEdgeProperty(int $edgeId, string $key): void
    {
        $err = $this->ffi->lattice_edge_remove_property($this->requireTxn(), $edgeId, $key);
        LatticeLibrary::checkError($this->ffi, $err, "Failed to remove edge property '{$key}'");
    }

    public function deleteEdge(int $sourceId, int $targetId, string $type): void
    {
        $err = $this->ffi->lattice_edge_delete($this->requireTxn(), $sourceId, $targetId, $type);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to delete edge');
    }

    /** @return EdgeDTO[] */
    public function getOutgoingEdges(int $nodeId): array
    {
        return $this->getEdges($nodeId, outgoing: true);
    }

    /** @return EdgeDTO[] */
    public function getIncomingEdges(int $nodeId): array
    {
        return $this->getEdges($nodeId, outgoing: false);
    }

    /** @return EdgeDTO[] */
    private function getEdges(int $nodeId, bool $outgoing): array
    {
        $resultPtr = $this->ffi->new('lattice_edge_result*');
        $fn = $outgoing ? 'lattice_edge_get_outgoing' : 'lattice_edge_get_incoming';
        $err = $this->ffi->$fn($this->requireTxn(), $nodeId, FFI::addr($resultPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to get edges');

        $count = $this->ffi->lattice_edge_result_count($resultPtr);
        $edges = [];

        for ($i = 0; $i < $count; $i++) {
            $edgeId = $this->ffi->new('lattice_edge_id');
            $this->ffi->lattice_edge_result_get_id($resultPtr, $i, FFI::addr($edgeId));

            $source = $this->ffi->new('lattice_node_id');
            $target = $this->ffi->new('lattice_node_id');
            $typePtr = $this->ffi->new('char*');
            $typeLen = $this->ffi->new('uint32_t');
            $this->ffi->lattice_edge_result_get($resultPtr, $i, FFI::addr($source), FFI::addr($target), FFI::addr($typePtr), FFI::addr($typeLen));

            $edgeType = LatticeLibrary::toPhpString($typePtr);
            if (is_string($typePtr)) {
                $edgeType = substr($typePtr, 0, (int) $typeLen->cdata);
            } else {
                $edgeType = FFI::string($typePtr, (int) $typeLen->cdata);
            }

            $edges[] = new EdgeDTO(
                (int) $edgeId->cdata,
                (int) $source->cdata,
                (int) $target->cdata,
                $edgeType,
            );
        }

        $this->ffi->lattice_edge_result_free($resultPtr);
        return $edges;
    }

    private function requireTxn(): CData
    {
        if ($this->txnHandle === null) {
            throw new TransactionException(
                'Graph operations require a transaction. Use $db->transaction() or $db->beginTransaction().',
                ErrorCode::Error,
            );
        }
        return $this->txnHandle;
    }
}

<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\FFI\LatticeLibrary;

class QueryBuilder
{
    /** @var array<string, mixed> */
    private array $bindings = [];
    /** @var array<string, float[]> */
    private array $vectorBindings = [];

    public function __construct(
        private readonly FFI $ffi,
        private readonly CData $dbHandle,
        private readonly string $cypher,
        private readonly bool $autoTransaction = true,
        private readonly ?CData $txnHandle = null,
    ) {}

    public function bind(string $name, mixed $value): self
    {
        $this->bindings[$name] = $value;
        return $this;
    }

    public function bindVector(string $name, array $vector): self
    {
        $this->vectorBindings[$name] = $vector;
        return $this;
    }

    /** @return array<int, array<string, mixed>> */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->cursor() as $row) {
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return array<string, mixed>|null */
    public function first(): ?array
    {
        foreach ($this->cursor() as $row) {
            return $row;
        }
        return null;
    }

    public function scalar(): mixed
    {
        $row = $this->first();
        if ($row === null) {
            return null;
        }
        return reset($row);
    }

    /** @return \Generator<int, array<string, mixed>> */
    public function cursor(): \Generator
    {
        $query = $this->prepare();
        $txn = $this->txnHandle;
        $ownsTxn = false;
        $resultPtr = null;

        try {
            if ($txn === null && $this->autoTransaction) {
                $txnPtr = $this->ffi->new('lattice_txn*');
                $err = $this->ffi->lattice_begin($this->dbHandle, 0, FFI::addr($txnPtr)); // READ_ONLY
                LatticeLibrary::checkError($this->ffi, $err, 'Failed to begin auto transaction');
                $txn = $txnPtr;
                $ownsTxn = true;
            }

            $resultPtr = $this->ffi->new('lattice_result*');
            $err = $this->ffi->lattice_query_execute($query, $txn, FFI::addr($resultPtr));
            if ($err !== 0) {
                LatticeLibrary::checkQueryError($this->ffi, $err, $query, 'Query execution failed');
            }

            while ($this->ffi->lattice_result_next($resultPtr)) {
                $colCount = $this->ffi->lattice_result_column_count($resultPtr);
                $row = [];
                for ($i = 0; $i < $colCount; $i++) {
                    $colName = FFI::string($this->ffi->lattice_result_column_name($resultPtr, $i));
                    $val = $this->ffi->new('lattice_value');
                    $err = $this->ffi->lattice_result_get($resultPtr, $i, FFI::addr($val));
                    LatticeLibrary::checkError($this->ffi, $err, "Failed to get column {$i}");
                    $row[$colName] = LatticeLibrary::valueToPhp($this->ffi, $val);
                    // Do NOT free — values from lattice_result_get are borrowed
                }
                yield $row;
            }
        } catch (\Throwable $e) {
            if ($ownsTxn && isset($txn)) {
                $this->ffi->lattice_rollback($txn);
                $ownsTxn = false; // prevent double-free in finally
            }
            throw $e;
        } finally {
            // Always free result and query, even if Generator is abandoned (e.g. first()/scalar())
            if ($resultPtr !== null) {
                $this->ffi->lattice_result_free($resultPtr);
            }
            if ($ownsTxn && isset($txn)) {
                $this->ffi->lattice_commit($txn);
            }
            $this->ffi->lattice_query_free($query);
        }
    }

    public function execute(): void
    {
        // Consume the cursor to execute the query
        foreach ($this->cursor() as $_) {
        }
    }

    private function prepare(): CData
    {
        $queryPtr = $this->ffi->new('lattice_query*');
        $err = $this->ffi->lattice_query_prepare($this->dbHandle, $this->cypher, FFI::addr($queryPtr));
        if ($err !== 0) {
            // Query handle may be uninitialized on prepare failure — use checkError, not checkQueryError
            LatticeLibrary::checkError($this->ffi, $err, 'Query prepare failed');
        }

        foreach ($this->bindings as $name => $value) {
            [$val, $bufs] = LatticeLibrary::phpToValue($this->ffi, $value);
            $err = $this->ffi->lattice_query_bind($queryPtr, $name, FFI::addr($val));
            unset($bufs);
            LatticeLibrary::checkError($this->ffi, $err, "Failed to bind parameter '{$name}'");
        }

        foreach ($this->vectorBindings as $name => $vector) {
            $dims = count($vector);
            $floatArr = $this->ffi->new("float[{$dims}]");
            foreach ($vector as $i => $v) {
                $floatArr[$i] = $v;
            }
            $err = $this->ffi->lattice_query_bind_vector($queryPtr, $name, $floatArr, $dims);
            LatticeLibrary::checkError($this->ffi, $err, "Failed to bind vector '{$name}'");
        }

        return $queryPtr;
    }
}

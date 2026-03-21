<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\FFI\LatticeLibrary;

class Transaction
{
    private bool $finished = false;

    private function __construct(
        private readonly FFI $ffi,
        private readonly CData $dbHandle,
        private CData $handle,
        private readonly bool $readOnly,
    ) {}

    /** @internal */
    public static function begin(FFI $ffi, CData $dbHandle, bool $readOnly): self
    {
        $mode = $readOnly ? 0 : 1; // LATTICE_TXN_READ_ONLY / READ_WRITE
        $txnPtr = $ffi->new('lattice_txn*');
        $err = $ffi->lattice_begin($dbHandle, $mode, FFI::addr($txnPtr));
        LatticeLibrary::checkError($ffi, $err, 'Failed to begin transaction');

        return new self($ffi, $dbHandle, $txnPtr, $readOnly);
    }

    public function commit(): void
    {
        $this->ensureActive();
        $this->finished = true;
        $err = $this->ffi->lattice_commit($this->handle);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to commit transaction');
    }

    public function rollback(): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;
        $err = $this->ffi->lattice_rollback($this->handle);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to rollback transaction');
    }

    public function __destruct()
    {
        if (!$this->finished) {
            $this->rollback();
        }
    }

    public function graph(): Graph
    {
        $this->ensureActive();
        return new Graph($this->ffi, $this->handle);
    }

    public function vectors(): VectorSearch
    {
        $this->ensureActive();
        return new VectorSearch($this->ffi, $this->dbHandle, $this->handle);
    }

    public function fts(): FullTextSearch
    {
        $this->ensureActive();
        return new FullTextSearch($this->ffi, $this->dbHandle, $this->handle);
    }

    public function query(string $cypher): QueryBuilder
    {
        $this->ensureActive();
        return new QueryBuilder($this->ffi, $this->dbHandle, $cypher, autoTransaction: false, txnHandle: $this->handle);
    }

    /** @internal */
    public function getHandle(): CData
    {
        return $this->handle;
    }

    private function ensureActive(): void
    {
        if ($this->finished) {
            throw new TransactionException(
                'Transaction is already finished (committed or rolled back)',
                ErrorCode::Error,
            );
        }
    }
}

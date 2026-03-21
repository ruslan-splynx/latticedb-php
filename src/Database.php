<?php

namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\DTO\QueryCacheStats;
use LatticeDB\FFI\LatticeLibrary;

class Database
{
    private const VALID_OPTIONS = [
        'create', 'read_only', 'cache_size_mb', 'page_size',
        'enable_vector', 'vector_dimensions',
    ];

    private bool $closed = false;

    private function __construct(
        private readonly FFI $ffi,
        private CData $handle,
    ) {}

    public static function open(string $path, array $options = []): self
    {
        if ($path === '') {
            throw new \InvalidArgumentException('Database path must not be empty');
        }

        $unknown = array_diff(array_keys($options), self::VALID_OPTIONS);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown option: ' . implode(', ', $unknown));
        }

        $ffi = LatticeLibrary::ffiInstance();
        $opts = $ffi->new('lattice_open_options');
        $opts->create = $options['create'] ?? false;
        $opts->read_only = $options['read_only'] ?? false;
        $opts->cache_size_mb = $options['cache_size_mb'] ?? 100;
        $opts->page_size = $options['page_size'] ?? 4096;
        $opts->enable_vector = $options['enable_vector'] ?? false;
        $opts->vector_dimensions = $options['vector_dimensions'] ?? 128;

        $dbPtr = $ffi->new('lattice_database*');
        $err = $ffi->lattice_open($path, FFI::addr($opts), FFI::addr($dbPtr));
        LatticeLibrary::checkError($ffi, $err, "Failed to open database '{$path}'");

        return new self($ffi, $dbPtr);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $err = $this->ffi->lattice_close($this->handle);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to close database');
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }

    public static function version(): string
    {
        $ffi = LatticeLibrary::ffiInstance();
        return LatticeLibrary::toPhpString($ffi->lattice_version());
    }

    public function transaction(callable $callback): mixed
    {
        $txn = $this->beginTransaction();
        try {
            $result = $callback($txn);
            $txn->commit();
            return $result;
        } catch (\Throwable $e) {
            $txn->rollback();
            throw $e;
        }
    }

    public function read(callable $callback): mixed
    {
        $txn = $this->beginReadTransaction();
        try {
            $result = $callback($txn);
            $txn->commit();
            return $result;
        } catch (\Throwable $e) {
            $txn->rollback();
            throw $e;
        }
    }

    public function beginTransaction(): Transaction
    {
        return Transaction::begin($this->ffi, $this->handle, readOnly: false);
    }

    public function beginReadTransaction(): Transaction
    {
        return Transaction::begin($this->ffi, $this->handle, readOnly: true);
    }

    public function query(string $cypher): QueryBuilder
    {
        return new QueryBuilder($this->ffi, $this->handle, $cypher, autoTransaction: true);
    }

    public function graph(): Graph
    {
        return new Graph($this->ffi);
    }

    public function vectors(): VectorSearch
    {
        return new VectorSearch($this->ffi, $this->handle);
    }

    public function fts(): FullTextSearch
    {
        return new FullTextSearch($this->ffi, $this->handle);
    }

    public function embeddings(): EmbeddingService
    {
        return new EmbeddingService($this->ffi);
    }

    public function clearQueryCache(): void
    {
        $err = $this->ffi->lattice_query_cache_clear($this->handle);
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to clear query cache');
    }

    public function queryCacheStats(): QueryCacheStats
    {
        $entries = $this->ffi->new('uint32_t');
        $hits = $this->ffi->new('uint64_t');
        $misses = $this->ffi->new('uint64_t');
        $err = $this->ffi->lattice_query_cache_stats(
            $this->handle,
            FFI::addr($entries),
            FFI::addr($hits),
            FFI::addr($misses),
        );
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to get query cache stats');

        return new QueryCacheStats((int) $entries->cdata, (int) $hits->cdata, (int) $misses->cdata);
    }

    /** @internal */
    public function getHandle(): CData
    {
        return $this->handle;
    }

    /** @internal */
    public function getFFI(): FFI
    {
        return $this->ffi;
    }
}

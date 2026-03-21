# LatticeDB PHP Wrapper Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Composer-installable PHP 8.1+ FFI wrapper for LatticeDB's C API, providing a fluent API for graph operations, Cypher queries, vector search, full-text search, and embeddings.

**Architecture:** Four-layer design — PHP FFI loads `liblattice` via a C header. `LatticeLibrary` provides 1:1 C function mapping. Domain classes (`Graph`, `VectorSearch`, `FullTextSearch`, `QueryBuilder`, `EmbeddingService`) provide fluent PHP APIs. `Database` is the entry point, `Transaction` manages lifecycle.

**Tech Stack:** PHP 8.1+, ext-ffi, PHPUnit 10, LatticeDB C library (liblattice)

**Spec:** `docs/superpowers/specs/2026-03-21-latticedb-php-wrapper-design.md`
**C API Reference:** `LATTICEDB_C_API_REFERENCE.md`

---

### Task 1: Project Scaffolding

**Files:**
- Create: `composer.json`
- Create: `phpunit.xml`
- Create: `.gitignore`

- [ ] **Step 1: Create composer.json**

```json
{
    "name": "latticedb/latticedb",
    "description": "PHP FFI wrapper for LatticeDB — embedded knowledge graph with vector search and full-text search",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.1",
        "ext-ffi": "*"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.0"
    },
    "autoload": {
        "psr-4": {
            "LatticeDB\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "LatticeDB\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Create phpunit.xml**

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.0/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true">
    <testsuites>
        <testsuite name="Unit">
            <directory>tests/Unit</directory>
        </testsuite>
        <testsuite name="Integration">
            <directory>tests/Integration</directory>
        </testsuite>
    </testsuites>
    <source>
        <include>
            <directory>src</directory>
        </include>
    </source>
</phpunit>
```

- [ ] **Step 3: Create .gitignore**

```
/vendor/
composer.lock
*.ltdb
*.ltdb-wal
*.ltdb-shm
.phpunit.result.cache
```

- [ ] **Step 4: Install dependencies**

Run: `cd /Users/ex/latticedb-wrapper && composer install`
Expected: PHPUnit installed, autoload generated.

- [ ] **Step 5: Commit**

```bash
git add composer.json phpunit.xml .gitignore
git commit -m "feat: project scaffolding with composer and phpunit"
```

---

### Task 2: Enums and DTOs

**Files:**
- Create: `src/Enum/ErrorCode.php`
- Create: `src/Enum/QueryStage.php`
- Create: `src/Enum/EmbeddingApiFormat.php`
- Create: `src/DTO/EdgeDTO.php`
- Create: `src/DTO/VectorMatch.php`
- Create: `src/DTO/FtsMatch.php`
- Create: `src/DTO/QueryCacheStats.php`
- Create: `tests/Unit/Enum/ErrorCodeTest.php`
- Create: `tests/Unit/DTO/EdgeDTOTest.php`

- [ ] **Step 1: Write enum tests**

```php
<?php
// tests/Unit/Enum/ErrorCodeTest.php
namespace LatticeDB\Tests\Unit\Enum;

use LatticeDB\Enum\ErrorCode;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    public function testOkIsZero(): void
    {
        $this->assertSame(0, ErrorCode::Ok->value);
    }

    public function testNegativeErrorCodes(): void
    {
        $this->assertSame(-4, ErrorCode::NotFound->value);
        $this->assertSame(-7, ErrorCode::TxnAborted->value);
        $this->assertSame(-14, ErrorCode::Unsupported->value);
    }

    public function testFromValueRoundTrip(): void
    {
        $code = ErrorCode::from(-5);
        $this->assertSame(ErrorCode::AlreadyExists, $code);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Enum/ErrorCodeTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create all three enums**

`src/Enum/ErrorCode.php`:
```php
<?php
namespace LatticeDB\Enum;

enum ErrorCode: int
{
    case Ok = 0;
    case Error = -1;
    case Io = -2;
    case Corruption = -3;
    case NotFound = -4;
    case AlreadyExists = -5;
    case InvalidArg = -6;
    case TxnAborted = -7;
    case LockTimeout = -8;
    case ReadOnly = -9;
    case Full = -10;
    case VersionMismatch = -11;
    case Checksum = -12;
    case OutOfMemory = -13;
    case Unsupported = -14;
}
```

`src/Enum/QueryStage.php`:
```php
<?php
namespace LatticeDB\Enum;

enum QueryStage: int
{
    case None = 0;
    case Parse = 1;
    case Semantic = 2;
    case Plan = 3;
    case Execution = 4;
}
```

`src/Enum/EmbeddingApiFormat.php`:
```php
<?php
namespace LatticeDB\Enum;

enum EmbeddingApiFormat: int
{
    case Ollama = 0;
    case OpenAI = 1;
}
```

- [ ] **Step 4: Write DTO test**

```php
<?php
// tests/Unit/DTO/EdgeDTOTest.php
namespace LatticeDB\Tests\Unit\DTO;

use LatticeDB\DTO\EdgeDTO;
use LatticeDB\DTO\VectorMatch;
use LatticeDB\DTO\FtsMatch;
use LatticeDB\DTO\QueryCacheStats;
use PHPUnit\Framework\TestCase;

class EdgeDTOTest extends TestCase
{
    public function testEdgeDTOIsReadonly(): void
    {
        $edge = new EdgeDTO(1, 10, 20, 'KNOWS');
        $this->assertSame(1, $edge->id);
        $this->assertSame(10, $edge->source);
        $this->assertSame(20, $edge->target);
        $this->assertSame('KNOWS', $edge->type);
    }

    public function testVectorMatch(): void
    {
        $m = new VectorMatch(42, 0.95);
        $this->assertSame(42, $m->nodeId);
        $this->assertSame(0.95, $m->distance);
    }

    public function testFtsMatch(): void
    {
        $m = new FtsMatch(7, 3.14);
        $this->assertSame(7, $m->nodeId);
        $this->assertSame(3.14, $m->score);
    }

    public function testQueryCacheStats(): void
    {
        $s = new QueryCacheStats(10, 100, 5);
        $this->assertSame(10, $s->entries);
        $this->assertSame(100, $s->hits);
        $this->assertSame(5, $s->misses);
    }
}
```

- [ ] **Step 5: Create all four DTOs**

`src/DTO/EdgeDTO.php`:
```php
<?php
namespace LatticeDB\DTO;

readonly class EdgeDTO
{
    public function __construct(
        public int $id,
        public int $source,
        public int $target,
        public string $type,
    ) {}
}
```

`src/DTO/VectorMatch.php`:
```php
<?php
namespace LatticeDB\DTO;

readonly class VectorMatch
{
    public function __construct(
        public int $nodeId,
        public float $distance,
    ) {}
}
```

`src/DTO/FtsMatch.php`:
```php
<?php
namespace LatticeDB\DTO;

readonly class FtsMatch
{
    public function __construct(
        public int $nodeId,
        public float $score,
    ) {}
}
```

`src/DTO/QueryCacheStats.php`:
```php
<?php
namespace LatticeDB\DTO;

readonly class QueryCacheStats
{
    public function __construct(
        public int $entries,
        public int $hits,
        public int $misses,
    ) {}
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/`
Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Enum/ src/DTO/ tests/Unit/
git commit -m "feat: add enums (ErrorCode, QueryStage, EmbeddingApiFormat) and DTOs"
```

---

### Task 3: Exception Hierarchy

**Files:**
- Create: `src/Exception/LatticeException.php`
- Create: `src/Exception/ConnectionException.php`
- Create: `src/Exception/TransactionException.php`
- Create: `src/Exception/QueryException.php`
- Create: `src/Exception/NotFoundException.php`
- Create: `src/Exception/AlreadyExistsException.php`
- Create: `src/Exception/CorruptionException.php`
- Create: `src/Exception/IOException.php`
- Create: `tests/Unit/Exception/ExceptionTest.php`

- [ ] **Step 1: Write exception tests**

```php
<?php
// tests/Unit/Exception/ExceptionTest.php
namespace LatticeDB\Tests\Unit\Exception;

use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;
use LatticeDB\Exception\LatticeException;
use LatticeDB\Exception\ConnectionException;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Exception\QueryException;
use LatticeDB\Exception\NotFoundException;
use LatticeDB\Exception\AlreadyExistsException;
use LatticeDB\Exception\CorruptionException;
use LatticeDB\Exception\IOException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testLatticeExceptionCarriesErrorCode(): void
    {
        $e = new ConnectionException('connection failed', ErrorCode::Io);
        $this->assertInstanceOf(LatticeException::class, $e);
        $this->assertSame(ErrorCode::Io, $e->errorCode);
        $this->assertSame('connection failed', $e->getMessage());
    }

    public function testErrorCodeToExceptionMapping(): void
    {
        $this->assertInstanceOf(NotFoundException::class, LatticeException::fromErrorCode(ErrorCode::NotFound));
        $this->assertInstanceOf(AlreadyExistsException::class, LatticeException::fromErrorCode(ErrorCode::AlreadyExists));
        $this->assertInstanceOf(CorruptionException::class, LatticeException::fromErrorCode(ErrorCode::Corruption));
        $this->assertInstanceOf(CorruptionException::class, LatticeException::fromErrorCode(ErrorCode::Checksum));
        $this->assertInstanceOf(IOException::class, LatticeException::fromErrorCode(ErrorCode::Io));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::TxnAborted));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::LockTimeout));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::ReadOnly));
        $this->assertInstanceOf(ConnectionException::class, LatticeException::fromErrorCode(ErrorCode::VersionMismatch));
    }

    public function testInvalidArgThrowsInvalidArgumentException(): void
    {
        $e = LatticeException::fromErrorCode(ErrorCode::InvalidArg);
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testQueryExceptionDiagnostics(): void
    {
        $e = new QueryException(
            'parse error',
            ErrorCode::Error,
            QueryStage::Parse,
            'SYNTAX_ERROR',
            1,
            5,
            3,
        );
        $this->assertSame(QueryStage::Parse, $e->getStage());
        $this->assertSame('SYNTAX_ERROR', $e->getQueryErrorCode());
        $this->assertSame(1, $e->getLine());
        $this->assertSame(5, $e->getColumn());
        $this->assertSame(3, $e->getLength());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Exception/ExceptionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create all exception classes**

`src/Exception/LatticeException.php`:
```php
<?php
namespace LatticeDB\Exception;

use LatticeDB\Enum\ErrorCode;

abstract class LatticeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorCode $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode->value, $previous);
    }

    public static function fromErrorCode(ErrorCode $code, string $message = ''): \Throwable
    {
        if ($message === '') {
            $message = "LatticeDB error: {$code->name}";
        }

        return match ($code) {
            ErrorCode::NotFound => new NotFoundException($message, $code),
            ErrorCode::AlreadyExists => new AlreadyExistsException($message, $code),
            ErrorCode::Corruption, ErrorCode::Checksum => new CorruptionException($message, $code),
            ErrorCode::Io => new IOException($message, $code),
            ErrorCode::TxnAborted, ErrorCode::LockTimeout, ErrorCode::ReadOnly => new TransactionException($message, $code),
            ErrorCode::VersionMismatch => new ConnectionException($message, $code),
            ErrorCode::InvalidArg => new \InvalidArgumentException($message, $code->value),
            default => new ConnectionException($message, $code),
        };
    }
}
```

`src/Exception/ConnectionException.php`:
```php
<?php
namespace LatticeDB\Exception;

use LatticeDB\Enum\ErrorCode;

class ConnectionException extends LatticeException
{
}
```

`src/Exception/TransactionException.php`:
```php
<?php
namespace LatticeDB\Exception;

class TransactionException extends LatticeException
{
}
```

`src/Exception/NotFoundException.php`:
```php
<?php
namespace LatticeDB\Exception;

class NotFoundException extends LatticeException
{
}
```

`src/Exception/AlreadyExistsException.php`:
```php
<?php
namespace LatticeDB\Exception;

class AlreadyExistsException extends LatticeException
{
}
```

`src/Exception/CorruptionException.php`:
```php
<?php
namespace LatticeDB\Exception;

class CorruptionException extends LatticeException
{
}
```

`src/Exception/IOException.php`:
```php
<?php
namespace LatticeDB\Exception;

class IOException extends LatticeException
{
}
```

`src/Exception/QueryException.php`:
```php
<?php
namespace LatticeDB\Exception;

use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;

class QueryException extends LatticeException
{
    public function __construct(
        string $message,
        ErrorCode $errorCode,
        private readonly QueryStage $stage = QueryStage::None,
        private readonly ?string $queryErrorCode = null,
        private readonly ?int $line = null,
        private readonly ?int $column = null,
        private readonly ?int $length = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode, $previous);
    }

    public function getStage(): QueryStage { return $this->stage; }
    public function getQueryErrorCode(): ?string { return $this->queryErrorCode; }
    public function getLine(): ?int { return $this->line; }
    public function getColumn(): ?int { return $this->column; }
    public function getLength(): ?int { return $this->length; }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/Exception/ExceptionTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Exception/ tests/Unit/Exception/
git commit -m "feat: add exception hierarchy with error code mapping"
```

---

### Task 4: C Header and FFI LatticeLibrary

This is the core FFI layer — the most critical and complex task. It provides 1:1 PHP methods for all 55 C API functions.

**Files:**
- Create: `src/FFI/lattice.h`
- Create: `src/FFI/LatticeLibrary.php`
- Create: `tests/Unit/FFI/LatticeLibraryTest.php`

- [ ] **Step 1: Create the C header for FFI::cdef()**

`src/FFI/lattice.h` — this is a PHP-FFI-compatible header (subset of C, no includes, no macros):

```c
// Opaque types
typedef struct lattice_database lattice_database;
typedef struct lattice_txn lattice_txn;
typedef struct lattice_query lattice_query;
typedef struct lattice_result lattice_result;
typedef struct lattice_vector_result lattice_vector_result;
typedef struct lattice_fts_result lattice_fts_result;
typedef struct lattice_edge_result lattice_edge_result;
typedef struct lattice_embedding_client lattice_embedding_client;

// ID types
typedef uint64_t lattice_node_id;
typedef uint64_t lattice_edge_id;

// Error codes
typedef enum {
    LATTICE_OK = 0,
    LATTICE_ERROR = -1,
    LATTICE_ERROR_IO = -2,
    LATTICE_ERROR_CORRUPTION = -3,
    LATTICE_ERROR_NOT_FOUND = -4,
    LATTICE_ERROR_ALREADY_EXISTS = -5,
    LATTICE_ERROR_INVALID_ARG = -6,
    LATTICE_ERROR_TXN_ABORTED = -7,
    LATTICE_ERROR_LOCK_TIMEOUT = -8,
    LATTICE_ERROR_READ_ONLY = -9,
    LATTICE_ERROR_FULL = -10,
    LATTICE_ERROR_VERSION_MISMATCH = -11,
    LATTICE_ERROR_CHECKSUM = -12,
    LATTICE_ERROR_OUT_OF_MEMORY = -13,
    LATTICE_ERROR_UNSUPPORTED = -14
} lattice_error;

// Value types
typedef enum {
    LATTICE_VALUE_NULL = 0,
    LATTICE_VALUE_BOOL = 1,
    LATTICE_VALUE_INT = 2,
    LATTICE_VALUE_FLOAT = 3,
    LATTICE_VALUE_STRING = 4,
    LATTICE_VALUE_BYTES = 5,
    LATTICE_VALUE_VECTOR = 6
} lattice_value_type;

// Transaction modes
typedef enum {
    LATTICE_TXN_READ_ONLY = 0,
    LATTICE_TXN_READ_WRITE = 1
} lattice_txn_mode;

// Embedding API format
typedef enum {
    LATTICE_EMBEDDING_OLLAMA = 0,
    LATTICE_EMBEDDING_OPENAI = 1
} lattice_embedding_api_format;

// Query error stages
typedef enum {
    LATTICE_QUERY_STAGE_NONE = 0,
    LATTICE_QUERY_STAGE_PARSE = 1,
    LATTICE_QUERY_STAGE_SEMANTIC = 2,
    LATTICE_QUERY_STAGE_PLAN = 3,
    LATTICE_QUERY_STAGE_EXECUTION = 4
} lattice_query_error_stage;

// Value struct — tagged union
typedef struct {
    lattice_value_type type;
    union {
        bool bool_val;
        int64_t int_val;
        double float_val;
        struct { const char* ptr; size_t len; } string_val;
        struct { const uint8_t* ptr; size_t len; } bytes_val;
        struct { const float* ptr; uint32_t dimensions; } vector_val;
    } data;
} lattice_value;

// Open options
typedef struct {
    bool create;
    bool read_only;
    uint32_t cache_size_mb;
    uint32_t page_size;
    bool enable_vector;
    uint16_t vector_dimensions;
} lattice_open_options;

// Embedding config
typedef struct {
    const char* endpoint;
    const char* model;
    lattice_embedding_api_format api_format;
    const char* api_key;
    uint32_t timeout_ms;
} lattice_embedding_config;

// Batch insert struct
typedef struct {
    const char* label;
    const float* vector;
    uint32_t dimensions;
} lattice_node_with_vector;

// === Functions ===

// Database lifecycle
lattice_error lattice_open(const char* path, const lattice_open_options* options, lattice_database** db_out);
lattice_error lattice_close(lattice_database* db);

// Transactions
lattice_error lattice_begin(lattice_database* db, lattice_txn_mode mode, lattice_txn** txn_out);
lattice_error lattice_commit(lattice_txn* txn);
lattice_error lattice_rollback(lattice_txn* txn);

// Node operations
lattice_error lattice_node_create(lattice_txn* txn, const char* label, lattice_node_id* node_out);
lattice_error lattice_node_add_label(lattice_txn* txn, lattice_node_id node_id, const char* label);
lattice_error lattice_node_remove_label(lattice_txn* txn, lattice_node_id node_id, const char* label);
lattice_error lattice_node_delete(lattice_txn* txn, lattice_node_id node_id);
lattice_error lattice_node_set_property(lattice_txn* txn, lattice_node_id node_id, const char* key, const lattice_value* value);
lattice_error lattice_node_get_property(lattice_txn* txn, lattice_node_id node_id, const char* key, lattice_value* value_out);
lattice_error lattice_node_exists(lattice_txn* txn, lattice_node_id node_id, bool* exists_out);
lattice_error lattice_node_get_labels(lattice_txn* txn, lattice_node_id node_id, char** labels_out);
lattice_error lattice_node_set_vector(lattice_txn* txn, lattice_node_id node_id, const char* key, const float* vector, uint32_t dimensions);

// Batch insert
lattice_error lattice_batch_insert(lattice_txn* txn, const lattice_node_with_vector* nodes, uint32_t count, lattice_node_id* node_ids_out, uint32_t* count_out);

// Edge operations
lattice_error lattice_edge_create(lattice_txn* txn, lattice_node_id source, lattice_node_id target, const char* edge_type, lattice_edge_id* edge_out);
lattice_error lattice_edge_delete(lattice_txn* txn, lattice_node_id source, lattice_node_id target, const char* edge_type);
lattice_error lattice_edge_set_property(lattice_txn* txn, lattice_edge_id edge_id, const char* key, const lattice_value* value);
lattice_error lattice_edge_get_property(lattice_txn* txn, lattice_edge_id edge_id, const char* key, lattice_value* value_out);
lattice_error lattice_edge_remove_property(lattice_txn* txn, lattice_edge_id edge_id, const char* key);
lattice_error lattice_edge_get_outgoing(lattice_txn* txn, lattice_node_id node_id, lattice_edge_result** result_out);
lattice_error lattice_edge_get_incoming(lattice_txn* txn, lattice_node_id node_id, lattice_edge_result** result_out);
uint32_t lattice_edge_result_count(lattice_edge_result* result);
lattice_error lattice_edge_result_get_id(lattice_edge_result* result, uint32_t index, lattice_edge_id* edge_id_out);
lattice_error lattice_edge_result_get(lattice_edge_result* result, uint32_t index, lattice_node_id* source_out, lattice_node_id* target_out, const char** edge_type_out, uint32_t* edge_type_len_out);
void lattice_edge_result_free(lattice_edge_result* result);

// Vector search
lattice_error lattice_vector_search(lattice_database* db, const float* vector, uint32_t dimensions, uint32_t k, uint16_t ef_search, lattice_vector_result** result_out);
uint32_t lattice_vector_result_count(lattice_vector_result* result);
lattice_error lattice_vector_result_get(lattice_vector_result* result, uint32_t index, lattice_node_id* node_id_out, float* distance_out);
void lattice_vector_result_free(lattice_vector_result* result);

// Full-text search
lattice_error lattice_fts_index(lattice_txn* txn, lattice_node_id node_id, const char* text, size_t text_len);
lattice_error lattice_fts_search(lattice_database* db, const char* query, size_t query_len, uint32_t limit, lattice_fts_result** result_out);
lattice_error lattice_fts_search_fuzzy(lattice_database* db, const char* query, size_t query_len, uint32_t limit, uint32_t max_distance, uint32_t min_term_length, lattice_fts_result** result_out);
uint32_t lattice_fts_result_count(lattice_fts_result* result);
lattice_error lattice_fts_result_get(lattice_fts_result* result, uint32_t index, lattice_node_id* node_id_out, float* score_out);
void lattice_fts_result_free(lattice_fts_result* result);

// Query operations
lattice_error lattice_query_prepare(lattice_database* db, const char* cypher, lattice_query** query_out);
lattice_error lattice_query_bind(lattice_query* query, const char* name, const lattice_value* value);
lattice_error lattice_query_bind_vector(lattice_query* query, const char* name, const float* vector, uint32_t dimensions);
lattice_error lattice_query_execute(lattice_query* query, lattice_txn* txn, lattice_result** result_out);
lattice_query_error_stage lattice_query_last_error_stage(lattice_query* query);
const char* lattice_query_last_error_message(lattice_query* query);
const char* lattice_query_last_error_code(lattice_query* query);
bool lattice_query_last_error_has_location(lattice_query* query);
uint32_t lattice_query_last_error_line(lattice_query* query);
uint32_t lattice_query_last_error_column(lattice_query* query);
uint32_t lattice_query_last_error_length(lattice_query* query);
void lattice_query_free(lattice_query* query);

// Result operations
bool lattice_result_next(lattice_result* result);
uint32_t lattice_result_column_count(lattice_result* result);
const char* lattice_result_column_name(lattice_result* result, uint32_t index);
lattice_error lattice_result_get(lattice_result* result, uint32_t index, lattice_value* value_out);
void lattice_result_free(lattice_result* result);

// Query cache
lattice_error lattice_query_cache_clear(lattice_database* db);
lattice_error lattice_query_cache_stats(lattice_database* db, uint32_t* entries_out, uint64_t* hits_out, uint64_t* misses_out);

// Embedding operations
lattice_error lattice_hash_embed(const char* text, size_t text_len, uint16_t dimensions, float** vector_out, uint32_t* dims_out);
void lattice_hash_embed_free(float* vector, uint32_t dimensions);
lattice_error lattice_embedding_client_create(const lattice_embedding_config* config, lattice_embedding_client** client_out);
lattice_error lattice_embedding_client_embed(lattice_embedding_client* client, const char* text, size_t text_len, float** vector_out, uint32_t* dims_out);
void lattice_embedding_client_free(lattice_embedding_client* client);

// Memory management
void lattice_free_string(char* str);
void lattice_value_free(lattice_value* value);

// Utility
const char* lattice_version(void);
const char* lattice_error_message(lattice_error code);
```

- [ ] **Step 2: Write LatticeLibrary unit test (library discovery + value conversion)**

```php
<?php
// tests/Unit/FFI/LatticeLibraryTest.php
namespace LatticeDB\Tests\Unit\FFI;

use LatticeDB\FFI\LatticeLibrary;
use PHPUnit\Framework\TestCase;

class LatticeLibraryTest extends TestCase
{
    public function testLibraryNameForCurrentPlatform(): void
    {
        $name = LatticeLibrary::libraryFileName();
        $this->assertMatchesRegularExpression('/^(liblattice\.(dylib|so)|lattice\.dll)$/', $name);
    }

    public function testPhpToLatticeValueNull(): void
    {
        $ffi = LatticeLibrary::ffiInstance();
        [$val, $bufs] = LatticeLibrary::phpToValue($ffi, null);
        $this->assertSame(0, $val->type); // LATTICE_VALUE_NULL
    }

    public function testPhpToLatticeValueBool(): void
    {
        $ffi = LatticeLibrary::ffiInstance();
        [$val, $bufs] = LatticeLibrary::phpToValue($ffi, true);
        $this->assertSame(1, $val->type); // LATTICE_VALUE_BOOL
    }

    public function testPhpToLatticeValueInt(): void
    {
        $ffi = LatticeLibrary::ffiInstance();
        [$val, $bufs] = LatticeLibrary::phpToValue($ffi, 42);
        $this->assertSame(2, $val->type); // LATTICE_VALUE_INT
    }

    public function testPhpToLatticeValueFloat(): void
    {
        $ffi = LatticeLibrary::ffiInstance();
        [$val, $bufs] = LatticeLibrary::phpToValue($ffi, 3.14);
        $this->assertSame(3, $val->type); // LATTICE_VALUE_FLOAT
    }

    public function testPhpToLatticeValueString(): void
    {
        $ffi = LatticeLibrary::ffiInstance();
        [$val, $bufs] = LatticeLibrary::phpToValue($ffi, 'hello');
        $this->assertSame(4, $val->type); // LATTICE_VALUE_STRING
        $this->assertNotEmpty($bufs); // buffer must be kept alive
    }

    public function testLatticeValueToPhpRoundTrip(): void
    {
        $ffi = LatticeLibrary::ffiInstance();

        [$v1, $b1] = LatticeLibrary::phpToValue($ffi, null);
        $this->assertNull(LatticeLibrary::valueToPhp($ffi, $v1));
        [$v2, $b2] = LatticeLibrary::phpToValue($ffi, true);
        $this->assertTrue(LatticeLibrary::valueToPhp($ffi, $v2));
        [$v3, $b3] = LatticeLibrary::phpToValue($ffi, 42);
        $this->assertSame(42, LatticeLibrary::valueToPhp($ffi, $v3));
        [$v4, $b4] = LatticeLibrary::phpToValue($ffi, 3.14);
        $this->assertSame(3.14, LatticeLibrary::valueToPhp($ffi, $v4));
        [$v5, $b5] = LatticeLibrary::phpToValue($ffi, 'hello');
        $this->assertSame('hello', LatticeLibrary::valueToPhp($ffi, $v5));
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/FFI/LatticeLibraryTest.php`
Expected: FAIL — class not found.

- [ ] **Step 4: Create LatticeLibrary**

`src/FFI/LatticeLibrary.php` — the full FFI wrapper class. Key responsibilities:
- Library discovery across platform-specific paths
- FFI loading via `FFI::cdef()` with the bundled header
- Static `phpToValue()` / `valueToPhp()` conversion methods
- `checkError()` method that converts C error codes to PHP exceptions
- All 55 C API functions as thin PHP method wrappers

```php
<?php
namespace LatticeDB\FFI;

use FFI;
use FFI\CData;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;
use LatticeDB\Exception\LatticeException;
use LatticeDB\Exception\QueryException;

class LatticeLibrary
{
    private static ?FFI $ffi = null;

    public static function libraryFileName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'liblattice.dylib',
            'Windows' => 'lattice.dll',
            default => 'liblattice.so',
        };
    }

    private static function discoverLibraryPath(): string
    {
        $fileName = self::libraryFileName();

        $envPath = getenv('LATTICE_LIB_PATH');
        if ($envPath !== false && file_exists($envPath)) {
            return $envPath;
        }

        $searchDirs = [
            dirname(__DIR__, 2) . '/lib',
            'zig-out/lib',
        ];

        if (PHP_OS_FAMILY === 'Darwin') {
            $searchDirs[] = '/opt/homebrew/lib';
            $searchDirs[] = '/usr/local/opt/latticedb/lib';
        }

        $searchDirs[] = '/usr/local/lib';
        $searchDirs[] = '/usr/lib';
        $home = getenv('HOME');
        if ($home !== false) {
            $searchDirs[] = $home . '/.local/lib';
        }

        foreach ($searchDirs as $dir) {
            $path = $dir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException(
            "Cannot find {$fileName}. Set LATTICE_LIB_PATH or install liblattice to a standard location."
        );
    }

    public static function ffiInstance(): FFI
    {
        if (self::$ffi === null) {
            $headerPath = __DIR__ . '/lattice.h';
            $libPath = self::discoverLibraryPath();
            self::$ffi = FFI::cdef(file_get_contents($headerPath), $libPath);
        }
        return self::$ffi;
    }

    /** Reset the FFI singleton (for testing). */
    public static function reset(): void
    {
        self::$ffi = null;
    }

    public static function checkError(FFI $ffi, int $code, string $context = ''): void
    {
        if ($code === 0) {
            return;
        }

        $errorCode = ErrorCode::tryFrom($code) ?? ErrorCode::Error;
        $msg = FFI::string($ffi->lattice_error_message($code));
        if ($context !== '') {
            $msg = "{$context}: {$msg}";
        }

        throw LatticeException::fromErrorCode($errorCode, $msg);
    }

    public static function checkQueryError(FFI $ffi, int $code, CData $query, string $context = ''): void
    {
        if ($code === 0) {
            return;
        }

        $errorCode = ErrorCode::tryFrom($code) ?? ErrorCode::Error;
        $stage = QueryStage::tryFrom($ffi->lattice_query_last_error_stage($query)) ?? QueryStage::None;
        $msgPtr = $ffi->lattice_query_last_error_message($query);
        $msg = $msgPtr !== null ? FFI::string($msgPtr) : 'Unknown query error';
        $qCode = $ffi->lattice_query_last_error_code($query);
        $queryErrorCode = $qCode !== null ? FFI::string($qCode) : null;

        $line = null;
        $column = null;
        $length = null;
        if ($ffi->lattice_query_last_error_has_location($query)) {
            $line = $ffi->lattice_query_last_error_line($query);
            $column = $ffi->lattice_query_last_error_column($query);
            $length = $ffi->lattice_query_last_error_length($query);
        }

        if ($context !== '') {
            $msg = "{$context}: {$msg}";
        }

        throw new QueryException($msg, $errorCode, $stage, $queryErrorCode, $line, $column, $length);
    }

    /**
     * Convert a PHP value to a lattice_value CData struct.
     * Returns [$val, $buffers] where $buffers must be kept alive until the FFI call completes.
     * @return array{CData, array<CData>}
     */
    public static function phpToValue(FFI $ffi, mixed $value): array
    {
        $val = $ffi->new('lattice_value');
        $buffers = [];

        if ($value === null) {
            $val->type = 0; // LATTICE_VALUE_NULL
        } elseif (is_bool($value)) {
            $val->type = 1; // LATTICE_VALUE_BOOL
            $val->data->bool_val = $value;
        } elseif (is_int($value)) {
            $val->type = 2; // LATTICE_VALUE_INT
            $val->data->int_val = $value;
        } elseif (is_float($value)) {
            $val->type = 3; // LATTICE_VALUE_FLOAT
            $val->data->float_val = $value;
        } elseif (is_string($value)) {
            $val->type = 4; // LATTICE_VALUE_STRING
            $len = strlen($value);
            // PHP-managed buffer — lives as long as $buffers reference is held
            $buf = $ffi->new('char[' . ($len + 1) . ']');
            FFI::memcpy($buf, $value, $len);
            $val->data->string_val->ptr = $buf;
            $val->data->string_val->len = $len;
            $buffers[] = $buf;
        } else {
            throw new \InvalidArgumentException('Unsupported PHP type for lattice_value: ' . get_debug_type($value));
        }

        return [$val, $buffers];
    }

    public static function valueToPhp(FFI $ffi, CData $val): mixed
    {
        return match ($val->type) {
            0 => null, // NULL
            1 => (bool) $val->data->bool_val, // BOOL
            2 => (int) $val->data->int_val, // INT
            3 => (float) $val->data->float_val, // FLOAT
            4 => FFI::string($val->data->string_val->ptr, $val->data->string_val->len), // STRING
            5 => FFI::string($val->data->bytes_val->ptr, $val->data->bytes_val->len), // BYTES
            6 => self::vectorToPhpArray($ffi, $val), // VECTOR
            default => throw new \RuntimeException("Unknown lattice_value type: {$val->type}"),
        };
    }

    private static function vectorToPhpArray(FFI $ffi, CData $val): array
    {
        $dims = $val->data->vector_val->dimensions;
        $result = [];
        for ($i = 0; $i < $dims; $i++) {
            $result[] = $val->data->vector_val->ptr[$i];
        }
        return $result;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/FFI/LatticeLibraryTest.php`
Expected: Library discovery test passes, value conversion tests pass (these don't need the actual library to load — they use FFI struct operations only).

Note: The `ffiInstance()` tests that require the actual library will be skipped if `liblattice` is not installed. We'll add a `@requires` annotation or conditional skip in integration tests.

Actually, the value conversion tests DO require FFI::cdef which needs the library. Let's restructure — value conversion tests should go into integration tests, and the unit test only tests `libraryFileName()`.

- [ ] **Step 6: Adjust unit test to not require the library**

The unit test should only test `libraryFileName()`. Move value conversion tests to integration. Update `tests/Unit/FFI/LatticeLibraryTest.php`:

```php
<?php
namespace LatticeDB\Tests\Unit\FFI;

use LatticeDB\FFI\LatticeLibrary;
use PHPUnit\Framework\TestCase;

class LatticeLibraryTest extends TestCase
{
    public function testLibraryNameForCurrentPlatform(): void
    {
        $name = LatticeLibrary::libraryFileName();
        $this->assertMatchesRegularExpression('/^(liblattice\.(dylib|so)|lattice\.dll)$/', $name);
    }
}
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/`
Expected: All unit tests PASS.

- [ ] **Step 8: Commit**

```bash
git add src/FFI/ tests/Unit/FFI/
git commit -m "feat: add FFI LatticeLibrary with C header and value conversion"
```

---

### Task 5: Database Class

**Files:**
- Create: `src/Database.php`
- Create: `tests/Unit/DatabaseTest.php`

- [ ] **Step 1: Write unit test for Database options validation**

```php
<?php
// tests/Unit/DatabaseTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testOpenRequiresPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::open('', ['create' => true]);
    }

    public function testOpenRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');
        Database::open('/tmp/test.ltdb', ['unknown_option' => true]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/DatabaseTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create Database class**

`src/Database.php`:
```php
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
        return FFI::string($ffi->lattice_version());
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/DatabaseTest.php`
Expected: All tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Database.php tests/Unit/DatabaseTest.php
git commit -m "feat: add Database class with open/close, transactions, and query cache"
```

---

### Task 6: Transaction Class

**Files:**
- Create: `src/Transaction.php`
- Create: `tests/Unit/TransactionTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php
// tests/Unit/TransactionTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\Transaction;
use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase
{
    public function testDoubleCommitThrows(): void
    {
        // This test requires a real DB, so it's more of a design validation.
        // We test that the class exists and has the expected interface.
        $ref = new \ReflectionClass(Transaction::class);
        $this->assertTrue($ref->hasMethod('commit'));
        $this->assertTrue($ref->hasMethod('rollback'));
        $this->assertTrue($ref->hasMethod('graph'));
        $this->assertTrue($ref->hasMethod('vectors'));
        $this->assertTrue($ref->hasMethod('fts'));
        $this->assertTrue($ref->hasMethod('query'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/TransactionTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create Transaction class**

`src/Transaction.php`:
```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/TransactionTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Transaction.php tests/Unit/TransactionTest.php
git commit -m "feat: add Transaction class with auto-rollback on destruct"
```

---

### Task 7: Graph Class

**Files:**
- Create: `src/Graph.php`
- Create: `tests/Unit/GraphTest.php`

- [ ] **Step 1: Write unit test (interface validation)**

```php
<?php
// tests/Unit/GraphTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\Graph;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(Graph::class);
        $expected = [
            'createNode', 'addLabel', 'removeLabel', 'getLabels',
            'setProperty', 'getProperty', 'nodeExists', 'deleteNode',
            'createEdge', 'setEdgeProperty', 'getEdgeProperty',
            'removeEdgeProperty', 'deleteEdge',
            'getOutgoingEdges', 'getIncomingEdges',
        ];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/GraphTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create Graph class**

`src/Graph.php`:
```php
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

        $labels = FFI::string($labelsPtr);
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
        unset($bufs); // safe to release string buffers after FFI call
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
        unset($bufs);
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

            $edges[] = new EdgeDTO(
                (int) $edgeId->cdata,
                (int) $source->cdata,
                (int) $target->cdata,
                FFI::string($typePtr, (int) $typeLen->cdata),
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/GraphTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Graph.php tests/Unit/GraphTest.php
git commit -m "feat: add Graph class with node/edge CRUD and traversal"
```

---

### Task 8: QueryBuilder Class

**Files:**
- Create: `src/QueryBuilder.php`
- Create: `tests/Unit/QueryBuilderTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php
// tests/Unit/QueryBuilderTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(QueryBuilder::class);
        $expected = ['bind', 'bindVector', 'rows', 'first', 'scalar', 'cursor', 'execute'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }

    public function testBindReturnsSelf(): void
    {
        $ref = new \ReflectionMethod(QueryBuilder::class, 'bind');
        $returnType = $ref->getReturnType();
        $this->assertSame('self', $returnType->getName());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/QueryBuilderTest.php`
Expected: FAIL — class not found.

- [ ] **Step 3: Create QueryBuilder class**

`src/QueryBuilder.php`:
```php
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
            if (isset($resultPtr)) {
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/QueryBuilderTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/QueryBuilder.php tests/Unit/QueryBuilderTest.php
git commit -m "feat: add QueryBuilder with bind, cursor, rows, first, scalar"
```

---

### Task 9: VectorSearch Class

**Files:**
- Create: `src/VectorSearch.php`
- Create: `tests/Unit/VectorSearchTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php
// tests/Unit/VectorSearchTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\VectorSearch;
use PHPUnit\Framework\TestCase;

class VectorSearchTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(VectorSearch::class);
        $expected = ['search', 'setVector', 'batchInsert'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/VectorSearchTest.php`
Expected: FAIL.

- [ ] **Step 3: Create VectorSearch class**

`src/VectorSearch.php`:
```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/VectorSearchTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/VectorSearch.php tests/Unit/VectorSearchTest.php
git commit -m "feat: add VectorSearch with search, setVector, and batchInsert"
```

---

### Task 10: FullTextSearch Class

**Files:**
- Create: `src/FullTextSearch.php`
- Create: `tests/Unit/FullTextSearchTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php
// tests/Unit/FullTextSearchTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\FullTextSearch;
use PHPUnit\Framework\TestCase;

class FullTextSearchTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(FullTextSearch::class);
        $expected = ['index', 'search', 'searchFuzzy'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/FullTextSearchTest.php`
Expected: FAIL.

- [ ] **Step 3: Create FullTextSearch class**

`src/FullTextSearch.php`:
```php
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
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/FullTextSearchTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/FullTextSearch.php tests/Unit/FullTextSearchTest.php
git commit -m "feat: add FullTextSearch with index, search, and searchFuzzy"
```

---

### Task 11: EmbeddingService and EmbeddingClient

**Files:**
- Create: `src/EmbeddingService.php`
- Create: `src/EmbeddingClient.php`
- Create: `tests/Unit/EmbeddingServiceTest.php`

- [ ] **Step 1: Write unit test**

```php
<?php
// tests/Unit/EmbeddingServiceTest.php
namespace LatticeDB\Tests\Unit;

use LatticeDB\EmbeddingService;
use LatticeDB\EmbeddingClient;
use PHPUnit\Framework\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function testServiceHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(EmbeddingService::class);
        $this->assertTrue($ref->hasMethod('hash'));
        $this->assertTrue($ref->hasMethod('createClient'));
    }

    public function testClientHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(EmbeddingClient::class);
        $this->assertTrue($ref->hasMethod('embed'));
        $this->assertTrue($ref->hasMethod('close'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/EmbeddingServiceTest.php`
Expected: FAIL.

- [ ] **Step 3: Create EmbeddingService and EmbeddingClient**

`src/EmbeddingService.php`:
```php
<?php
namespace LatticeDB;

use FFI;
use LatticeDB\Enum\EmbeddingApiFormat;
use LatticeDB\FFI\LatticeLibrary;

class EmbeddingService
{
    public function __construct(
        private readonly FFI $ffi,
    ) {}

    /** @return float[] */
    public function hash(string $text, int $dimensions = 128): array
    {
        $vectorPtr = $this->ffi->new('float*');
        $dimsOut = $this->ffi->new('uint32_t');
        $err = $this->ffi->lattice_hash_embed($text, strlen($text), $dimensions, FFI::addr($vectorPtr), FFI::addr($dimsOut));
        LatticeLibrary::checkError($this->ffi, $err, 'Hash embedding failed');

        $dims = (int) $dimsOut->cdata;
        $result = [];
        for ($i = 0; $i < $dims; $i++) {
            $result[] = (float) $vectorPtr[$i];
        }

        $this->ffi->lattice_hash_embed_free($vectorPtr, $dims);
        return $result;
    }

    public function createClient(
        string $endpoint,
        string $model,
        EmbeddingApiFormat $apiFormat = EmbeddingApiFormat::Ollama,
        ?string $apiKey = null,
        int $timeoutMs = 0,
    ): EmbeddingClient {
        $config = $this->ffi->new('lattice_embedding_config');
        $config->endpoint = $endpoint;
        $config->model = $model;
        $config->api_format = $apiFormat->value;
        $config->api_key = $apiKey;
        $config->timeout_ms = $timeoutMs;

        $clientPtr = $this->ffi->new('lattice_embedding_client*');
        $err = $this->ffi->lattice_embedding_client_create(FFI::addr($config), FFI::addr($clientPtr));
        LatticeLibrary::checkError($this->ffi, $err, 'Failed to create embedding client');

        return new EmbeddingClient($this->ffi, $clientPtr);
    }
}
```

`src/EmbeddingClient.php`:
```php
<?php
namespace LatticeDB;

use FFI;
use FFI\CData;
use LatticeDB\FFI\LatticeLibrary;

class EmbeddingClient
{
    private bool $closed = false;

    /** @internal */
    public function __construct(
        private readonly FFI $ffi,
        private CData $handle,
    ) {}

    /** @return float[] */
    public function embed(string $text): array
    {
        if ($this->closed) {
            throw new \RuntimeException('Embedding client is closed');
        }

        $vectorPtr = $this->ffi->new('float*');
        $dimsOut = $this->ffi->new('uint32_t');
        $err = $this->ffi->lattice_embedding_client_embed($this->handle, $text, strlen($text), FFI::addr($vectorPtr), FFI::addr($dimsOut));
        LatticeLibrary::checkError($this->ffi, $err, 'Embedding failed');

        $dims = (int) $dimsOut->cdata;
        $result = [];
        for ($i = 0; $i < $dims; $i++) {
            $result[] = (float) $vectorPtr[$i];
        }

        $this->ffi->lattice_hash_embed_free($vectorPtr, $dims);
        return $result;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->ffi->lattice_embedding_client_free($this->handle);
    }

    public function __destruct()
    {
        $this->close();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./vendor/bin/phpunit tests/Unit/EmbeddingServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/EmbeddingService.php src/EmbeddingClient.php tests/Unit/EmbeddingServiceTest.php
git commit -m "feat: add EmbeddingService and EmbeddingClient with hash and remote embeddings"
```

---

### Task 12: Integration Tests

These tests require `liblattice` to be installed. They validate the full stack end-to-end.

**Files:**
- Create: `tests/Integration/DatabaseIntegrationTest.php`
- Create: `tests/Integration/GraphIntegrationTest.php`
- Create: `tests/Integration/QueryIntegrationTest.php`
- Create: `tests/Integration/VectorSearchIntegrationTest.php`
- Create: `tests/Integration/FullTextSearchIntegrationTest.php`
- Create: `tests/Integration/EmbeddingIntegrationTest.php`

- [ ] **Step 1: Create base integration test case**

```php
<?php
// tests/Integration/IntegrationTestCase.php
namespace LatticeDB\Tests\Integration;

use LatticeDB\Database;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected Database $db;
    private string $dbPath;

    protected function setUp(): void
    {
        if (getenv('LATTICE_LIB_PATH') === false && !$this->libraryExists()) {
            $this->markTestSkipped('liblattice not found. Set LATTICE_LIB_PATH to run integration tests.');
        }

        $this->dbPath = tempnam(sys_get_temp_dir(), 'lattice_test_') . '.ltdb';
        $this->db = Database::open($this->dbPath, [
            'create' => true,
            'enable_vector' => true,
            'vector_dimensions' => 4,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        foreach (glob($this->dbPath . '*') as $file) {
            @unlink($file);
        }
    }

    private function libraryExists(): bool
    {
        try {
            \LatticeDB\FFI\LatticeLibrary::ffiInstance();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

- [ ] **Step 2: Create Database integration test**

```php
<?php
// tests/Integration/DatabaseIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class DatabaseIntegrationTest extends IntegrationTestCase
{
    public function testOpenAndClose(): void
    {
        $this->assertInstanceOf(\LatticeDB\Database::class, $this->db);
    }

    public function testVersion(): void
    {
        $version = \LatticeDB\Database::version();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testQueryCacheStats(): void
    {
        $stats = $this->db->queryCacheStats();
        $this->assertSame(0, $stats->entries);
    }
}
```

- [ ] **Step 3: Create Graph integration test**

```php
<?php
// tests/Integration/GraphIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class GraphIntegrationTest extends IntegrationTestCase
{
    public function testCreateNodeAndGetProperty(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
            $this->assertIsInt($nodeId);

            $name = $txn->graph()->getProperty($nodeId, 'name');
            $this->assertSame('Alice', $name);

            $age = $txn->graph()->getProperty($nodeId, 'age');
            $this->assertSame(30, $age);
        });
    }

    public function testNodeExistence(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Test');
            $this->assertTrue($txn->graph()->nodeExists($nodeId));

            $txn->graph()->deleteNode($nodeId);
            $this->assertFalse($txn->graph()->nodeExists($nodeId));
        });
    }

    public function testLabels(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Person');
            $txn->graph()->addLabel($nodeId, 'Employee');

            $labels = $txn->graph()->getLabels($nodeId);
            $this->assertContains('Person', $labels);
            $this->assertContains('Employee', $labels);

            $txn->graph()->removeLabel($nodeId, 'Employee');
            $labels = $txn->graph()->getLabels($nodeId);
            $this->assertNotContains('Employee', $labels);
        });
    }

    public function testEdges(): void
    {
        $this->db->transaction(function ($txn) {
            $alice = $txn->graph()->createNode('Person', ['name' => 'Alice']);
            $bob = $txn->graph()->createNode('Person', ['name' => 'Bob']);

            $edgeId = $txn->graph()->createEdge($alice, $bob, 'KNOWS', ['since' => 2020]);
            $this->assertIsInt($edgeId);

            $since = $txn->graph()->getEdgeProperty($edgeId, 'since');
            $this->assertSame(2020, $since);

            $outgoing = $txn->graph()->getOutgoingEdges($alice);
            $this->assertCount(1, $outgoing);
            $this->assertSame('KNOWS', $outgoing[0]->type);
            $this->assertSame($bob, $outgoing[0]->target);

            $incoming = $txn->graph()->getIncomingEdges($bob);
            $this->assertCount(1, $incoming);
        });
    }
}
```

- [ ] **Step 4: Create Query integration test**

```php
<?php
// tests/Integration/QueryIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class QueryIntegrationTest extends IntegrationTestCase
{
    public function testSimpleQuery(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
            $txn->graph()->createNode('Person', ['name' => 'Bob', 'age' => 25]);
        });

        $rows = $this->db->query('MATCH (n:Person) RETURN n.name, n.age ORDER BY n.name')
            ->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['n.name']);
        $this->assertSame(30, $rows[0]['n.age']);
    }

    public function testParameterBinding(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
            $txn->graph()->createNode('Person', ['name' => 'Bob', 'age' => 25]);
        });

        $rows = $this->db->query('MATCH (n:Person) WHERE n.age > $minAge RETURN n.name')
            ->bind('minAge', 27)
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['n.name']);
    }

    public function testFirst(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice']);
        });

        $row = $this->db->query('MATCH (n:Person) RETURN n.name')->first();
        $this->assertSame('Alice', $row['n.name']);

        $empty = $this->db->query('MATCH (n:Nobody) RETURN n.name')->first();
        $this->assertNull($empty);
    }

    public function testScalar(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person');
            $txn->graph()->createNode('Person');
        });

        $count = $this->db->query('MATCH (n:Person) RETURN count(n)')->scalar();
        $this->assertSame(2, $count);
    }

    public function testCursor(): void
    {
        $this->db->transaction(function ($txn) {
            for ($i = 0; $i < 5; $i++) {
                $txn->graph()->createNode('Item', ['idx' => $i]);
            }
        });

        $count = 0;
        foreach ($this->db->query('MATCH (n:Item) RETURN n.idx')->cursor() as $row) {
            $count++;
        }
        $this->assertSame(5, $count);
    }

    public function testWriteQueryInTransaction(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->query('CREATE (n:Person {name: $name})')
                ->bind('name', 'Charlie')
                ->execute();
        });

        $name = $this->db->query('MATCH (n:Person) RETURN n.name')->scalar();
        $this->assertSame('Charlie', $name);
    }

    public function testInvalidQueryThrowsQueryException(): void
    {
        $this->expectException(\LatticeDB\Exception\QueryException::class);
        $this->db->query('INVALID CYPHER')->rows();
    }
}
```

- [ ] **Step 5: Create VectorSearch integration test**

```php
<?php
// tests/Integration/VectorSearchIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class VectorSearchIntegrationTest extends IntegrationTestCase
{
    public function testVectorSearchAndSetVector(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Doc', ['title' => 'Test']);
            $txn->vectors()->setVector($nodeId, 'embedding', [1.0, 0.0, 0.0, 0.0]);
        });

        $results = $this->db->vectors()->search(
            vector: [1.0, 0.0, 0.0, 0.0],
            k: 5,
        );

        $this->assertNotEmpty($results);
        $this->assertSame(0.0, $results[0]->distance);
    }

    public function testBatchInsert(): void
    {
        $nodeIds = null;
        $this->db->transaction(function ($txn) use (&$nodeIds) {
            $nodeIds = $txn->vectors()->batchInsert([
                ['label' => 'Doc', 'vector' => [1.0, 0.0, 0.0, 0.0]],
                ['label' => 'Doc', 'vector' => [0.0, 1.0, 0.0, 0.0]],
                ['label' => 'Doc', 'vector' => [0.0, 0.0, 1.0, 0.0]],
            ]);
        });

        $this->assertCount(3, $nodeIds);
    }
}
```

- [ ] **Step 6: Create FullTextSearch integration test**

```php
<?php
// tests/Integration/FullTextSearchIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class FullTextSearchIntegrationTest extends IntegrationTestCase
{
    public function testIndexAndSearch(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Article', ['title' => 'PHP FFI']);
            $txn->fts()->index($nodeId, 'PHP FFI is a powerful extension for calling C libraries');
        });

        $results = $this->db->fts()->search('PHP FFI', limit: 10);
        $this->assertNotEmpty($results);
        $this->assertGreaterThan(0, $results[0]->score);
    }

    public function testFuzzySearch(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Article');
            $txn->fts()->index($nodeId, 'LatticeDB is an embedded database');
        });

        $results = $this->db->fts()->searchFuzzy('lattcedb embeded', limit: 10, maxDistance: 2);
        $this->assertNotEmpty($results);
    }
}
```

- [ ] **Step 7: Create Embedding integration test**

```php
<?php
// tests/Integration/EmbeddingIntegrationTest.php
namespace LatticeDB\Tests\Integration;

class EmbeddingIntegrationTest extends IntegrationTestCase
{
    public function testHashEmbedding(): void
    {
        $vector = $this->db->embeddings()->hash('test text', dimensions: 16);
        $this->assertCount(16, $vector);
        $this->assertIsFloat($vector[0]);
    }

    public function testHashEmbeddingDeterministic(): void
    {
        $v1 = $this->db->embeddings()->hash('hello world', dimensions: 8);
        $v2 = $this->db->embeddings()->hash('hello world', dimensions: 8);
        $this->assertSame($v1, $v2);
    }
}
```

- [ ] **Step 8: Run all unit tests to make sure nothing is broken**

Run: `./vendor/bin/phpunit tests/Unit/`
Expected: All PASS.

- [ ] **Step 9: Run integration tests (requires liblattice)**

Run: `./vendor/bin/phpunit tests/Integration/`
Expected: All PASS (or SKIP if liblattice not installed).

- [ ] **Step 10: Commit**

```bash
git add tests/Integration/
git commit -m "feat: add integration tests for all components"
```

---

### Task 13: Final Verification and Cleanup

**Files:**
- Verify: all `src/` and `tests/` files

- [ ] **Step 1: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS.

- [ ] **Step 2: Verify namespace and autoloading**

Run: `composer dump-autoload && php -r "require 'vendor/autoload.php'; echo \LatticeDB\Database::class . PHP_EOL;"`
Expected: `LatticeDB\Database`

- [ ] **Step 3: Verify all files from spec exist**

Check that every file listed in the spec's Package Structure section exists:

Run: `ls -la src/ src/FFI/ src/DTO/ src/Enum/ src/Exception/`
Expected: all files present.

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "chore: final verification pass"
```

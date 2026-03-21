# LatticeDB PHP Wrapper — Design Spec

## Overview

A Composer-installable PHP 8.1+ wrapper for [LatticeDB](https://github.com/jeffhajewski/latticedb) — an embedded single-file knowledge graph database with vector search and full-text search. The wrapper uses PHP FFI to call the LatticeDB C API directly, providing a fluent, idiomatic PHP interface without ORM overhead.

## Architecture

```
┌─────────────────────────────────────┐
│          User Code                  │
├─────────────────────────────────────┤
│  Database / Graph / Vector / FTS    │  ← Fluent API
├─────────────────────────────────────┤
│  Transaction                        │  ← Auto-managed transactions
├─────────────────────────────────────┤
│  LatticeLibrary                     │  ← FFI wrapper (1:1 with C API)
├─────────────────────────────────────┤
│  PHP FFI  →  liblattice.so/dylib    │
└─────────────────────────────────────┘
```

**Four layers:**

1. **LatticeLibrary** (`src/FFI/LatticeLibrary.php`) — thin 1:1 mapping of all 55 C API functions to PHP methods. Handles FFI loading, type conversion, pointer management. No business logic. Ships with `lattice.h` for `FFI::cdef()`. Uses `lattice_error_message()` internally to populate exception messages with human-readable C error strings.

2. **Transaction** (`src/Transaction.php`) — wraps `begin/commit/rollback`. Provides access to `graph()`, `vectors()`, `fts()`, `query()` within transaction scope.

3. **Domain APIs** — `Graph`, `VectorSearch`, `FullTextSearch`, `EmbeddingService`, `QueryBuilder` — each responsible for one domain. Constructed with a `LatticeLibrary` instance and a `lattice_database*` handle. Transaction-aware methods also receive a `lattice_txn*` handle. `$db->graph()` and `$txn->graph()` return separate instances configured with/without a transaction context. Methods that require a transaction throw `TransactionException` if called without one.

4. **Database** (`src/Database.php`) — entry point. Opens/closes the database. Provides `transaction()`, `read()`, and convenience access to domain APIs with auto-commit transactions.

### Async readiness

All domain API classes accept dependencies via constructor injection. The `LatticeLibrary` is the sole FFI touchpoint. The architecture does not prevent async adaptation, but true async would require running FFI calls in a thread pool (e.g., Swoole Task workers) and the `LatticeLibrary` interface may need to return promises/futures. The current design minimizes the blast radius of such changes but does not make them zero-cost.

## API Design

### Database & Transaction

```php
// Open (all lattice_open_options fields supported)
$db = Database::open('mydb.ltdb', [
    'create' => true,
    'read_only' => false,
    'cache_size_mb' => 256,
    'page_size' => 4096,
    'enable_vector' => true,
    'vector_dimensions' => 384,
]);

// Auto-managed read-write transaction
$db->transaction(function (Transaction $txn) {
    $txn->graph()->createNode('Person', ['name' => 'Alice']);
});

// Auto-managed read-only transaction
$db->read(function (Transaction $txn) {
    return $txn->graph()->getProperty($id, 'name');
});

// Manual transaction
$txn = $db->beginTransaction();
try {
    // ...
    $txn->commit();
} catch (\Throwable $e) {
    $txn->rollback();
    throw $e;
}

// Queries without explicit transaction — auto read-only
$results = $db->query('MATCH (n:Person) RETURN n.name')->rows();

// Library version
$version = Database::version(); // e.g. "0.3.0"

// Query cache management
$db->clearQueryCache();
$stats = $db->queryCacheStats(); // QueryCacheStats DTO

// Close (also called automatically via __destruct)
$db->close();
```

### Graph

```php
// Nodes
$nodeId = $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
$txn->graph()->addLabel($nodeId, 'Employee');
$txn->graph()->removeLabel($nodeId, 'Employee');
$labels = $txn->graph()->getLabels($nodeId); // ['Person', 'Employee']
$txn->graph()->setProperty($nodeId, 'email', 'alice@test.com');
$name = $txn->graph()->getProperty($nodeId, 'name');
$txn->graph()->nodeExists($nodeId); // bool
$txn->graph()->deleteNode($nodeId);

// Edges
$edgeId = $txn->graph()->createEdge($fromId, $toId, 'KNOWS', ['since' => 2020]);
$txn->graph()->setEdgeProperty($edgeId, 'weight', 1.0);
$txn->graph()->getEdgeProperty($edgeId, 'weight');
$txn->graph()->removeEdgeProperty($edgeId, 'weight');
$txn->graph()->deleteEdge($fromId, $toId, 'KNOWS');

// Traversal — returns EdgeDTO[]
$outgoing = $txn->graph()->getOutgoingEdges($nodeId);
$incoming = $txn->graph()->getIncomingEdges($nodeId);
```

**Implementation notes:**

- `createNode('Label', [...props])` is a convenience method that issues `lattice_node_create` + N `lattice_node_set_property` calls within the current transaction. If any property set fails, the exception propagates and the caller is responsible for rolling back the transaction.
- `createEdge($from, $to, 'TYPE', [...props])` is similarly a compound operation: `lattice_edge_create` + N `lattice_edge_set_property` calls.
- `getLabels()` calls `lattice_node_get_labels`, splits the comma-separated result, frees the C string with `lattice_free_string`, returns `string[]`.
- Edge result iteration calls both `lattice_edge_result_get_id` and `lattice_edge_result_get` per index to fully populate the `EdgeDTO`.
- The C API has no `lattice_node_remove_property` — only edges support property removal. This asymmetry mirrors the C API; to "remove" a node property, set it to null.

### Query

```php
// Full result set
$rows = $db->query('MATCH (n:Person) RETURN n.name, n.age')->rows();

// Parameter binding
$rows = $db->query('MATCH (n:Person) WHERE n.age > $minAge RETURN n.name')
    ->bind('minAge', 25)
    ->rows();

// Vector binding
$rows = $db->query('MATCH (n:Doc) WHERE n.embedding <=> $vec RETURN n.title')
    ->bindVector('vec', [0.1, 0.2, 0.3])
    ->rows();

// Single row
$row = $db->query('...')->first(); // assoc array or null

// Scalar
$count = $db->query('MATCH (n) RETURN count(n)')->scalar();

// Lazy cursor (Traversable, memory-friendly)
foreach ($db->query('...')->cursor() as $row) { }

// Write queries in transaction
$db->transaction(function (Transaction $txn) {
    $txn->query('CREATE (n:Person {name: $name})')
        ->bind('name', 'Charlie')
        ->execute(); // returns void
});
```

### Vector Search

```php
$results = $db->vectors()->search(
    vector: [0.1, 0.2, ...],
    k: 10,
    efSearch: 200,
);
// VectorMatch[] — {nodeId, distance}

$txn->vectors()->setVector($nodeId, 'embedding', [0.1, 0.2, ...]);

$nodeIds = $txn->vectors()->batchInsert([
    ['label' => 'Doc', 'vector' => [0.1, 0.2, ...]],
    ['label' => 'Doc', 'vector' => [0.3, 0.4, ...]],
]);
```

### Full-Text Search

```php
$txn->fts()->index($nodeId, 'Full text content');

$results = $db->fts()->search('query', limit: 20);
// FtsMatch[] — {nodeId, score}

$results = $db->fts()->searchFuzzy('serch qury',
    limit: 20,
    maxDistance: 2,
    minTermLength: 4,
);
```

### Embeddings

```php
// Built-in hash embedding
$vector = $db->embeddings()->hash('text', dimensions: 128);

// Remote embedding client (Ollama/OpenAI)
$client = $db->embeddings()->createClient(
    endpoint: 'http://localhost:11434',
    model: 'nomic-embed-text',
    apiFormat: EmbeddingApiFormat::Ollama,
    apiKey: null,
    timeoutMs: 5000,
);
$vector = $client->embed('text');
$client->close();
```

## Data Types

### DTOs

```php
readonly class EdgeDTO {
    public function __construct(
        public int $id,
        public int $source,
        public int $target,
        public string $type,
    ) {}
}

readonly class VectorMatch {
    public function __construct(
        public int $nodeId,
        public float $distance,
    ) {}
}

readonly class FtsMatch {
    public function __construct(
        public int $nodeId,
        public float $score,
    ) {}
}

readonly class QueryCacheStats {
    public function __construct(
        public int $entries,
        public int $hits,
        public int $misses,
    ) {}
}
```

### Enums

```php
enum QueryStage: int {
    case None = 0;
    case Parse = 1;
    case Semantic = 2;
    case Plan = 3;
    case Execution = 4;
}

enum ErrorCode: int {
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

enum EmbeddingApiFormat: int {
    case Ollama = 0;
    case OpenAI = 1;
}
```

## Error Handling

Exception hierarchy:

```
LatticeException (abstract)
├── ConnectionException        — open/close, version mismatch
├── TransactionException       — begin/commit/rollback, aborted, lock timeout, read-only violations
├── QueryException             — prepare/execute with diagnostics
├── NotFoundException          — node/edge not found
├── AlreadyExistsException     — duplicate node/edge
├── CorruptionException        — data corruption, checksum
└── IOException                — file I/O errors
```

C error code mapping:
- `LATTICE_ERROR_NOT_FOUND` → `NotFoundException`
- `LATTICE_ERROR_ALREADY_EXISTS` → `AlreadyExistsException`
- `LATTICE_ERROR_CORRUPTION`, `LATTICE_ERROR_CHECKSUM` → `CorruptionException`
- `LATTICE_ERROR_IO` → `IOException`
- `LATTICE_ERROR_TXN_ABORTED`, `LATTICE_ERROR_LOCK_TIMEOUT`, `LATTICE_ERROR_READ_ONLY` → `TransactionException`
- `LATTICE_ERROR_VERSION_MISMATCH` → `ConnectionException`
- `LATTICE_ERROR_INVALID_ARG` → `\InvalidArgumentException`
- `LATTICE_ERROR_OUT_OF_MEMORY` → `LatticeException` (unrecoverable, no special subclass needed)
- All others (`ERROR`, `FULL`, `UNSUPPORTED`) → `LatticeException`

All exceptions include the human-readable message from `lattice_error_message()` and the `ErrorCode` enum value.

`QueryException` includes diagnostics: `getStage()`, `getQueryErrorCode()`, `getLine()`, `getColumn()`, `getLength()`.

## Memory Management

The PHP wrapper takes full ownership of C memory lifecycle:

- `Database::__destruct()` calls `lattice_close()`
- `Transaction::commit()`/`rollback()` frees the txn handle; `__destruct()` calls rollback if uncommitted
- `QueryBuilder` frees query and result handles after consumption
- `cursor()` frees result on iterator exhaustion or when the cursor object is destroyed
- `EmbeddingClient::__destruct()` calls `lattice_embedding_client_free()`
- Values from `lattice_node_get_property()` are freed with `lattice_value_free()` after converting to PHP types
- Values from `lattice_result_get()` are borrowed — NOT freed (valid until result_free)
- Labels from `lattice_node_get_labels()` freed with `lattice_free_string()` after converting
- Hash embeddings freed with `lattice_hash_embed_free()` after converting to PHP array
- Edge results freed with `lattice_edge_result_free()` after converting to `EdgeDTO[]`
- Vector results freed with `lattice_vector_result_free()` after converting to `VectorMatch[]`
- FTS results freed with `lattice_fts_result_free()` after converting to `FtsMatch[]`

## PHP Value ↔ C Value Conversion

PHP to `lattice_value`:
- `null` → `LATTICE_VALUE_NULL`
- `bool` → `LATTICE_VALUE_BOOL`
- `int` → `LATTICE_VALUE_INT`
- `float` → `LATTICE_VALUE_FLOAT`
- `string` → `LATTICE_VALUE_STRING` (ptr + len)

`lattice_value` to PHP: reverse of above, plus:
- `LATTICE_VALUE_BYTES` → `string` (binary)
- `LATTICE_VALUE_VECTOR` → `float[]` (PHP array)

## Package Structure

```
latticedb-wrapper/
├── composer.json
├── src/
│   ├── Database.php
│   ├── Transaction.php
│   ├── QueryBuilder.php
│   ├── Graph.php
│   ├── VectorSearch.php
│   ├── FullTextSearch.php
│   ├── EmbeddingService.php
│   ├── EmbeddingClient.php
│   ├── FFI/
│   │   ├── LatticeLibrary.php
│   │   └── lattice.h
│   ├── DTO/
│   │   ├── EdgeDTO.php
│   │   ├── VectorMatch.php
│   │   ├── FtsMatch.php
│   │   └── QueryCacheStats.php
│   ├── Enum/
│   │   ├── QueryStage.php
│   │   ├── ErrorCode.php
│   │   └── EmbeddingApiFormat.php
│   └── Exception/
│       ├── LatticeException.php
│       ├── ConnectionException.php
│       ├── TransactionException.php
│       ├── QueryException.php
│       ├── NotFoundException.php
│       ├── AlreadyExistsException.php
│       ├── CorruptionException.php
│       └── IOException.php
├── tests/
│   ├── Unit/
│   └── Integration/
└── README.md
```

## Library Discovery

The `LatticeLibrary` searches for `liblattice` in this order:
1. `LATTICE_LIB_PATH` environment variable
2. Project-local `lib/` directory
3. Development build `zig-out/lib/`
4. Homebrew paths (macOS): `/opt/homebrew/lib`, `/usr/local/opt/latticedb/lib`
5. System paths: `/usr/local/lib`, `/usr/lib`, `~/.local/lib`

Platform-specific library file names:
- macOS: `liblattice.dylib`
- Linux: `liblattice.so`
- Windows: `lattice.dll` (not actively tested)

## Constraints & Decisions

- **PHP 8.1+** required (enums, readonly, fibers support)
- **No ORM layer** — fluent API with raw Cypher, DTOs for structured results
- **Synchronous** initially, architecture minimizes blast radius of future async adaptation
- **Namespace**: `LatticeDB\`
- **Package name**: `latticedb/latticedb` (Composer)
- **PSR-4** autoloading
- **No external PHP dependencies** — only `ext-ffi`

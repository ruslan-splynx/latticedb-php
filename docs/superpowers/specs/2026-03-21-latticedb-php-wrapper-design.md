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

1. **LatticeLibrary** (`src/FFI/LatticeLibrary.php`) — thin 1:1 mapping of all 55 C API functions to PHP methods. Handles FFI loading, type conversion, pointer management. No business logic. Ships with `lattice.h` for `FFI::cdef()`.

2. **Transaction** (`src/Transaction.php`) — wraps `begin/commit/rollback`. Provides access to `graph()`, `vectors()`, `fts()`, `query()` within transaction scope.

3. **Domain APIs** — `Graph`, `VectorSearch`, `FullTextSearch`, `EmbeddingService`, `QueryBuilder` — each responsible for one domain. Accept a `LatticeLibrary` instance and optional transaction handle.

4. **Database** (`src/Database.php`) — entry point. Opens/closes the database. Provides `transaction()`, `read()`, and convenience access to domain APIs with auto-commit transactions.

### Async readiness

All domain API classes accept their dependencies via constructor injection. The `LatticeLibrary` is the sole FFI touchpoint. This makes it possible to later introduce an async adapter (Fibers, Swoole) at the `LatticeLibrary` level without changing upper layers.

## API Design

### Database & Transaction

```php
// Open
$db = Database::open('mydb.ltdb', [
    'create' => true,
    'cache_size_mb' => 256,
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

// Close (also called automatically via __destruct)
$db->close();
```

### Graph

```php
// Nodes
$nodeId = $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
$txn->graph()->addLabel($nodeId, 'Employee');
$txn->graph()->removeLabel($nodeId, 'Employee');
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
        ->execute();
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
$client = $db->embeddings()->createClient([
    'endpoint' => 'http://localhost:11434',
    'model' => 'nomic-embed-text',
    'api_format' => 'ollama',
]);
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
```

## Error Handling

Exception hierarchy:

```
LatticeException (abstract)
├── ConnectionException        — open/close failures
├── TransactionException       — begin/commit/rollback, aborted, lock timeout
├── QueryException             — prepare/execute with diagnostics
├── NotFoundException          — node/edge not found
├── CorruptionException        — data corruption, checksum
└── IOException                — file I/O errors
```

C error code mapping:
- `LATTICE_ERROR_NOT_FOUND` → `NotFoundException`
- `LATTICE_ERROR_CORRUPTION`, `LATTICE_ERROR_CHECKSUM` → `CorruptionException`
- `LATTICE_ERROR_IO` → `IOException`
- `LATTICE_ERROR_TXN_ABORTED`, `LATTICE_ERROR_LOCK_TIMEOUT` → `TransactionException`
- All others → `LatticeException`

`QueryException` includes diagnostics: `getStage()`, `getErrorCode()`, `getLine()`, `getColumn()`, `getLength()`.

## Memory Management

The PHP wrapper takes full ownership of C memory lifecycle:

- `Database::__destruct()` calls `lattice_close()`
- `Transaction::commit()`/`rollback()` frees the txn handle; `__destruct()` calls rollback if uncommitted
- `QueryBuilder` frees query and result handles after consumption
- `cursor()` frees result on iterator exhaustion or when the cursor object is destroyed
- `EmbeddingClient::__destruct()` calls `lattice_embedding_client_free()`
- Values from `lattice_node_get_property()` are freed after converting to PHP types
- Values from `lattice_result_get()` are borrowed — NOT freed (valid until result_free)
- Labels from `lattice_node_get_labels()` freed with `lattice_free_string()` after converting
- Hash embeddings freed with `lattice_hash_embed_free()` after converting to PHP array

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
│   │   └── FtsMatch.php
│   ├── Enum/
│   │   ├── QueryStage.php
│   │   └── ErrorCode.php
│   └── Exception/
│       ├── LatticeException.php
│       ├── ConnectionException.php
│       ├── TransactionException.php
│       ├── QueryException.php
│       ├── NotFoundException.php
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
3. Homebrew paths (macOS): `/opt/homebrew/lib`, `/usr/local/opt/latticedb/lib`
4. System paths: `/usr/local/lib`, `/usr/lib`

## Constraints & Decisions

- **PHP 8.1+** required (enums, readonly, fibers support)
- **No ORM layer** — fluent API with raw Cypher, DTOs for structured results
- **Synchronous** initially, architecture allows async adapter at LatticeLibrary level
- **Namespace**: `LatticeDB\`
- **Package name**: `latticedb/latticedb` (Composer)
- **PSR-4** autoloading
- **No external PHP dependencies** — only `ext-ffi`

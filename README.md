# latticedb-php

PHP 8.1+ FFI wrapper for [LatticeDB](https://github.com/jeffhajewski/latticedb) — an embedded single-file knowledge graph database with vector search and full-text search.

## Requirements

- PHP 8.1+
- `ext-ffi` enabled
- `liblattice` shared library ([build from source](#building-liblattice))

## Installation

```bash
composer require latticedb/latticedb
```

The package ships with a pre-built `liblattice.so` for Linux x86_64 in the `lib/` directory — it works out of the box, no extra setup needed.

For other platforms or custom builds, set `LATTICE_LIB_PATH`:

```bash
export LATTICE_LIB_PATH=/path/to/liblattice.dylib
```

## Quick Start

```php
use LatticeDB\Database;

$db = Database::open('myapp.ltdb', [
    'create' => true,
    'enable_vector' => true,
    'vector_dimensions' => 128,
]);

// Create nodes and edges
$db->transaction(function ($txn) {
    $alice = $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
    $bob = $txn->graph()->createNode('Person', ['name' => 'Bob', 'age' => 25]);
    $txn->graph()->createEdge($alice, $bob, 'KNOWS', ['since' => 2020]);
});

// Query with Cypher
$rows = $db->query('MATCH (n:Person) WHERE n.age > $minAge RETURN n.name')
    ->bind('minAge', 27)
    ->rows();

$db->close();
```

## API

### Database

```php
// Open with options
$db = Database::open('path.ltdb', [
    'create' => true,          // create if not exists
    'read_only' => false,      // read-only mode
    'cache_size_mb' => 256,    // cache size (default: 100)
    'page_size' => 4096,       // page size (default: 4096)
    'enable_vector' => true,   // enable vector storage
    'vector_dimensions' => 384, // vector dimensions (default: 128)
]);

// Library version
$version = Database::version();

// Query cache
$db->clearQueryCache();
$stats = $db->queryCacheStats(); // ->entries, ->hits, ->misses
```

### Transactions

```php
// Auto-managed read-write
$db->transaction(function ($txn) {
    $txn->graph()->createNode('Person', ['name' => 'Alice']);
});

// Auto-managed read-only
$result = $db->read(function ($txn) {
    return $txn->graph()->getProperty($nodeId, 'name');
});

// Manual
$txn = $db->beginTransaction();
try {
    // ...
    $txn->commit();
} catch (\Throwable $e) {
    $txn->rollback();
    throw $e;
}
```

### Graph

```php
$db->transaction(function ($txn) {
    $g = $txn->graph();

    // Nodes
    $id = $g->createNode('Person', ['name' => 'Alice', 'age' => 30]);
    $g->setProperty($id, 'email', 'alice@example.com');
    $g->getProperty($id, 'name');       // 'Alice'
    $g->nodeExists($id);                // true
    $g->addLabel($id, 'Employee');
    $g->removeLabel($id, 'Employee');
    $g->getLabels($id);                 // ['Person']
    $g->deleteNode($id);

    // Edges
    $edgeId = $g->createEdge($fromId, $toId, 'KNOWS', ['since' => 2020]);
    $g->setEdgeProperty($edgeId, 'weight', 0.9);
    $g->getEdgeProperty($edgeId, 'weight');  // 0.9
    $g->removeEdgeProperty($edgeId, 'weight');
    $g->deleteEdge($fromId, $toId, 'KNOWS');

    // Traversal
    $outgoing = $g->getOutgoingEdges($nodeId); // EdgeDTO[]
    $incoming = $g->getIncomingEdges($nodeId); // EdgeDTO[]
    // EdgeDTO: ->id, ->source, ->target, ->type
});
```

### Cypher Queries

```php
// All rows
$rows = $db->query('MATCH (n:Person) RETURN n.name, n.age')->rows();

// Parameter binding
$rows = $db->query('MATCH (n:Person) WHERE n.age > $min RETURN n.name')
    ->bind('min', 25)
    ->rows();

// First row or null
$row = $db->query('MATCH (n:Person) RETURN n.name')->first();

// Single scalar value
$count = $db->query('MATCH (n:Person) RETURN count(n)')->scalar();

// Lazy cursor (memory-friendly for large results)
foreach ($db->query('MATCH (n) RETURN n.name')->cursor() as $row) {
    // ...
}

// Write queries (in transaction)
$db->transaction(function ($txn) {
    $txn->query('CREATE (n:Person {name: $name})')
        ->bind('name', 'Charlie')
        ->execute();
});

// Vector parameter binding
$rows = $db->query('MATCH (n:Doc) WHERE n.embedding <=> $vec RETURN n.title')
    ->bindVector('vec', [0.1, 0.2, 0.3])
    ->rows();
```

### Vector Search

```php
// Search nearest neighbors
$results = $db->vectors()->search(
    vector: [0.1, 0.2, ...],
    k: 10,
    efSearch: 200,
);
// VectorMatch[]: ->nodeId, ->distance

// Set vector on node (in transaction)
$db->transaction(function ($txn) {
    $txn->vectors()->setVector($nodeId, 'embedding', [0.1, 0.2, ...]);
});

// Batch insert nodes with vectors
$db->transaction(function ($txn) {
    $nodeIds = $txn->vectors()->batchInsert([
        ['label' => 'Doc', 'vector' => [0.1, 0.2, ...]],
        ['label' => 'Doc', 'vector' => [0.3, 0.4, ...]],
    ]);
});
```

### Full-Text Search

```php
// Index text for a node (in transaction)
$db->transaction(function ($txn) {
    $txn->fts()->index($nodeId, 'Full text content to index');
});

// Search
$results = $db->fts()->search('search query', limit: 20);
// FtsMatch[]: ->nodeId, ->score

// Fuzzy search
$results = $db->fts()->searchFuzzy('serch qury',
    limit: 20,
    maxDistance: 2,
    minTermLength: 4,
);
```

### Embeddings

```php
// Built-in hash embedding (no external services)
$vector = $db->embeddings()->hash('some text', dimensions: 128);

// Remote embedding client (Ollama/OpenAI)
use LatticeDB\Enum\EmbeddingApiFormat;

$client = $db->embeddings()->createClient(
    endpoint: 'http://localhost:11434',
    model: 'nomic-embed-text',
    apiFormat: EmbeddingApiFormat::Ollama,
);
$vector = $client->embed('some text');
$client->close();
```

## Error Handling

All errors throw typed exceptions extending `LatticeException`:

```php
use LatticeDB\Exception\NotFoundException;
use LatticeDB\Exception\QueryException;
use LatticeDB\Exception\TransactionException;

try {
    $db->query('INVALID CYPHER')->rows();
} catch (QueryException $e) {
    $e->getMessage();
    $e->getStage();          // QueryStage::Parse
    $e->getQueryErrorCode(); // string|null
    $e->getQueryLine();      // position in Cypher
    $e->getQueryColumn();
}
```

Exception hierarchy:

| Exception | When |
|-----------|------|
| `ConnectionException` | open/close, version mismatch |
| `TransactionException` | commit/rollback, lock timeout, read-only violation |
| `QueryException` | Cypher parse/execution errors (with diagnostics) |
| `NotFoundException` | node/edge not found |
| `AlreadyExistsException` | duplicate node/edge |
| `CorruptionException` | data corruption, checksum failure |
| `IOException` | file I/O errors |

## Building liblattice from Source

Only needed for non-Linux platforms or if you want a newer version. Requires [Zig](https://ziglang.org/):

```bash
git clone https://github.com/jeffhajewski/latticedb.git
cd latticedb
zig build -Doptimize=ReleaseFast
# Output: zig-out/lib/liblattice.dylib (macOS) or liblattice.so (Linux)
```

## Testing

```bash
# Unit tests (no liblattice required)
./vendor/bin/phpunit tests/Unit/

# Integration tests (Linux — uses bundled lib automatically)
./vendor/bin/phpunit tests/Integration/

# Integration tests (macOS/other — specify lib path)
LATTICE_LIB_PATH=/path/to/liblattice.dylib ./vendor/bin/phpunit tests/Integration/
```

## License

MIT

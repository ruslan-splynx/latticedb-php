# LatticeDB C API Reference

Extracted from https://github.com/jeffhajewski/latticedb (v0.3.0)
Source files: `include/lattice.h`, `src/api/c_api.zig`, `book/src/api/c.md`

---

## 1. Opaque Handle Types

All handles are opaque pointers. The caller never sees the internal struct layout.

```c
typedef struct lattice_database lattice_database;
typedef struct lattice_txn lattice_txn;
typedef struct lattice_query lattice_query;
typedef struct lattice_result lattice_result;
typedef struct lattice_vector_result lattice_vector_result;
typedef struct lattice_fts_result lattice_fts_result;
typedef struct lattice_edge_result lattice_edge_result;
typedef struct lattice_embedding_client lattice_embedding_client;
```

## 2. ID Types

```c
typedef uint64_t lattice_node_id;
typedef uint64_t lattice_edge_id;
```

## 3. Error Codes

All functions that can fail return `lattice_error` (an enum). 0 = success, negative = error.

```c
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
```

Human-readable message: `const char* lattice_error_message(lattice_error code);`

## 4. Value Types

```c
typedef enum {
    LATTICE_VALUE_NULL = 0,
    LATTICE_VALUE_BOOL = 1,
    LATTICE_VALUE_INT = 2,
    LATTICE_VALUE_FLOAT = 3,
    LATTICE_VALUE_STRING = 4,
    LATTICE_VALUE_BYTES = 5,
    LATTICE_VALUE_VECTOR = 6,
    LATTICE_VALUE_LIST = 7,    // reserved, returns UNSUPPORTED
    LATTICE_VALUE_MAP = 8      // reserved, returns UNSUPPORTED
} lattice_value_type;
```

### Property Value Struct

```c
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
```

**Note:** Strings are NOT null-terminated in the value struct. They use ptr+len.

## 5. Open Options

```c
typedef struct {
    bool create;              // Create if not exists
    bool read_only;           // Open in read-only mode
    uint32_t cache_size_mb;   // Cache size in MB (default: 100)
    uint32_t page_size;       // Page size in bytes (default: 4096)
    bool enable_vector;       // Enable vector storage for embeddings
    uint16_t vector_dimensions; // Vector dimensions (default: 128)
} lattice_open_options;

#define LATTICE_OPEN_OPTIONS_DEFAULT { false, false, 100, 4096, false, 128 }
```

## 6. Transaction Modes

```c
typedef enum {
    LATTICE_TXN_READ_ONLY = 0,
    LATTICE_TXN_READ_WRITE = 1
} lattice_txn_mode;
```

## 7. Embedding Config

```c
typedef enum {
    LATTICE_EMBEDDING_OLLAMA = 0,
    LATTICE_EMBEDDING_OPENAI = 1
} lattice_embedding_api_format;

typedef struct {
    const char* endpoint;
    const char* model;
    lattice_embedding_api_format api_format;
    const char* api_key;        // NULL for no auth
    uint32_t timeout_ms;        // 0 = default 30s
} lattice_embedding_config;
```

## 8. Batch Insert Struct

```c
typedef struct {
    const char* label;
    const float* vector;
    uint32_t dimensions;
} lattice_node_with_vector;
```

## 9. Query Error Diagnostics

```c
typedef enum {
    LATTICE_QUERY_STAGE_NONE = 0,
    LATTICE_QUERY_STAGE_PARSE = 1,
    LATTICE_QUERY_STAGE_SEMANTIC = 2,
    LATTICE_QUERY_STAGE_PLAN = 3,
    LATTICE_QUERY_STAGE_EXECUTION = 4
} lattice_query_error_stage;
```

---

## Complete Function List (55 functions)

### Database Lifecycle (2)

```c
lattice_error lattice_open(const char* path, const lattice_open_options* options, lattice_database** db_out);
lattice_error lattice_close(lattice_database* db);
```

### Transaction Operations (3)

```c
lattice_error lattice_begin(lattice_database* db, lattice_txn_mode mode, lattice_txn** txn_out);
lattice_error lattice_commit(lattice_txn* txn);
lattice_error lattice_rollback(lattice_txn* txn);
```

### Node Operations (8)

```c
lattice_error lattice_node_create(lattice_txn* txn, const char* label, lattice_node_id* node_out);
lattice_error lattice_node_add_label(lattice_txn* txn, lattice_node_id node_id, const char* label);
lattice_error lattice_node_remove_label(lattice_txn* txn, lattice_node_id node_id, const char* label);
lattice_error lattice_node_delete(lattice_txn* txn, lattice_node_id node_id);
lattice_error lattice_node_set_property(lattice_txn* txn, lattice_node_id node_id, const char* key, const lattice_value* value);
lattice_error lattice_node_get_property(lattice_txn* txn, lattice_node_id node_id, const char* key, lattice_value* value_out);
lattice_error lattice_node_exists(lattice_txn* txn, lattice_node_id node_id, bool* exists_out);
lattice_error lattice_node_get_labels(lattice_txn* txn, lattice_node_id node_id, char** labels_out);
```

### Node Vector (1)

```c
lattice_error lattice_node_set_vector(lattice_txn* txn, lattice_node_id node_id, const char* key, const float* vector, uint32_t dimensions);
```

### Batch Insert (1)

```c
lattice_error lattice_batch_insert(lattice_txn* txn, const lattice_node_with_vector* nodes, uint32_t count, lattice_node_id* node_ids_out, uint32_t* count_out);
```

### Edge Operations (9)

```c
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
```

### Vector Search (4)

```c
lattice_error lattice_vector_search(lattice_database* db, const float* vector, uint32_t dimensions, uint32_t k, uint16_t ef_search, lattice_vector_result** result_out);
uint32_t lattice_vector_result_count(lattice_vector_result* result);
lattice_error lattice_vector_result_get(lattice_vector_result* result, uint32_t index, lattice_node_id* node_id_out, float* distance_out);
void lattice_vector_result_free(lattice_vector_result* result);
```

### Full-Text Search (6)

```c
lattice_error lattice_fts_index(lattice_txn* txn, lattice_node_id node_id, const char* text, size_t text_len);
lattice_error lattice_fts_search(lattice_database* db, const char* query, size_t query_len, uint32_t limit, lattice_fts_result** result_out);
lattice_error lattice_fts_search_fuzzy(lattice_database* db, const char* query, size_t query_len, uint32_t limit, uint32_t max_distance, uint32_t min_term_length, lattice_fts_result** result_out);
uint32_t lattice_fts_result_count(lattice_fts_result* result);
lattice_error lattice_fts_result_get(lattice_fts_result* result, uint32_t index, lattice_node_id* node_id_out, float* score_out);
void lattice_fts_result_free(lattice_fts_result* result);
```

### Query Operations (11)

```c
lattice_error lattice_query_prepare(lattice_database* db, const char* cypher, lattice_query** query_out);
lattice_error lattice_query_bind(lattice_query* query, const char* name, const lattice_value* value);
lattice_error lattice_query_bind_vector(lattice_query* query, const char* name, const float* vector, uint32_t dimensions);
lattice_error lattice_query_execute(lattice_query* query, lattice_txn* txn, lattice_result** result_out);
lattice_query_error_stage lattice_query_last_error_stage(lattice_query* query);
const char* lattice_query_last_error_message(lattice_query* query);
const char* lattice_query_last_error_code(lattice_query* query);  // nullable
bool lattice_query_last_error_has_location(lattice_query* query);
uint32_t lattice_query_last_error_line(lattice_query* query);     // 1-based
uint32_t lattice_query_last_error_column(lattice_query* query);   // 1-based
uint32_t lattice_query_last_error_length(lattice_query* query);
void lattice_query_free(lattice_query* query);
```

### Result Operations (5)

```c
bool lattice_result_next(lattice_result* result);
uint32_t lattice_result_column_count(lattice_result* result);
const char* lattice_result_column_name(lattice_result* result, uint32_t index);
lattice_error lattice_result_get(lattice_result* result, uint32_t index, lattice_value* value_out);
void lattice_result_free(lattice_result* result);
```

### Query Cache (2)

```c
lattice_error lattice_query_cache_clear(lattice_database* db);
lattice_error lattice_query_cache_stats(lattice_database* db, uint32_t* entries_out, uint64_t* hits_out, uint64_t* misses_out);
```

### Embedding Operations (5)

```c
lattice_error lattice_hash_embed(const char* text, size_t text_len, uint16_t dimensions, float** vector_out, uint32_t* dims_out);
void lattice_hash_embed_free(float* vector, uint32_t dimensions);
lattice_error lattice_embedding_client_create(const lattice_embedding_config* config, lattice_embedding_client** client_out);
lattice_error lattice_embedding_client_embed(lattice_embedding_client* client, const char* text, size_t text_len, float** vector_out, uint32_t* dims_out);
void lattice_embedding_client_free(lattice_embedding_client* client);
```

### Memory Management (2)

```c
void lattice_free_string(char* str);
void lattice_value_free(lattice_value* value);
```

### Utility (2)

```c
const char* lattice_version(void);
const char* lattice_error_message(lattice_error code);
```

---

## Memory Management Rules

1. **`lattice_node_get_property()`** - Ownership of heap-backed values (string/bytes/vector) transfers to the caller. Call `lattice_value_free()` after consuming.

2. **`lattice_result_get()`** - Values are BORROWED from the result handle. Valid until `lattice_result_free()`. Do NOT call `lattice_value_free()` on these.

3. **`lattice_node_get_labels()`** - Returns a comma-separated string. Caller must free with `lattice_free_string()`.

4. **`lattice_hash_embed()`** / **`lattice_embedding_client_embed()`** - Returns allocated float array. Free with `lattice_hash_embed_free()`.

5. **Result sets** (`lattice_vector_result`, `lattice_fts_result`, `lattice_edge_result`, `lattice_result`) - Must be freed with their respective `_free()` functions.

6. **`lattice_query`** - Must be freed with `lattice_query_free()` after done with results.

7. **`lattice_database`** - Must be closed with `lattice_close()`.

8. **`lattice_txn`** - Must be committed or rolled back. The commit/rollback frees the handle.

9. **`lattice_embedding_client`** - Must be freed with `lattice_embedding_client_free()`.

10. **`lattice_value_free()`** is safe to call on null/bool/int/float values (no-op).

---

## Usage Patterns

### Basic Lifecycle

```c
// Open
lattice_open_options opts = LATTICE_OPEN_OPTIONS_DEFAULT;
opts.create = true;
lattice_database* db;
lattice_open("mydb.ltdb", &opts, &db);

// Work with transactions
lattice_txn* txn;
lattice_begin(db, LATTICE_TXN_READ_WRITE, &txn);
// ... operations ...
lattice_commit(txn);  // or lattice_rollback(txn)

// Close
lattice_close(db);
```

### Query Pattern (Prepare/Bind/Execute)

```c
lattice_query* query;
lattice_query_prepare(db, "MATCH (n:Person) WHERE n.name = $name RETURN n.name, n.age", &query);

lattice_value val = { .type = LATTICE_VALUE_STRING, .data.string_val = { "Alice", 5 } };
lattice_query_bind(query, "name", &val);

lattice_txn* txn;
lattice_begin(db, LATTICE_TXN_READ_ONLY, &txn);

lattice_result* result;
lattice_query_execute(query, txn, &result);

while (lattice_result_next(result)) {
    uint32_t cols = lattice_result_column_count(result);
    for (uint32_t i = 0; i < cols; i++) {
        const char* col_name = lattice_result_column_name(result, i);
        lattice_value val;
        lattice_result_get(result, i, &val);
        // val.data is borrowed - valid until lattice_result_free()
    }
}

lattice_result_free(result);
lattice_commit(txn);
lattice_query_free(query);
```

### Error Handling Pattern

```c
lattice_error err = lattice_some_function(...);
if (err != LATTICE_OK) {
    const char* msg = lattice_error_message(err);
    fprintf(stderr, "Error: %s\n", msg);
    // handle error...
}
```

### Query Error Diagnostics

```c
lattice_error err = lattice_query_execute(query, txn, &result);
if (err != LATTICE_OK) {
    lattice_query_error_stage stage = lattice_query_last_error_stage(query);
    const char* msg = lattice_query_last_error_message(query);
    const char* code = lattice_query_last_error_code(query);  // may be NULL
    if (lattice_query_last_error_has_location(query)) {
        uint32_t line = lattice_query_last_error_line(query);
        uint32_t col = lattice_query_last_error_column(query);
        uint32_t len = lattice_query_last_error_length(query);
    }
}
```

---

## Library File Names

- macOS: `liblattice.dylib`
- Linux: `liblattice.so`
- Windows: `lattice.dll`

Library search order (from Python bindings):
1. `LATTICE_LIB_PATH` environment variable
2. Package lib directory (bundled)
3. Development build `zig-out/lib/`
4. Homebrew paths (macOS): `/opt/homebrew/lib`, `/usr/local/opt/latticedb/lib`
5. System paths: `/usr/local/lib`, `/usr/lib`, `~/.local/lib`

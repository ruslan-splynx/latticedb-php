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

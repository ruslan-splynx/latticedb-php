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

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

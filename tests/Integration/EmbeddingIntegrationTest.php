<?php

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

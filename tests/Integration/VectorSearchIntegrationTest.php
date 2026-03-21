<?php

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
        $this->assertEqualsWithDelta(0.0, $results[0]->distance, 0.001);
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

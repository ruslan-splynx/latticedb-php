<?php

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

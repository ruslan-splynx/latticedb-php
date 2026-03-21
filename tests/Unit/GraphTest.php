<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\Graph;
use PHPUnit\Framework\TestCase;

class GraphTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(Graph::class);
        $expected = [
            'createNode', 'addLabel', 'removeLabel', 'getLabels',
            'setProperty', 'getProperty', 'nodeExists', 'deleteNode',
            'createEdge', 'setEdgeProperty', 'getEdgeProperty',
            'removeEdgeProperty', 'deleteEdge',
            'getOutgoingEdges', 'getIncomingEdges',
        ];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}

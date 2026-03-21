<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\VectorSearch;
use PHPUnit\Framework\TestCase;

class VectorSearchTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(VectorSearch::class);
        $expected = ['search', 'setVector', 'batchInsert'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}

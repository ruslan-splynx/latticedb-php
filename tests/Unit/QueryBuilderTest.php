<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\QueryBuilder;
use PHPUnit\Framework\TestCase;

class QueryBuilderTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(QueryBuilder::class);
        $expected = ['bind', 'bindVector', 'rows', 'first', 'scalar', 'cursor', 'execute'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }

    public function testBindReturnsSelf(): void
    {
        $ref = new \ReflectionMethod(QueryBuilder::class, 'bind');
        $returnType = $ref->getReturnType();
        $this->assertSame(QueryBuilder::class, $returnType->getName());
    }
}

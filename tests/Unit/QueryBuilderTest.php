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
        $name = $ref->getReturnType()->getName();
        // PHP < 8.5 reports a `self` return type as "self", 8.5+ resolves it to the class name.
        if ($name === 'self') {
            $name = $ref->getDeclaringClass()->getName();
        }
        $this->assertSame(QueryBuilder::class, $name);
    }
}

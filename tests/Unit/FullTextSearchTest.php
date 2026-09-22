<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\FullTextSearch;
use PHPUnit\Framework\TestCase;

class FullTextSearchTest extends TestCase
{
    public function testClassHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(FullTextSearch::class);
        $expected = ['createIndex', 'dropIndex', 'indexExists', 'search', 'searchFuzzy'];
        foreach ($expected as $method) {
            $this->assertTrue($ref->hasMethod($method), "Missing method: {$method}");
        }
    }
}

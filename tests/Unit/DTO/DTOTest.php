<?php

namespace LatticeDB\Tests\Unit\DTO;

use LatticeDB\DTO\EdgeDTO;
use LatticeDB\DTO\VectorMatch;
use LatticeDB\DTO\FtsMatch;
use LatticeDB\DTO\QueryCacheStats;
use PHPUnit\Framework\TestCase;

class DTOTest extends TestCase
{
    public function testEdgeDTOIsReadonly(): void
    {
        $edge = new EdgeDTO(1, 10, 20, 'KNOWS');
        $this->assertSame(1, $edge->id);
        $this->assertSame(10, $edge->source);
        $this->assertSame(20, $edge->target);
        $this->assertSame('KNOWS', $edge->type);
    }

    public function testVectorMatch(): void
    {
        $m = new VectorMatch(42, 0.95);
        $this->assertSame(42, $m->nodeId);
        $this->assertSame(0.95, $m->distance);
    }

    public function testFtsMatch(): void
    {
        $m = new FtsMatch(7, 3.14);
        $this->assertSame(7, $m->nodeId);
        $this->assertSame(3.14, $m->score);
    }

    public function testQueryCacheStats(): void
    {
        $s = new QueryCacheStats(10, 100, 5);
        $this->assertSame(10, $s->entries);
        $this->assertSame(100, $s->hits);
        $this->assertSame(5, $s->misses);
    }
}

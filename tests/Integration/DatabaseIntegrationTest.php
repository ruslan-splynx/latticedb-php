<?php

namespace LatticeDB\Tests\Integration;

use LatticeDB\Database;

class DatabaseIntegrationTest extends IntegrationTestCase
{
    public function testOpenAndClose(): void
    {
        $this->assertInstanceOf(Database::class, $this->db);
    }

    public function testVersion(): void
    {
        $version = Database::version();
        $this->assertNotEmpty($version);
        $this->assertMatchesRegularExpression('/^\d+\.\d+/', $version);
    }

    public function testQueryCacheStats(): void
    {
        $stats = $this->db->queryCacheStats();
        $this->assertSame(0, $stats->entries);
    }
}

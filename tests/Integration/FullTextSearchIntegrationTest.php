<?php

namespace LatticeDB\Tests\Integration;

use LatticeDB\Exception\LatticeException;

class FullTextSearchIntegrationTest extends IntegrationTestCase
{
    public function testIndexAndSearch(): void
    {
        $this->db->fts()->createIndex('Article', 'body');
        $this->assertTrue($this->db->fts()->indexExists('Article', 'body'));

        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Article', [
                'title' => 'PHP FFI',
                'body' => 'PHP FFI is a powerful extension for calling C libraries',
            ]);
        });

        $results = $this->db->fts()->search('Article', 'body', 'PHP FFI', limit: 10);
        $this->assertNotEmpty($results);
        $this->assertGreaterThan(0, $results[0]->score);
    }

    public function testFuzzySearch(): void
    {
        $this->db->fts()->createIndex('Article', 'body');
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Article', ['body' => 'LatticeDB is an embedded database']);
        });

        $results = $this->db->fts()->searchFuzzy('Article', 'body', 'lattcedb embeded', limit: 10, maxDistance: 2);
        $this->assertNotEmpty($results);
    }

    public function testSearchInsideTransactionSeesUncommittedWrites(): void
    {
        $this->db->fts()->createIndex('Article', 'body');
        $found = $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Article', ['body' => 'uncommitted quantum text']);
            return $txn->fts()->search('Article', 'body', 'quantum');
        });

        $this->assertCount(1, $found);
    }

    public function testSearchWithoutIndexThrows(): void
    {
        $this->expectException(LatticeException::class);
        $this->db->fts()->search('Article', 'missing', 'anything');
    }

    public function testDropIndex(): void
    {
        $this->db->fts()->createIndex('Article', 'body');
        $this->db->fts()->dropIndex('Article', 'body');
        $this->assertFalse($this->db->fts()->indexExists('Article', 'body'));
    }
}

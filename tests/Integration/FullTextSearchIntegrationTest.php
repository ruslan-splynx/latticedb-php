<?php

namespace LatticeDB\Tests\Integration;

class FullTextSearchIntegrationTest extends IntegrationTestCase
{
    public function testIndexAndSearch(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Article', ['title' => 'PHP FFI']);
            $txn->fts()->index($nodeId, 'PHP FFI is a powerful extension for calling C libraries');
        });

        $results = $this->db->fts()->search('PHP FFI', limit: 10);
        $this->assertNotEmpty($results);
        $this->assertGreaterThan(0, $results[0]->score);
    }

    public function testFuzzySearch(): void
    {
        $this->db->transaction(function ($txn) {
            $nodeId = $txn->graph()->createNode('Article');
            $txn->fts()->index($nodeId, 'LatticeDB is an embedded database');
        });

        $results = $this->db->fts()->searchFuzzy('lattcedb embeded', limit: 10, maxDistance: 2);
        $this->assertNotEmpty($results);
    }
}

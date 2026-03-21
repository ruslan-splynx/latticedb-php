<?php

namespace LatticeDB\Tests\Integration;

use LatticeDB\Exception\QueryException;

class QueryIntegrationTest extends IntegrationTestCase
{
    public function testSimpleQuery(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
            $txn->graph()->createNode('Person', ['name' => 'Bob', 'age' => 25]);
        });

        $rows = $this->db->query('MATCH (n:Person) RETURN n.name, n.age ORDER BY n.name')
            ->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('Alice', $rows[0]['n.name']);
        $this->assertSame(30, $rows[0]['n.age']);
    }

    public function testParameterBinding(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice', 'age' => 30]);
            $txn->graph()->createNode('Person', ['name' => 'Bob', 'age' => 25]);
        });

        $rows = $this->db->query('MATCH (n:Person) WHERE n.age > $minAge RETURN n.name')
            ->bind('minAge', 27)
            ->rows();

        $this->assertCount(1, $rows);
        $this->assertSame('Alice', $rows[0]['n.name']);
    }

    public function testFirst(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person', ['name' => 'Alice']);
        });

        $row = $this->db->query('MATCH (n:Person) RETURN n.name')->first();
        $this->assertSame('Alice', $row['n.name']);

        $empty = $this->db->query('MATCH (n:Nobody) RETURN n.name')->first();
        $this->assertNull($empty);
    }

    public function testScalar(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->graph()->createNode('Person');
            $txn->graph()->createNode('Person');
        });

        $count = $this->db->query('MATCH (n:Person) RETURN count(n)')->scalar();
        $this->assertSame(2, $count);
    }

    public function testCursor(): void
    {
        $this->db->transaction(function ($txn) {
            for ($i = 0; $i < 5; $i++) {
                $txn->graph()->createNode('Item', ['idx' => $i]);
            }
        });

        $count = 0;
        foreach ($this->db->query('MATCH (n:Item) RETURN n.idx')->cursor() as $row) {
            $count++;
        }
        $this->assertSame(5, $count);
    }

    public function testWriteQueryInTransaction(): void
    {
        $this->db->transaction(function ($txn) {
            $txn->query('CREATE (n:Person {name: $name})')
                ->bind('name', 'Charlie')
                ->execute();
        });

        $name = $this->db->query('MATCH (n:Person) RETURN n.name')->scalar();
        $this->assertSame('Charlie', $name);
    }

    public function testInvalidQueryThrowsQueryException(): void
    {
        $this->expectException(\Throwable::class);
        $this->db->query('INVALID CYPHER')->rows();
    }
}

<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\Transaction;
use PHPUnit\Framework\TestCase;

class TransactionTest extends TestCase
{
    public function testClassHasExpectedInterface(): void
    {
        $ref = new \ReflectionClass(Transaction::class);
        $this->assertTrue($ref->hasMethod('commit'));
        $this->assertTrue($ref->hasMethod('rollback'));
        $this->assertTrue($ref->hasMethod('graph'));
        $this->assertTrue($ref->hasMethod('vectors'));
        $this->assertTrue($ref->hasMethod('fts'));
        $this->assertTrue($ref->hasMethod('query'));
    }
}

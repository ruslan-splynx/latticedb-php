<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\Database;
use PHPUnit\Framework\TestCase;

class DatabaseTest extends TestCase
{
    public function testOpenRequiresPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Database::open('', ['create' => true]);
    }

    public function testOpenRejectsUnknownOptions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown option');
        Database::open('/tmp/test.ltdb', ['unknown_option' => true]);
    }
}

<?php

namespace LatticeDB\Tests\Unit;

use LatticeDB\EmbeddingService;
use LatticeDB\EmbeddingClient;
use PHPUnit\Framework\TestCase;

class EmbeddingServiceTest extends TestCase
{
    public function testServiceHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(EmbeddingService::class);
        $this->assertTrue($ref->hasMethod('hash'));
        $this->assertTrue($ref->hasMethod('createClient'));
    }

    public function testClientHasExpectedMethods(): void
    {
        $ref = new \ReflectionClass(EmbeddingClient::class);
        $this->assertTrue($ref->hasMethod('embed'));
        $this->assertTrue($ref->hasMethod('close'));
    }
}

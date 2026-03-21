<?php

namespace LatticeDB\Tests\Unit\FFI;

use LatticeDB\FFI\LatticeLibrary;
use PHPUnit\Framework\TestCase;

class LatticeLibraryTest extends TestCase
{
    public function testLibraryNameForCurrentPlatform(): void
    {
        $name = LatticeLibrary::libraryFileName();
        $this->assertMatchesRegularExpression('/^(liblattice\.(dylib|so)|lattice\.dll)$/', $name);
    }
}

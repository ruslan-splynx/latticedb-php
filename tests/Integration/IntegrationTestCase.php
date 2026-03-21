<?php

namespace LatticeDB\Tests\Integration;

use LatticeDB\Database;
use LatticeDB\FFI\LatticeLibrary;
use PHPUnit\Framework\TestCase;

abstract class IntegrationTestCase extends TestCase
{
    protected Database $db;
    private string $dbPath;

    protected function setUp(): void
    {
        if (!$this->libraryExists()) {
            $this->markTestSkipped('liblattice not found. Set LATTICE_LIB_PATH to run integration tests.');
        }

        $this->dbPath = tempnam(sys_get_temp_dir(), 'lattice_test_') . '.ltdb';
        $this->db = Database::open($this->dbPath, [
            'create' => true,
            'enable_vector' => true,
            'vector_dimensions' => 4,
        ]);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->close();
        }
        if (isset($this->dbPath)) {
            foreach (glob($this->dbPath . '*') as $file) {
                @unlink($file);
            }
        }
    }

    private function libraryExists(): bool
    {
        try {
            LatticeLibrary::ffiInstance();
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

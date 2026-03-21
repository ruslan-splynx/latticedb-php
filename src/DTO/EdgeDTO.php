<?php

namespace LatticeDB\DTO;

readonly class EdgeDTO
{
    public function __construct(
        public int $id,
        public int $source,
        public int $target,
        public string $type,
    ) {}
}

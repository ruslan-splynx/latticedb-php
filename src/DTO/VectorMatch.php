<?php

namespace LatticeDB\DTO;

readonly class VectorMatch
{
    public function __construct(
        public int $nodeId,
        public float $distance,
    ) {}
}

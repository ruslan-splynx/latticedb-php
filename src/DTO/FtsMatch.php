<?php

namespace LatticeDB\DTO;

readonly class FtsMatch
{
    public function __construct(
        public int $nodeId,
        public float $score,
    ) {}
}

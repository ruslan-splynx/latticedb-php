<?php

namespace LatticeDB\DTO;

readonly class QueryCacheStats
{
    public function __construct(
        public int $entries,
        public int $hits,
        public int $misses,
    ) {}
}

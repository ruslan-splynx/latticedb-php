<?php

namespace LatticeDB\Enum;

enum QueryStage: int
{
    case None = 0;
    case Parse = 1;
    case Semantic = 2;
    case Plan = 3;
    case Execution = 4;
}

<?php

namespace LatticeDB\Enum;

enum EmbeddingApiFormat: int
{
    case Ollama = 0;
    case OpenAI = 1;
}

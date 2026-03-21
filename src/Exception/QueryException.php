<?php

namespace LatticeDB\Exception;

use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;

class QueryException extends LatticeException
{
    private readonly QueryStage $stage;
    private readonly ?string $queryErrorCode;
    private readonly ?int $queryLine;
    private readonly ?int $queryColumn;
    private readonly ?int $queryLength;

    public function __construct(
        string $message,
        ErrorCode $errorCode,
        QueryStage $stage = QueryStage::None,
        ?string $queryErrorCode = null,
        ?int $queryLine = null,
        ?int $queryColumn = null,
        ?int $queryLength = null,
        ?\Throwable $previous = null,
    ) {
        $this->stage = $stage;
        $this->queryErrorCode = $queryErrorCode;
        $this->queryLine = $queryLine;
        $this->queryColumn = $queryColumn;
        $this->queryLength = $queryLength;
        parent::__construct($message, $errorCode, $previous);
    }

    public function getStage(): QueryStage
    {
        return $this->stage;
    }

    public function getQueryErrorCode(): ?string
    {
        return $this->queryErrorCode;
    }

    public function getQueryLine(): ?int
    {
        return $this->queryLine;
    }

    public function getQueryColumn(): ?int
    {
        return $this->queryColumn;
    }

    public function getQueryLength(): ?int
    {
        return $this->queryLength;
    }
}

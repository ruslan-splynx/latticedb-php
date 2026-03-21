<?php

namespace LatticeDB\Tests\Unit\Exception;

use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;
use LatticeDB\Exception\LatticeException;
use LatticeDB\Exception\ConnectionException;
use LatticeDB\Exception\TransactionException;
use LatticeDB\Exception\QueryException;
use LatticeDB\Exception\NotFoundException;
use LatticeDB\Exception\AlreadyExistsException;
use LatticeDB\Exception\CorruptionException;
use LatticeDB\Exception\IOException;
use PHPUnit\Framework\TestCase;

class ExceptionTest extends TestCase
{
    public function testLatticeExceptionCarriesErrorCode(): void
    {
        $e = new ConnectionException('connection failed', ErrorCode::Io);
        $this->assertInstanceOf(LatticeException::class, $e);
        $this->assertSame(ErrorCode::Io, $e->errorCode);
        $this->assertSame('connection failed', $e->getMessage());
    }

    public function testErrorCodeToExceptionMapping(): void
    {
        $this->assertInstanceOf(NotFoundException::class, LatticeException::fromErrorCode(ErrorCode::NotFound));
        $this->assertInstanceOf(AlreadyExistsException::class, LatticeException::fromErrorCode(ErrorCode::AlreadyExists));
        $this->assertInstanceOf(CorruptionException::class, LatticeException::fromErrorCode(ErrorCode::Corruption));
        $this->assertInstanceOf(CorruptionException::class, LatticeException::fromErrorCode(ErrorCode::Checksum));
        $this->assertInstanceOf(IOException::class, LatticeException::fromErrorCode(ErrorCode::Io));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::TxnAborted));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::LockTimeout));
        $this->assertInstanceOf(TransactionException::class, LatticeException::fromErrorCode(ErrorCode::ReadOnly));
        $this->assertInstanceOf(ConnectionException::class, LatticeException::fromErrorCode(ErrorCode::VersionMismatch));
    }

    public function testInvalidArgThrowsInvalidArgumentException(): void
    {
        $e = LatticeException::fromErrorCode(ErrorCode::InvalidArg);
        $this->assertInstanceOf(\InvalidArgumentException::class, $e);
    }

    public function testQueryExceptionDiagnostics(): void
    {
        $e = new QueryException(
            'parse error',
            ErrorCode::Error,
            QueryStage::Parse,
            'SYNTAX_ERROR',
            1,
            5,
            3,
        );
        $this->assertSame(QueryStage::Parse, $e->getStage());
        $this->assertSame('SYNTAX_ERROR', $e->getQueryErrorCode());
        $this->assertSame(1, $e->getQueryLine());
        $this->assertSame(5, $e->getQueryColumn());
        $this->assertSame(3, $e->getQueryLength());
    }
}

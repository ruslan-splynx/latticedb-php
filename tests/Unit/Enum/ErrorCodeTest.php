<?php

namespace LatticeDB\Tests\Unit\Enum;

use LatticeDB\Enum\ErrorCode;
use PHPUnit\Framework\TestCase;

class ErrorCodeTest extends TestCase
{
    public function testOkIsZero(): void
    {
        $this->assertSame(0, ErrorCode::Ok->value);
    }

    public function testNegativeErrorCodes(): void
    {
        $this->assertSame(-4, ErrorCode::NotFound->value);
        $this->assertSame(-7, ErrorCode::TxnAborted->value);
        $this->assertSame(-14, ErrorCode::Unsupported->value);
    }

    public function testFromValueRoundTrip(): void
    {
        $code = ErrorCode::from(-5);
        $this->assertSame(ErrorCode::AlreadyExists, $code);
    }
}

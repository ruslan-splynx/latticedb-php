<?php

namespace LatticeDB\Exception;

use LatticeDB\Enum\ErrorCode;

abstract class LatticeException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly ErrorCode $errorCode,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $errorCode->value, $previous);
    }

    public static function fromErrorCode(ErrorCode $code, string $message = ''): \Throwable
    {
        if ($message === '') {
            $message = "LatticeDB error: {$code->name}";
        }

        return match ($code) {
            ErrorCode::NotFound => new NotFoundException($message, $code),
            ErrorCode::AlreadyExists => new AlreadyExistsException($message, $code),
            ErrorCode::Corruption, ErrorCode::Checksum => new CorruptionException($message, $code),
            ErrorCode::Io => new IOException($message, $code),
            ErrorCode::TxnAborted, ErrorCode::LockTimeout, ErrorCode::ReadOnly => new TransactionException($message, $code),
            ErrorCode::VersionMismatch => new ConnectionException($message, $code),
            ErrorCode::InvalidArg => new \InvalidArgumentException($message, $code->value),
            default => new ConnectionException($message, $code),
        };
    }
}

<?php

namespace LatticeDB\Enum;

enum ErrorCode: int
{
    case Ok = 0;
    case Error = -1;
    case Io = -2;
    case Corruption = -3;
    case NotFound = -4;
    case AlreadyExists = -5;
    case InvalidArg = -6;
    case TxnAborted = -7;
    case LockTimeout = -8;
    case ReadOnly = -9;
    case Full = -10;
    case VersionMismatch = -11;
    case Checksum = -12;
    case OutOfMemory = -13;
    case Unsupported = -14;
}

<?php

namespace LatticeDB\FFI;

use FFI;
use FFI\CData;
use LatticeDB\Enum\ErrorCode;
use LatticeDB\Enum\QueryStage;
use LatticeDB\Exception\LatticeException;
use LatticeDB\Exception\QueryException;

class LatticeLibrary
{
    private static ?FFI $ffi = null;

    public static function libraryFileName(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'liblattice.dylib',
            'Windows' => 'lattice.dll',
            default => 'liblattice.so',
        };
    }

    private static function discoverLibraryPath(): string
    {
        $fileName = self::libraryFileName();

        $envPath = getenv('LATTICE_LIB_PATH');
        if ($envPath !== false && file_exists($envPath)) {
            return $envPath;
        }

        $searchDirs = [
            dirname(__DIR__, 2) . '/lib',
            'zig-out/lib',
        ];

        if (PHP_OS_FAMILY === 'Darwin') {
            $searchDirs[] = '/opt/homebrew/lib';
            $searchDirs[] = '/usr/local/opt/latticedb/lib';
        }

        $searchDirs[] = '/usr/local/lib';
        $searchDirs[] = '/usr/lib';
        $home = getenv('HOME');
        if ($home !== false) {
            $searchDirs[] = $home . '/.local/lib';
        }

        foreach ($searchDirs as $dir) {
            $path = $dir . '/' . $fileName;
            if (file_exists($path)) {
                return $path;
            }
        }

        throw new \RuntimeException(
            "Cannot find {$fileName}. Set LATTICE_LIB_PATH or install liblattice to a standard location."
        );
    }

    public static function ffiInstance(): FFI
    {
        if (self::$ffi === null) {
            $headerPath = __DIR__ . '/lattice.h';
            $libPath = self::discoverLibraryPath();
            self::$ffi = FFI::cdef(file_get_contents($headerPath), $libPath);
        }
        return self::$ffi;
    }

    /** Reset the FFI singleton (for testing). */
    public static function reset(): void
    {
        self::$ffi = null;
    }

    public static function checkError(FFI $ffi, int $code, string $context = ''): void
    {
        if ($code === 0) {
            return;
        }

        $errorCode = ErrorCode::tryFrom($code) ?? ErrorCode::Error;
        $msg = FFI::string($ffi->lattice_error_message($code));
        if ($context !== '') {
            $msg = "{$context}: {$msg}";
        }

        throw LatticeException::fromErrorCode($errorCode, $msg);
    }

    public static function checkQueryError(FFI $ffi, int $code, CData $query, string $context = ''): void
    {
        if ($code === 0) {
            return;
        }

        $errorCode = ErrorCode::tryFrom($code) ?? ErrorCode::Error;
        $stage = QueryStage::tryFrom($ffi->lattice_query_last_error_stage($query)) ?? QueryStage::None;
        $msgPtr = $ffi->lattice_query_last_error_message($query);
        $msg = $msgPtr !== null ? FFI::string($msgPtr) : 'Unknown query error';
        $qCode = $ffi->lattice_query_last_error_code($query);
        $queryErrorCode = $qCode !== null ? FFI::string($qCode) : null;

        $queryLine = null;
        $queryColumn = null;
        $queryLength = null;
        if ($ffi->lattice_query_last_error_has_location($query)) {
            $queryLine = $ffi->lattice_query_last_error_line($query);
            $queryColumn = $ffi->lattice_query_last_error_column($query);
            $queryLength = $ffi->lattice_query_last_error_length($query);
        }

        if ($context !== '') {
            $msg = "{$context}: {$msg}";
        }

        throw new QueryException($msg, $errorCode, $stage, $queryErrorCode, $queryLine, $queryColumn, $queryLength);
    }

    /**
     * Convert a PHP value to a lattice_value CData struct.
     * Returns [$val, $buffers] where $buffers must be kept alive until the FFI call completes.
     * @return array{CData, array<CData>}
     */
    public static function phpToValue(FFI $ffi, mixed $value): array
    {
        $val = $ffi->new('lattice_value');
        $buffers = [];

        if ($value === null) {
            $val->type = 0; // LATTICE_VALUE_NULL
        } elseif (is_bool($value)) {
            $val->type = 1; // LATTICE_VALUE_BOOL
            $val->data->bool_val = $value;
        } elseif (is_int($value)) {
            $val->type = 2; // LATTICE_VALUE_INT
            $val->data->int_val = $value;
        } elseif (is_float($value)) {
            $val->type = 3; // LATTICE_VALUE_FLOAT
            $val->data->float_val = $value;
        } elseif (is_string($value)) {
            $val->type = 4; // LATTICE_VALUE_STRING
            $len = strlen($value);
            // PHP-managed buffer — lives as long as $buffers reference is held
            $buf = $ffi->new('char[' . ($len + 1) . ']');
            FFI::memcpy($buf, $value, $len);
            $val->data->string_val->ptr = $buf;
            $val->data->string_val->len = $len;
            $buffers[] = $buf;
        } else {
            throw new \InvalidArgumentException('Unsupported PHP type for lattice_value: ' . get_debug_type($value));
        }

        return [$val, $buffers];
    }

    public static function valueToPhp(FFI $ffi, CData $val): mixed
    {
        return match ($val->type) {
            0 => null, // NULL
            1 => (bool) $val->data->bool_val, // BOOL
            2 => (int) $val->data->int_val, // INT
            3 => (float) $val->data->float_val, // FLOAT
            4 => FFI::string($val->data->string_val->ptr, $val->data->string_val->len), // STRING
            5 => FFI::string($val->data->bytes_val->ptr, $val->data->bytes_val->len), // BYTES
            6 => self::vectorToPhpArray($val), // VECTOR
            default => throw new \RuntimeException("Unknown lattice_value type: {$val->type}"),
        };
    }

    private static function vectorToPhpArray(CData $val): array
    {
        $dims = $val->data->vector_val->dimensions;
        $result = [];
        for ($i = 0; $i < $dims; $i++) {
            $result[] = $val->data->vector_val->ptr[$i];
        }
        return $result;
    }
}

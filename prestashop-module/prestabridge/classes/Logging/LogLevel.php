<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Logging;

/**
 * Log level constants.
 * Maps to the ENUM values in ps_prestabridge_log table.
 */
class LogLevel
{
    public const DEBUG = 'debug';
    public const INFO = 'info';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    public const CRITICAL = 'critical';

    public const ALL = [
        self::DEBUG,
        self::INFO,
        self::WARNING,
        self::ERROR,
        self::CRITICAL,
    ];
}

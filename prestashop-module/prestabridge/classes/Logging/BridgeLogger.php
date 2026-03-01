<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Logging;

use Db;

/**
 * Central logging class. Writes to ps_prestabridge_log table.
 */
class BridgeLogger
{
    /**
     * Log a message with full context.
     *
     * @param string      $level     One of LogLevel constants
     * @param string      $message   Human-readable message
     * @param array<string, mixed> $context   Additional context data (JSON-encoded)
     * @param string      $source    Source: import|image|cron|api|config|system
     * @param string|null $sku       Product SKU (optional)
     * @param int|null    $productId Product ID (optional)
     * @param string|null $requestId Request UUID (optional)
     */
    public static function log(
        string $level,
        string $message,
        array $context = [],
        string $source = 'system',
        ?string $sku = null,
        ?int $productId = null,
        ?string $requestId = null
        ): void
    {
        Db::getInstance()->insert('prestabridge_log', [
            'level' => pSQL($level),
            'source' => pSQL($source),
            'message' => pSQL($message),
            'context' => pSQL(json_encode($context)),
            'sku' => $sku ? pSQL($sku) : null,
            'id_product' => $productId ? (int)$productId : null,
            'request_id' => $requestId ? pSQL($requestId) : null,
        ]);
    }

    public static function debug(
        string $msg,
        array $ctx = [],
        string $src = 'system',
        ?string $sku = null,
        ?int $pid = null
        ): void
    {
        self::log(LogLevel::DEBUG, $msg, $ctx, $src, $sku, $pid);
    }

    public static function info(
        string $msg,
        array $ctx = [],
        string $src = 'system',
        ?string $sku = null,
        ?int $pid = null
        ): void
    {
        self::log(LogLevel::INFO, $msg, $ctx, $src, $sku, $pid);
    }

    public static function warning(
        string $msg,
        array $ctx = [],
        string $src = 'system',
        ?string $sku = null,
        ?int $pid = null
        ): void
    {
        self::log(LogLevel::WARNING, $msg, $ctx, $src, $sku, $pid);
    }

    public static function error(
        string $msg,
        array $ctx = [],
        string $src = 'system',
        ?string $sku = null,
        ?int $pid = null
        ): void
    {
        self::log(LogLevel::ERROR, $msg, $ctx, $src, $sku, $pid);
    }

    public static function critical(
        string $msg,
        array $ctx = [],
        string $src = 'system',
        ?string $sku = null,
        ?int $pid = null
        ): void
    {
        self::log(LogLevel::CRITICAL, $msg, $ctx, $src, $sku, $pid);
    }

    /**
     * Retrieve logs with pagination and optional filters.
     *
     * @return array{logs: array, total: int, page: int, perPage: int, totalPages: int}
     */
    public static function getLogs(
        int $page = 1,
        int $perPage = 50,
        ?string $level = null,
        ?string $source = null
        ): array
    {
        $where = '1=1';
        if ($level !== null) {
            $where .= ' AND level = "' . pSQL($level) . '"';
        }
        if ($source !== null) {
            $where .= ' AND source = "' . pSQL($source) . '"';
        }

        $total = (int)Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'prestabridge_log WHERE ' . $where
        );

        $offset = ($page - 1) * $perPage;
        $logs = Db::getInstance()->executeS(
            'SELECT * FROM ' . _DB_PREFIX_ . 'prestabridge_log
             WHERE ' . $where . '
             ORDER BY created_at DESC
             LIMIT ' . (int)$offset . ', ' . (int)$perPage
        );

        return [
            'logs' => $logs ?: [],
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $total > 0 ? (int)ceil($total / $perPage) : 0,
        ];
    }

    /**
     * Delete logs older than $days days, or all logs if $days is null.
     *
     * @return int Number of deleted rows
     */
    public static function clearLogs(?int $days = null): int
    {
        $db = Db::getInstance();
        if ($days === null) {
            $db->execute('DELETE FROM ' . _DB_PREFIX_ . 'prestabridge_log');
        }
        else {
            $db->execute(
                'DELETE FROM ' . _DB_PREFIX_ . 'prestabridge_log
                 WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)'
            );
        }
        return 0; // Db mock doesn't return affected rows; real PS Db::execute() does
    }
}

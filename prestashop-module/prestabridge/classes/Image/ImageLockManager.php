<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Image;

use Db;

/**
 * Manages pessimistic locking for the image queue.
 * Handles expired lock cleanup (safety net for crashed CRON runs).
 *
 * Race condition pattern from race-conditions skill: Problem 3.
 */
class ImageLockManager
{
    /**
     * Lock timeout in minutes — same as used in ImageQueueManager::acquireBatch().
     */
    private const LOCK_TIMEOUT_MINUTES = 10;

    /**
     * Releases stale locks older than LOCK_TIMEOUT_MINUTES.
     * Call at the END of every CRON run as a safety net.
     *
     * This protects against records stuck in 'processing' status
     * when a CRON process crashes or times out (OOM, PHP timeout).
     *
     * @return int Number of locks released
     */
    public static function releaseExpiredLocks(): int
    {
        $lockTimeout = date('Y-m-d H:i:s', strtotime('-' . self::LOCK_TIMEOUT_MINUTES . ' minutes'));

        $result = Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = NULL,
                 locked_at = NULL,
                 status = \'pending\'
             WHERE status = \'processing\'
             AND locked_at < \'' . pSQL($lockTimeout) . '\''
        );

        return $result ? (int)Db::getInstance()->getValue('SELECT ROW_COUNT()') : 0;
    }

    /**
     * Checks if a specific record is currently locked.
     *
     * @param int $id Image queue record ID
     * @return bool True if locked (status=processing and not expired)
     */
    public static function isLocked(int $id): bool
    {
        $lockTimeout = date('Y-m-d H:i:s', strtotime('-' . self::LOCK_TIMEOUT_MINUTES . ' minutes'));

        $result = Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'prestabridge_image_queue
             WHERE id_image_queue = ' . (int)$id . '
             AND status = \'processing\'
             AND locked_at >= \'' . pSQL($lockTimeout) . '\''
        );

        return (bool)$result;
    }

    /**
     * Forcibly releases the lock for a specific record.
     * Use only when explicitly unlocking after processing.
     *
     * @param int    $id        Image queue record ID
     * @param string $newStatus Status to set after unlock ('pending' or 'failed')
     */
    public static function releaseLock(int $id, string $newStatus = 'pending'): void
    {
        $allowed = ['pending', 'failed'];
        $status = in_array($newStatus, $allowed, true) ? $newStatus : 'pending';

        Db::getInstance()->execute(
            'UPDATE ' . _DB_PREFIX_ . 'prestabridge_image_queue
             SET lock_token = NULL,
                 locked_at = NULL,
                 status = \'' . pSQL($status) . '\'
             WHERE id_image_queue = ' . (int)$id
        );
    }
}

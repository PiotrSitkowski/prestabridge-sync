<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Image\ImageQueueManager;
use Db;

class ImageQueueManagerTest extends TestCase
{
    protected function setUp(): void
    {
        Db::reset();
    }

    /**
     * P-IM1: enqueues 3 images correctly
     */
    public function testEnqueueThreeImagesCorrectly(): void
    {
        // Arrange
        $productId = 42;
        $sku = 'SKU-001';
        $images = [
            'https://example.com/img1.jpg',
            'https://example.com/img2.jpg',
            'https://example.com/img3.jpg',
        ];

        // Act
        $result = ImageQueueManager::enqueue($productId, $sku, $images);

        // Assert
        $db = Db::getInstance();
        $this->assertSame(3, $result);
        $this->assertCount(3, $db->insertCalls);

        // First image should be cover (position 0)
        $this->assertSame(1, $db->insertCalls[0]['data']['is_cover']);
        $this->assertSame(0, $db->insertCalls[0]['data']['position']);

        // Second image should NOT be cover (position 1)
        $this->assertSame(0, $db->insertCalls[1]['data']['is_cover']);
        $this->assertSame(1, $db->insertCalls[1]['data']['position']);

        // Third image should NOT be cover (position 2)
        $this->assertSame(0, $db->insertCalls[2]['data']['is_cover']);
        $this->assertSame(2, $db->insertCalls[2]['data']['position']);
    }

    /**
     * P-IM2: returns 0 for empty images
     */
    public function testEnqueueReturnsZeroForEmptyImages(): void
    {
        // Act
        $result = ImageQueueManager::enqueue(42, 'SKU-001', []);

        // Assert
        $db = Db::getInstance();
        $this->assertSame(0, $result);
        $this->assertCount(0, $db->insertCalls);
    }

    /**
     * P-IM3: acquireBatch locks correct number of records
     */
    public function testAcquireBatchLocksCorrectNumberOfRecords(): void
    {
        // Arrange
        $db = Db::getInstance();
        $db->setReturnRows([
            [
                'id_image_queue' => 1,
                'id_product' => 42,
                'image_url' => 'https://example.com/img1.jpg',
                'position' => 0,
                'is_cover' => 1,
                'status' => 'processing',
            ],
        ]);

        // Act
        $result = ImageQueueManager::acquireBatch(5);

        // Assert
        // Should have 2 execute calls: 1 for releasing expired locks, 1 for locking batch
        $this->assertCount(2, $db->executeCalls);

        // Second call should contain LIMIT 5
        $this->assertStringContainsString('LIMIT 5', $db->executeCalls[1]);

        // Second call should set lock_token and locked_at
        $this->assertStringContainsString('lock_token', $db->executeCalls[1]);
        $this->assertStringContainsString('locked_at', $db->executeCalls[1]);

        // Result should be from executeS (our mocked return rows)
        $this->assertCount(1, $result);
    }

    /**
     * P-IM4: acquireBatch releases expired locks first
     */
    public function testAcquireBatchReleasesExpiredLocksFirst(): void
    {
        // Arrange
        $db = Db::getInstance();
        $db->setReturnRows([]);

        // Act
        ImageQueueManager::acquireBatch(5);

        // Assert
        // First execute call should release expired locks (safety net)
        $this->assertGreaterThanOrEqual(2, count($db->executeCalls));
        $firstQuery = $db->executeCalls[0];
        $this->assertStringContainsString('lock_token = NULL', $firstQuery);
        $this->assertStringContainsString('status = "pending"', $firstQuery);
        $this->assertStringContainsString('status = "processing"', $firstQuery);
    }

    /**
     * P-IM5: markCompleted sets status and clears lock
     */
    public function testMarkCompletedSetsStatusAndClearsLock(): void
    {
        // Act
        ImageQueueManager::markCompleted(99);

        // Assert
        $db = Db::getInstance();
        $this->assertCount(1, $db->updateCalls);
        $call = $db->updateCalls[0];
        $this->assertSame('prestabridge_image_queue', $call['table']);
        $this->assertSame('completed', $call['data']['status']);
        $this->assertNull($call['data']['lock_token']);
        $this->assertNull($call['data']['locked_at']);
        $this->assertStringContainsString('99', $call['where']);
    }

    /**
     * P-IM6: markFailed sets status to failed on last attempt
     */
    public function testMarkFailedSetsStatusToFailedOnLastAttempt(): void
    {
        // Act
        ImageQueueManager::markFailed(99, 'Download timeout');

        // Assert
        $db = Db::getInstance();
        $this->assertCount(1, $db->executeCalls);
        $query = $db->executeCalls[0];

        // Query should use CASE WHEN to set 'failed' when attempts + 1 >= max_attempts
        $this->assertStringContainsString('CASE WHEN attempts + 1 >= max_attempts THEN "failed"', $query);
        $this->assertStringContainsString('attempts = attempts + 1', $query);
        $this->assertStringContainsString('lock_token = NULL', $query);
        $this->assertStringContainsString('99', $query);
    }

    /**
     * P-IM7: markFailed sets status back to pending if retries left
     */
    public function testMarkFailedSetsStatusToPendingIfRetriesLeft(): void
    {
        // Act
        ImageQueueManager::markFailed(99, 'Temporary error');

        // Assert
        $db = Db::getInstance();
        $this->assertCount(1, $db->executeCalls);
        $query = $db->executeCalls[0];

        // Query should contain ELSE "pending" for when retries are left
        $this->assertStringContainsString('ELSE "pending"', $query);
        $this->assertStringContainsString('locked_at = NULL', $query);
    }
}

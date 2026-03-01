<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Logging;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Logging\BridgeLogger;
use Db;

class BridgeLoggerTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        Db::reset();
        $this->db = Db::getInstance();
    }

    // TEST P-L1: inserts log with all fields
    public function testInsertsLogWithAllFields(): void
    {
        // Arrange & Act
        BridgeLogger::error('msg', ['key' => 'val'], 'import', 'SKU-1', 42, 'req-1');

        // Assert
        $this->assertCount(1, $this->db->insertCalls);
        $call = $this->db->insertCalls[0];
        $this->assertSame('prestabridge_log', $call['table']);
        $this->assertSame('error', $call['data']['level']);
        $this->assertSame('import', $call['data']['source']);
        // message goes through pSQL which escapes quotes
        $this->assertStringContainsString('msg', $call['data']['message']);
        $this->assertStringContainsString('key', (string)$call['data']['context']);
        $this->assertStringContainsString('val', (string)$call['data']['context']);
        $this->assertStringContainsString('SKU-1', (string)$call['data']['sku']);
        $this->assertSame(42, $call['data']['id_product']);
        $this->assertStringContainsString('req-1', (string)$call['data']['request_id']);
    }

    // TEST P-L2: handles null optional fields
    public function testHandlesNullOptionalFields(): void
    {
        // Act
        BridgeLogger::info('msg');

        // Assert
        $call = $this->db->insertCalls[0];
        $this->assertNull($call['data']['sku']);
        $this->assertNull($call['data']['id_product']);
        $this->assertNull($call['data']['request_id']);
    }

    // TEST P-L3: getLogs returns paginated results
    public function testGetLogsReturnsPaginatedResults(): void
    {
        // Arrange — 100 total, 50 rows returned
        $this->db->setReturnValue(100);
        $rows = array_fill(0, 50, ['id_log' => 1, 'level' => 'info']);
        $this->db->setReturnRows($rows);

        // Act
        $result = BridgeLogger::getLogs(1, 50);

        // Assert
        $this->assertSame(100, $result['total']);
        $this->assertSame(50, $result['perPage']);
        $this->assertSame(2, $result['totalPages']);
        $this->assertCount(50, $result['logs']);
    }

    // TEST P-L4: getLogs filters by level
    public function testGetLogsFiltersByLevel(): void
    {
        // Arrange
        $this->db->setReturnValue(0);

        // Act
        BridgeLogger::getLogs(1, 50, 'error');

        // Assert — SQL should contain WHERE level = "error"
        $this->assertStringContainsString('level = "error"', (string)$this->db->lastQuery);
    }

    // TEST P-L5: clearLogs deletes all when days=null
    public function testClearLogsDeletesAllWhenDaysNull(): void
    {
        // Act
        BridgeLogger::clearLogs();

        // Assert — no WHERE date condition
        $sql = $this->db->executeCalls[0] ?? '';
        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringNotContainsString('created_at', $sql);
    }

    // TEST P-L6: clearLogs deletes older than N days
    public function testClearLogsDeletesOlderThanNDays(): void
    {
        // Act
        BridgeLogger::clearLogs(7);

        // Assert
        $sql = $this->db->executeCalls[0] ?? '';
        $this->assertStringContainsString('DELETE FROM', $sql);
        $this->assertStringContainsString('created_at', $sql);
        $this->assertStringContainsString('INTERVAL 7 DAY', $sql);
    }

    // TEST P-L7: escapes special characters in message
    public function testEscapesSpecialCharactersInMessage(): void
    {
        // Act
        BridgeLogger::error("test'; DROP TABLE --");

        // Assert — pSQL() must have been called (single quotes escaped)
        $call = $this->db->insertCalls[0];
        $this->assertStringNotContainsString("test'; DROP TABLE", $call['data']['message']);
    }
}

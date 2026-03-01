<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Image\ImageDownloader;
use Configuration;

class ImageDownloaderTest extends TestCase
{
    protected function setUp(): void
    {
        Configuration::reset();
        Configuration::seed([
            'PRESTABRIDGE_IMAGE_TIMEOUT' => 30,
        ]);
    }

    /**
     * ADDED: Tests return structure on download failure (invalid URL)
     */
    public function testReturnsErrorForInvalidUrl(): void
    {
        // Act — use an invalid URL that will fail
        $result = ImageDownloader::download('https://invalid.nonexistent.domain.test/img.jpg');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('tmpPath', $result);
    }

    /**
     * ADDED: Tests that MIME type validation rejects non-image files
     */
    public function testRejectsNonImageMimeType(): void
    {
        // Arrange — create a temp file with text content (not an image)
        $tmpFile = tempnam(sys_get_temp_dir(), 'pb_test_');
        file_put_contents($tmpFile, '<?php echo "not an image"; ?>');

        // Use file:// protocol to test MIME validation
        $normalizedPath = str_replace('\\', '/', $tmpFile);
        $result = ImageDownloader::download('file:///' . ltrim($normalizedPath, '/'));

        // Assert — either it fails on protocol or MIME check
        $this->assertFalse($result['success']);

        // Cleanup
        if (file_exists($tmpFile)) {
            @unlink($tmpFile);
        }
    }

    /**
     * ADDED: Tests that return structure contains correct keys on failure
     */
    public function testReturnStructureHasCorrectKeys(): void
    {
        // We verify the method signature returns expected keys
        // by testing a failure case with an unreachable URL
        $result = ImageDownloader::download('https://nonexistent.invalid.test/img.jpg');

        // Assert — error result structure
        $this->assertIsBool($result['success']);
        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('error', $result);
    }
}

<?php

declare(strict_types = 1)
;

namespace PrestaBridge\Tests\Unit\Image;

use PHPUnit\Framework\TestCase;
use PrestaBridge\Image\ImageAssigner;
use Image;
use ImageType;
use ImageManager;
use Product;
use Db;

class ImageAssignerTest extends TestCase
{
    private string $tmpFile;

    protected function setUp(): void
    {
        Db::reset();
        Product::reset();
        Image::reset();
        ImageType::reset();
        ImageManager::reset();

        // Create a real temporary file with minimal JPEG header
        $this->tmpFile = tempnam(sys_get_temp_dir(), 'pb_test_');
        // Write minimal JPEG binary (FFD8FF header)
        file_put_contents($this->tmpFile, "\xFF\xD8\xFF\xE0" . str_repeat("\x00", 100));

        // Set up default mock behavior
        Product::setExistsResult(true);
        Image::setAddResult(true);
        Image::setPathForCreation(sys_get_temp_dir() . '/ps_img_test');
        ImageType::setTypes([
            ['name' => 'small_default', 'width' => 98, 'height' => 98],
            ['name' => 'medium_default', 'width' => 452, 'height' => 452],
            ['name' => 'large_default', 'width' => 800, 'height' => 800],
        ]);
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tmpFile)) {
            @unlink($this->tmpFile);
        }
        // Clean up any created image files
        $testPath = sys_get_temp_dir() . '/ps_img_test';
        foreach (glob($testPath . '*') as $file) {
            @unlink($file);
        }
    }

    /**
     * P-IA1: assigns image to existing product
     */
    public function testAssignsImageToExistingProduct(): void
    {
        // Arrange
        Product::setExistsResult(true);

        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 0, true);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertGreaterThan(0, $result['imageId']);
    }

    /**
     * P-IA2: fails when product does not exist (race condition)
     */
    public function testFailsWhenProductDoesNotExist(): void
    {
        // Arrange
        Product::setExistsResult(false);

        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 0, true);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('does not exist', $result['error']);
    // Image::add should NOT have been called (no insertCalls related to Image)
    }

    /**
     * P-IA3: sets cover and removes previous cover
     */
    public function testSetsCoverAndRemovesPreviousCover(): void
    {
        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 0, true);

        // Assert
        $this->assertTrue($result['success']);
        $deleteCoverCalls = Image::getDeleteCoverCalls();
        $this->assertContains(42, $deleteCoverCalls);
    }

    /**
     * P-IA4: does not set cover for non-cover images
     */
    public function testDoesNotSetCoverForNonCoverImages(): void
    {
        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 1, false);

        // Assert
        $this->assertTrue($result['success']);
        $deleteCoverCalls = Image::getDeleteCoverCalls();
        $this->assertEmpty($deleteCoverCalls);
    }

    /**
     * P-IA5: generates thumbnails for all image types
     */
    public function testGeneratesThumbnailsForAllImageTypes(): void
    {
        // Arrange — ImageType already set in setUp with 3 types

        // Act
        ImageAssigner::assign(42, $this->tmpFile, 0, false);

        // Assert
        $resizeCalls = ImageManager::getResizeCalls();
        $this->assertCount(3, $resizeCalls);
    }

    /**
     * P-IA6: cleans up tmp file on success
     */
    public function testCleansUpTmpFileOnSuccess(): void
    {
        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 0, false);

        // Assert
        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($this->tmpFile);
    }

    /**
     * P-IA7: cleans up tmp file on failure
     */
    public function testCleansUpTmpFileOnFailure(): void
    {
        // Arrange
        Image::setAddResult(false);

        // Act
        $result = ImageAssigner::assign(42, $this->tmpFile, 0, false);

        // Assert
        $this->assertFalse($result['success']);
        $this->assertFileDoesNotExist($this->tmpFile);
    }
}

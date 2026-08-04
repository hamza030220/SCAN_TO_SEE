<?php

namespace App\Tests\Service;

use App\Service\ImageUploadService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageUploadServiceTest extends TestCase
{
    private ImageUploadService $service;
    private string $testProjectDir;
    private string $testPublicDir;

    protected function setUp(): void
    {
        // Create a temporary directory for testing
        $this->testProjectDir = sys_get_temp_dir() . '/image_upload_test_' . uniqid();
        $this->testPublicDir = $this->testProjectDir . '/public';

        mkdir($this->testPublicDir, 0775, true);

        $this->service = new ImageUploadService($this->testProjectDir);
    }

    protected function tearDown(): void
    {
        // Clean up test directories
        $this->recursiveDelete($this->testProjectDir);
    }

    // ── Validation Tests ─────────────────────────────────────────────────────

    public function testValidateRejectsInvalidMimeType(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image must be JPG, PNG, or WEBP.');

        $file = $this->createMockUploadedFile('test.gif', 'image/gif', 1000);
        $this->service->uploadBusinessLogo($file, 'Test Business');
    }

    public function testValidateRejectsFileTooLarge(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image must be under 2 MB.');

        // 3 MB file (exceeds 2 MB limit)
        $file = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 3_145_728);
        $this->service->uploadBusinessLogo($file, 'Test Business');
    }

    public function testValidateAcceptsJpeg(): void
    {
        $file = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 500_000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Test Business');

        $this->assertStringContainsString('image/business/', $path);
        $this->assertStringContainsString('.jpg', $path);
    }

    public function testValidateAcceptsPng(): void
    {
        $file = $this->createMockUploadedFile('test.png', 'image/png', 500_000, 'png');
        
        $path = $this->service->uploadItemImage($file, 'Test Item');

        $this->assertStringContainsString('image/items/', $path);
        $this->assertStringContainsString('.png', $path);
    }

    public function testValidateAcceptsWebp(): void
    {
        $file = $this->createMockUploadedFile('test.webp', 'image/webp', 500_000, 'webp');
        
        $path = $this->service->uploadMenuBg($file, 'test-menu');

        $this->assertStringContainsString('image/menu/', $path);
        $this->assertStringContainsString('.webp', $path);
    }

    public function testValidateAcceptsExactly2MB(): void
    {
        // Exactly 2 MB should be accepted
        $file = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 2_097_152, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Test');

        $this->assertStringContainsString('image/business/', $path);
    }

    public function testValidateRejectsOver2MBByOneByte(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Image must be under 2 MB.');

        // 2 MB + 1 byte should be rejected
        $file = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 2_097_153);
        $this->service->uploadBusinessLogo($file, 'Test');
    }

    // ── Business Logo Upload Tests ───────────────────────────────────────────

    public function testUploadBusinessLogoCreatesCorrectPath(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Café Arabica');

        $this->assertStringStartsWith('image/business/logo_', $path);
        // The slugify function transliterates accented characters, exact output may vary by system
        $this->assertStringContainsString('arabica', $path);
        $this->assertStringEndsWith('.jpg', $path);
    }

    public function testUploadBusinessLogoCreatesDirectory(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $this->service->uploadBusinessLogo($file, 'Test Business');

        $this->assertDirectoryExists($this->testPublicDir . '/image/business');
    }

    public function testUploadBusinessLogoHandlesDuplicateNames(): void
    {
        $file1 = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        $file2 = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path1 = $this->service->uploadBusinessLogo($file1, 'My Restaurant');
        $path2 = $this->service->uploadBusinessLogo($file2, 'My Restaurant');

        $this->assertNotEquals($path1, $path2);
        $this->assertStringContainsString('logo_my_restaurant.jpg', $path1);
        $this->assertStringContainsString('logo_my_restaurant_1.jpg', $path2);
    }

    // ── Item Image Upload Tests ──────────────────────────────────────────────

    public function testUploadItemImageCreatesCorrectPath(): void
    {
        $file = $this->createMockUploadedFile('item.png', 'image/png', 1000, 'png');
        
        $path = $this->service->uploadItemImage($file, 'Caesar Salad');

        $this->assertStringStartsWith('image/items/image_', $path);
        $this->assertStringContainsString('caesar_salad', $path);
        $this->assertStringEndsWith('.png', $path);
    }

    public function testUploadItemImageCreatesDirectory(): void
    {
        $file = $this->createMockUploadedFile('item.jpg', 'image/jpeg', 1000, 'jpg');
        
        $this->service->uploadItemImage($file, 'Espresso');

        $this->assertDirectoryExists($this->testPublicDir . '/image/items');
    }

    public function testUploadItemImageHandlesDuplicateNames(): void
    {
        $file1 = $this->createMockUploadedFile('item.jpg', 'image/jpeg', 1000, 'jpg');
        $file2 = $this->createMockUploadedFile('item.jpg', 'image/jpeg', 1000, 'jpg');
        $file3 = $this->createMockUploadedFile('item.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path1 = $this->service->uploadItemImage($file1, 'Burger');
        $path2 = $this->service->uploadItemImage($file2, 'Burger');
        $path3 = $this->service->uploadItemImage($file3, 'Burger');

        $this->assertStringContainsString('image_burger.jpg', $path1);
        $this->assertStringContainsString('image_burger_1.jpg', $path2);
        $this->assertStringContainsString('image_burger_2.jpg', $path3);
    }

    // ── Menu Background Upload Tests ─────────────────────────────────────────

    public function testUploadMenuBgCreatesCorrectPath(): void
    {
        $file = $this->createMockUploadedFile('bg.webp', 'image/webp', 1000, 'webp');
        
        $path = $this->service->uploadMenuBg($file, 'summer-menu-2024');

        $this->assertStringStartsWith('image/menu/bg_', $path);
        $this->assertStringContainsString('summer_menu_2024', $path);
        $this->assertStringEndsWith('.webp', $path);
    }

    public function testUploadMenuBgCreatesDirectory(): void
    {
        $file = $this->createMockUploadedFile('bg.jpg', 'image/jpeg', 1000, 'jpg');
        
        $this->service->uploadMenuBg($file, 'test-menu');

        $this->assertDirectoryExists($this->testPublicDir . '/image/menu');
    }

    public function testUploadHeroImageUsesIsolatedHeroDirectory(): void
    {
        $file = $this->createMockUploadedFile('offer.webp', 'image/webp', 1000, 'webp');

        $path = $this->service->uploadHeroImage($file, 'summer-menu');

        $this->assertStringStartsWith('image/hero/hero_summer_menu', $path);
        $this->assertStringEndsWith('.webp', $path);
        $this->assertDirectoryExists($this->testPublicDir . '/image/hero');
    }

    // ── Slugify Tests ────────────────────────────────────────────────────────

    public function testSlugifyHandlesAccentedCharacters(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Café Napoléon');

        // The slugify function transliterates accented characters
        $this->assertStringContainsString('napol', $path);
        $this->assertStringNotContainsString('é', $path);
    }

    public function testSlugifyHandlesSpecialCharacters(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Joe\'s Diner & Grill!!!');

        $this->assertStringContainsString('joe_s_diner_grill', $path);
        $this->assertStringNotContainsString('&', $path);
        $this->assertStringNotContainsString('!', $path);
    }

    public function testSlugifyHandlesSpaces(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'The Best Restaurant');

        $this->assertStringContainsString('the_best_restaurant', $path);
    }

    public function testSlugifyHandlesMultipleSpaces(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'My    Restaurant    Name');

        // Multiple spaces should become single underscore
        $this->assertStringContainsString('my_restaurant_name', $path);
        $this->assertStringNotContainsString('__', $path);
    }

    public function testSlugifyHandlesUppercase(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'RESTAURANT NAME');

        $this->assertStringContainsString('restaurant_name', $path);
        $this->assertStringNotContainsString('RESTAURANT', $path);
    }

    public function testSlugifyHandlesEmptyString(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, '');

        $this->assertStringContainsString('logo_file.jpg', $path);
    }

    public function testSlugifyHandlesOnlySpecialCharacters(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, '!@#$%^&*()');

        $this->assertStringContainsString('logo_file.jpg', $path);
    }

    public function testSlugifyHandlesUnicodeCharacters(): void
    {
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'レストラン');

        // Should produce a valid filename
        $this->assertStringStartsWith('image/business/logo_', $path);
        $this->assertStringEndsWith('.jpg', $path);
    }

    // ── Delete Tests ─────────────────────────────────────────────────────────

    public function testDeleteRemovesExistingFile(): void
    {
        // First upload a file
        $file = $this->createMockUploadedFile('logo.jpg', 'image/jpeg', 1000, 'jpg');
        $path = $this->service->uploadBusinessLogo($file, 'Test Business');
        
        $fullPath = $this->testPublicDir . '/' . $path;
        $this->assertFileExists($fullPath);

        // Now delete it
        $this->service->delete($path);

        $this->assertFileDoesNotExist($fullPath);
    }

    public function testDeleteIgnoresNonExistentFile(): void
    {
        // Should not throw exception for missing file
        $this->service->delete('image/business/nonexistent.jpg');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testDeleteIgnoresNullPath(): void
    {
        // Should handle null gracefully
        $this->service->delete(null);

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    public function testDeleteIgnoresEmptyPath(): void
    {
        // Should handle empty string gracefully
        $this->service->delete('');

        $this->assertTrue(true); // If we get here, no exception was thrown
    }

    // ── Edge Cases ───────────────────────────────────────────────────────────

    public function testUploadHandlesDifferentExtensions(): void
    {
        $jpegFile = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, 'jpg');
        $pngFile = $this->createMockUploadedFile('test.png', 'image/png', 1000, 'png');
        $webpFile = $this->createMockUploadedFile('test.webp', 'image/webp', 1000, 'webp');

        $jpegPath = $this->service->uploadBusinessLogo($jpegFile, 'Test1');
        $pngPath = $this->service->uploadBusinessLogo($pngFile, 'Test2');
        $webpPath = $this->service->uploadBusinessLogo($webpFile, 'Test3');

        $this->assertStringEndsWith('.jpg', $jpegPath);
        $this->assertStringEndsWith('.png', $pngPath);
        $this->assertStringEndsWith('.webp', $webpPath);
    }

    public function testUploadCreatesNestedDirectories(): void
    {
        // Test all three directory types
        $file1 = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, 'jpg');
        $file2 = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, 'jpg');
        $file3 = $this->createMockUploadedFile('test.jpg', 'image/jpeg', 1000, 'jpg');

        $this->service->uploadBusinessLogo($file1, 'Test');
        $this->service->uploadItemImage($file2, 'Test');
        $this->service->uploadMenuBg($file3, 'test');

        $this->assertDirectoryExists($this->testPublicDir . '/image/business');
        $this->assertDirectoryExists($this->testPublicDir . '/image/items');
        $this->assertDirectoryExists($this->testPublicDir . '/image/menu');
    }

    public function testUploadPreservesExtensionCase(): void
    {
        $file = $this->createMockUploadedFile('TEST.JPG', 'image/jpeg', 1000, 'jpg');
        
        $path = $this->service->uploadBusinessLogo($file, 'Test');

        // Extension should be lowercase
        $this->assertStringEndsWith('.jpg', $path);
        $this->assertStringNotContainsString('.JPG', $path);
    }

    // ── Helper Methods ───────────────────────────────────────────────────────

    private function createMockUploadedFile(
        string $filename,
        string $mimeType,
        int $size,
        ?string $extension = null
    ): UploadedFile {
        $mock = $this->getMockBuilder(UploadedFile::class)
            ->disableOriginalConstructor()
            ->getMock();

        $mock->method('getMimeType')->willReturn($mimeType);
        $mock->method('getSize')->willReturn($size);
        $mock->method('guessExtension')->willReturn($extension);
        
        // Mock the move method to actually create a file
        $mock->method('move')->willReturnCallback(function ($directory, $name) use ($filename) {
            if (!is_dir($directory)) {
                mkdir($directory, 0775, true);
            }
            $fullPath = $directory . '/' . $name;
            // Create an empty file
            touch($fullPath);
            return new \Symfony\Component\HttpFoundation\File\File($fullPath);
        });

        return $mock;
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}

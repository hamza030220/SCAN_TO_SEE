<?php

namespace App\Tests\Service;

use App\Service\MenuFontCatalogService;
use PHPUnit\Framework\TestCase;

final class MenuFontCatalogServiceTest extends TestCase
{
    public function testItDiscoversOnlySupportedSafeFontFiles(): void
    {
        $project = sys_get_temp_dir().'/s2s-font-test-'.bin2hex(random_bytes(5));
        $directory = $project.'/public/fontes_desgne_tool';
        mkdir($directory, 0777, true);
        file_put_contents($directory.'/Restaurant_Display.woff2', 'font');
        file_put_contents($directory.'/Classic.otf', 'font');
        file_put_contents($directory.'/not-a-font.txt', 'text');

        try {
            $service = new MenuFontCatalogService($project);
            $fonts = $service->customFonts();

            self::assertCount(2, $fonts);
            self::assertSame(['Custom Classic', 'Custom Restaurant Display'], array_column($fonts, 'family'));
            self::assertContains('DM Sans', $service->allowedFamilies());
            self::assertContains('Custom Restaurant Display', $service->allowedFamilies());
        } finally {
            unlink($directory.'/Restaurant_Display.woff2');
            unlink($directory.'/Classic.otf');
            unlink($directory.'/not-a-font.txt');
            rmdir($directory);
            rmdir($project.'/public');
            rmdir($project);
        }
    }
}

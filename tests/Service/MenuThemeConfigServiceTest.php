<?php

namespace App\Tests\Service;

use App\Service\MenuThemeConfigService;
use App\Service\MenuFontCatalogService;
use PHPUnit\Framework\TestCase;

final class MenuThemeConfigServiceTest extends TestCase
{
    private MenuThemeConfigService $service;

    protected function setUp(): void
    {
        $this->service = new MenuThemeConfigService(new MenuFontCatalogService(sys_get_temp_dir()));
    }

    public function testItAcceptsSupportedThemeValues(): void
    {
        $config = $this->service->sanitize([
            'theme' => 'dark',
            'font' => 'Poppins',
            'fontScale' => '1.12',
            'layout' => 'grid',
            'density' => 'spacious',
            'bgType' => 'gradient',
            'bgColor' => '#abcdef',
            'bgGradientStart' => '#123456',
            'bgGradientEnd' => '#654321',
            'bgGradientDir' => '135deg',
            'headerBg' => '#111111',
            'accent' => '#e8a020',
            'cardStyle' => 'glass',
            'cardBg' => '#ffffff',
            'cardRadius' => '20',
            'imageShape' => 'circle',
            'priceStyle' => 'badge',
            'priceAlign' => 'right',
            'priceFont' => 'Montserrat',
            'priceSize' => '1.2',
            'priceWeight' => '800',
            'priceColor' => '#ff3300',
            'priceBoxColor' => '#ffee00',
            'priceRadius' => '14',
            'glassBlur' => '18',
            'glassOpacity' => '0.32',
            'pillStyle' => 'chip',
            'logoAlign' => 'center',
        ]);

        self::assertSame('dark', $config['theme']);
        self::assertSame('Poppins', $config['font']);
        self::assertSame(1.12, $config['fontScale']);
        self::assertSame('grid', $config['layout']);
        self::assertSame('spacious', $config['density']);
        self::assertSame('#ABCDEF', $config['bgColor']);
        self::assertSame('#E8A020', $config['accent']);
        self::assertSame(18, $config['glassBlur']);
        self::assertSame(0.32, $config['glassOpacity']);
        self::assertSame(20, $config['cardRadius']);
        self::assertSame('circle', $config['imageShape']);
        self::assertSame('badge', $config['priceStyle']);
        self::assertSame('right', $config['priceAlign']);
        self::assertSame('Montserrat', $config['priceFont']);
        self::assertSame(1.2, $config['priceSize']);
        self::assertSame('800', $config['priceWeight']);
        self::assertSame('#FF3300', $config['priceColor']);
        self::assertSame('#FFEE00', $config['priceBoxColor']);
        self::assertSame(14, $config['priceRadius']);
    }

    public function testItRejectsValuesThatCouldBreakGeneratedCss(): void
    {
        $config = $this->service->sanitize([
            'font' => 'serif; display:none',
            'bgColor' => 'red; position:fixed',
            'bgGradientDir' => '0); background:url(javascript:bad)',
            'accent' => '#12345',
            'layout' => 'freeform',
            'density' => 'crushed',
            'imageShape' => 'triangle',
            'priceStyle' => 'javascript',
            'priceAlign' => 'absolute',
            'priceFont' => 'serif;display:none',
            'priceWeight' => '999',
            'priceColor' => 'transparent',
        ]);

        self::assertSame('DM Sans', $config['font']);
        self::assertSame('#f7f4ef', $config['bgColor']);
        self::assertSame('to bottom', $config['bgGradientDir']);
        self::assertSame('#E8A020', $config['accent']);
        self::assertSame('list', $config['layout']);
        self::assertSame('comfortable', $config['density']);
        self::assertSame('rounded', $config['imageShape']);
        self::assertSame('accent', $config['priceStyle']);
        self::assertSame('left', $config['priceAlign']);
        self::assertSame('Space Grotesk', $config['priceFont']);
        self::assertSame('700', $config['priceWeight']);
        self::assertSame('#E8A020', $config['priceColor']);
    }

    public function testItPreservesTheCurrentBackgroundAndClampsRanges(): void
    {
        $config = $this->service->sanitize(
            ['glassBlur' => 100, 'glassOpacity' => -1, 'fontScale' => 8, 'cardRadius' => -4, 'priceSize' => 9, 'priceRadius' => -2],
            ['bgImagePath' => 'image/menu/existing.webp'],
        );

        self::assertSame('image/menu/existing.webp', $config['bgImagePath']);
        self::assertSame(30, $config['glassBlur']);
        self::assertSame(0.05, $config['glassOpacity']);
        self::assertSame(1.2, $config['fontScale']);
        self::assertSame(0, $config['cardRadius']);
        self::assertSame(1.4, $config['priceSize']);
        self::assertSame(0, $config['priceRadius']);
    }
}

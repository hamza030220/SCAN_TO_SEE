<?php

namespace App\Tests\Service;

use App\Service\MenuThemeConfigService;
use PHPUnit\Framework\TestCase;

final class MenuThemeConfigServiceTest extends TestCase
{
    private MenuThemeConfigService $service;

    protected function setUp(): void
    {
        $this->service = new MenuThemeConfigService();
    }

    public function testItAcceptsSupportedThemeValues(): void
    {
        $config = $this->service->sanitize([
            'theme' => 'dark',
            'font' => 'Poppins',
            'layout' => 'grid',
            'bgType' => 'gradient',
            'bgColor' => '#abcdef',
            'bgGradientStart' => '#123456',
            'bgGradientEnd' => '#654321',
            'bgGradientDir' => '135deg',
            'headerBg' => '#111111',
            'accent' => '#e8a020',
            'cardStyle' => 'glass',
            'cardBg' => '#ffffff',
            'glassBlur' => '18',
            'glassOpacity' => '0.32',
            'pillStyle' => 'chip',
            'logoAlign' => 'center',
        ]);

        self::assertSame('dark', $config['theme']);
        self::assertSame('Poppins', $config['font']);
        self::assertSame('grid', $config['layout']);
        self::assertSame('#ABCDEF', $config['bgColor']);
        self::assertSame('#E8A020', $config['accent']);
        self::assertSame(18, $config['glassBlur']);
        self::assertSame(0.32, $config['glassOpacity']);
    }

    public function testItRejectsValuesThatCouldBreakGeneratedCss(): void
    {
        $config = $this->service->sanitize([
            'font' => 'serif; display:none',
            'bgColor' => 'red; position:fixed',
            'bgGradientDir' => '0); background:url(javascript:bad)',
            'accent' => '#12345',
            'layout' => 'freeform',
        ]);

        self::assertSame('DM Sans', $config['font']);
        self::assertSame('#f7f4ef', $config['bgColor']);
        self::assertSame('to bottom', $config['bgGradientDir']);
        self::assertSame('#E8A020', $config['accent']);
        self::assertSame('list', $config['layout']);
    }

    public function testItPreservesTheCurrentBackgroundAndClampsRanges(): void
    {
        $config = $this->service->sanitize(
            ['glassBlur' => 100, 'glassOpacity' => -1],
            ['bgImagePath' => 'image/menu/existing.webp'],
        );

        self::assertSame('image/menu/existing.webp', $config['bgImagePath']);
        self::assertSame(30, $config['glassBlur']);
        self::assertSame(0.05, $config['glassOpacity']);
    }
}

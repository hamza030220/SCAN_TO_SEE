<?php

namespace App\Tests\Service;

use App\Service\MenuFontCatalogService;
use App\Service\MenuHeroConfigService;
use PHPUnit\Framework\TestCase;

final class MenuHeroConfigServiceTest extends TestCase
{
    private MenuHeroConfigService $service;

    protected function setUp(): void
    {
        $this->service = new MenuHeroConfigService(new MenuFontCatalogService(sys_get_temp_dir()));
    }

    public function testItCreatesALockedBackgroundAndSanitizesLayers(): void
    {
        $config = $this->service->sanitize([
            'enabled' => true,
            'desktopHeight' => 900,
            'mobileHeight' => 100,
            'layers' => [
                ['type' => 'background', 'locked' => false, 'color' => '#abcdef', 'imagePath' => '../secret'],
                [
                    'id' => 'offer', 'name' => '<b>Offer</b>', 'type' => 'text',
                    'content' => '<script>alert(1)</script>Summer sale', 'font' => 'Poppins',
                    'fontSize' => 300, 'color' => '#ff3300',
                    'desktop' => ['x' => -5, 'y' => 99, 'width' => 100, 'height' => 100],
                ],
            ],
        ]);

        self::assertSame(600, $config['desktopHeight']);
        self::assertSame(220, $config['mobileHeight']);
        self::assertSame('background', $config['layers'][0]['id']);
        self::assertTrue($config['layers'][0]['locked']);
        self::assertNull($config['layers'][0]['imagePath']);
        self::assertSame('Offer', $config['layers'][1]['name']);
        self::assertSame('alert(1)Summer sale', $config['layers'][1]['content']);
        self::assertSame(96, $config['layers'][1]['fontSize']);
        self::assertSame(0.0, $config['layers'][1]['desktop']['x']);
        self::assertSame(95.0, $config['layers'][1]['desktop']['y']);
        self::assertSame(5.0, $config['layers'][1]['desktop']['height']);
    }

    public function testItLimitsLayersAndRejectsUnsafeImagePaths(): void
    {
        $layers = [['type' => 'background']];
        for ($i = 0; $i < 30; ++$i) {
            $layers[] = ['id' => 'image-'.$i, 'type' => 'image', 'imagePath' => $i === 0 ? 'image/hero/offer.webp' : 'https://bad.test/x.png'];
        }

        $config = $this->service->sanitize(['layers' => $layers]);

        self::assertCount(MenuHeroConfigService::MAX_LAYERS, $config['layers']);
        self::assertSame('image/hero/offer.webp', $config['layers'][1]['imagePath']);
        self::assertNull($config['layers'][2]['imagePath']);
    }

    public function testPublishingExplainsEveryBlockingProblem(): void
    {
        $config = $this->service->sanitize([
            'enabled' => false,
            'expiresAt' => '2026-08-04T09:00:00+00:00',
            'layers' => [
                ['type' => 'background'],
                ['id' => 'timer', 'name' => 'Offer timer', 'type' => 'countdown', 'visible' => true],
                ['id' => 'photo', 'name' => 'Offer photo', 'type' => 'image', 'visible' => true],
            ],
        ]);

        $errors = $this->service->publishingErrors($config, new \DateTimeImmutable('2026-08-04T10:00:00+00:00'));

        self::assertContains('Turn on “Show hero” before publishing it.', $errors);
        self::assertContains('The expiration time must be in the future.', $errors);
        self::assertContains('Offer photo needs an uploaded image.', $errors);
    }

    public function testCountdownRequiresAnExpirationTime(): void
    {
        $config = $this->service->sanitize([
            'enabled' => true,
            'layers' => [
                ['type' => 'background'],
                ['id' => 'timer', 'name' => 'Countdown', 'type' => 'countdown', 'visible' => true],
            ],
        ]);

        self::assertContains('Countdown needs an expiration date and time.', $this->service->publishingErrors($config));
    }
}

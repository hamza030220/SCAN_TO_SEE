<?php

namespace App\Tests\Entity;

use App\Entity\Menu;
use App\Entity\MenuHero;
use PHPUnit\Framework\TestCase;

final class MenuHeroTest extends TestCase
{
    public function testItLinksBackToItsMenu(): void
    {
        $menu = new Menu();
        $hero = new MenuHero();

        $menu->setHero($hero);

        self::assertSame($hero, $menu->getHero());
        self::assertSame($menu, $hero->getMenu());
    }

    public function testPublicConfigHonoursEnableStartAndExpiration(): void
    {
        $hero = (new MenuHero())->publish([
            'enabled' => true,
            'startsAt' => '2026-08-04T10:00:00+00:00',
            'expiresAt' => '2026-08-04T12:00:00+00:00',
            'layers' => [],
        ], new \DateTimeImmutable('2026-08-04T09:00:00+00:00'));

        self::assertNull($hero->getPublicConfig(new \DateTimeImmutable('2026-08-04T09:59:59+00:00')));
        self::assertNotNull($hero->getPublicConfig(new \DateTimeImmutable('2026-08-04T11:00:00+00:00')));
        self::assertNull($hero->getPublicConfig(new \DateTimeImmutable('2026-08-04T12:00:00+00:00')));

        $hero->hide();
        self::assertNull($hero->getPublicConfig(new \DateTimeImmutable('2026-08-04T11:00:00+00:00')));
    }
}

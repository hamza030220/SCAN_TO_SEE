<?php

namespace App\Tests\Entity;

use App\Entity\Business;
use App\Entity\Category;
use App\Entity\Menu;
use PHPUnit\Framework\TestCase;

class MenuTest extends TestCase
{
    public function testMenuCreatesWithDefaultValues(): void
    {
        $menu = new Menu();

        $this->assertNull($menu->getId());
        $this->assertNull($menu->getBusiness());
        $this->assertNull($menu->getName());
        $this->assertNull($menu->getSlug());
        $this->assertSame('draft', $menu->getStatus());
        $this->assertSame('TND', $menu->getCurrency());
        $this->assertInstanceOf(\DateTimeImmutable::class, $menu->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $menu->getUpdatedAt());
        $this->assertCount(0, $menu->getCategories());
    }

    public function testSetAndGetBusiness(): void
    {
        $menu = new Menu();
        $business = new Business();
        $business->setName('Test Restaurant');

        $menu->setBusiness($business);

        $this->assertSame($business, $menu->getBusiness());
    }

    public function testSetAndGetName(): void
    {
        $menu = new Menu();
        $menu->setName('Lunch Menu');

        $this->assertSame('Lunch Menu', $menu->getName());
    }

    public function testSetAndGetSlug(): void
    {
        $menu = new Menu();
        $menu->setSlug('lunch-menu-2024');

        $this->assertSame('lunch-menu-2024', $menu->getSlug());
    }

    public function testSetAndGetStatus(): void
    {
        $menu = new Menu();
        
        $menu->setStatus('published');
        $this->assertSame('published', $menu->getStatus());

        $menu->setStatus('draft');
        $this->assertSame('draft', $menu->getStatus());
    }

    public function testSetAndGetCurrency(): void
    {
        $menu = new Menu();
        
        $menu->setCurrency('EUR');
        $this->assertSame('EUR', $menu->getCurrency());

        $menu->setCurrency('USD');
        $this->assertSame('USD', $menu->getCurrency());
    }

    public function testGetThemeConfigReturnsDefaultTheme(): void
    {
        $menu = new Menu();
        $config = $menu->getThemeConfig();

        $this->assertIsArray($config);
        $this->assertArrayHasKey('theme', $config);
        $this->assertArrayHasKey('font', $config);
        $this->assertArrayHasKey('layout', $config);
        $this->assertArrayHasKey('bgType', $config);
        $this->assertArrayHasKey('accent', $config);
        $this->assertSame('light', $config['theme']);
        $this->assertSame('DM Sans', $config['font']);
        $this->assertSame('list', $config['layout']);
        $this->assertSame('#E8A020', $config['accent']);
    }

    public function testSetThemeConfigMergesWithDefaults(): void
    {
        $menu = new Menu();
        $customConfig = [
            'theme' => 'dark',
            'accent' => '#FF5733',
        ];

        $menu->setThemeConfig($customConfig);
        $config = $menu->getThemeConfig();

        // Custom values should override defaults
        $this->assertSame('dark', $config['theme']);
        $this->assertSame('#FF5733', $config['accent']);

        // Default values should still be present
        $this->assertSame('DM Sans', $config['font']);
        $this->assertSame('list', $config['layout']);
    }

    public function testSetThemeConfigWithNull(): void
    {
        $menu = new Menu();
        $menu->setThemeConfig(null);
        $config = $menu->getThemeConfig();

        // Should return default theme when null
        $this->assertSame('light', $config['theme']);
        $this->assertSame('DM Sans', $config['font']);
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $menu = new Menu();
        $newDate = (new \DateTimeImmutable())->modify('+2 hours');

        $menu->setUpdatedAt($newDate);

        $this->assertSame($newDate, $menu->getUpdatedAt());
    }

    public function testGetCategoriesReturnsCollection(): void
    {
        $menu = new Menu();
        $categories = $menu->getCategories();

        $this->assertInstanceOf(\Doctrine\Common\Collections\Collection::class, $categories);
        $this->assertCount(0, $categories);
    }

    public function testScannerCanOnlyBeUsedWhileMenuIsEmpty(): void
    {
        $menu = new Menu();

        $this->assertTrue($menu->canUseScanner());

        $menu->getCategories()->add(new Category());

        $this->assertFalse($menu->canUseScanner());
    }

    public function testDefaultThemeHasRequiredKeys(): void
    {
        $expectedKeys = [
            'theme', 'font', 'fontScale', 'layout', 'density', 'bgType', 'bgColor',
            'bgGradientStart', 'bgGradientEnd', 'bgGradientDir',
            'bgImagePath', 'headerBg', 'accent', 'cardStyle',
            'cardBg', 'cardRadius', 'imageShape', 'priceStyle', 'priceAlign',
            'priceFont', 'priceSize', 'priceWeight', 'priceColor', 'priceBoxColor',
            'priceRadius', 'glassBlur', 'glassOpacity', 'pillStyle', 'logoAlign'
        ];

        $this->assertEquals($expectedKeys, array_keys(Menu::DEFAULT_THEME));
    }

    public function testDefaultThemeValues(): void
    {
        $this->assertSame('light', Menu::DEFAULT_THEME['theme']);
        $this->assertSame('DM Sans', Menu::DEFAULT_THEME['font']);
        $this->assertSame(1.0, Menu::DEFAULT_THEME['fontScale']);
        $this->assertSame('list', Menu::DEFAULT_THEME['layout']);
        $this->assertSame('comfortable', Menu::DEFAULT_THEME['density']);
        $this->assertSame('solid', Menu::DEFAULT_THEME['bgType']);
        $this->assertSame('#f7f4ef', Menu::DEFAULT_THEME['bgColor']);
        $this->assertSame('#18120a', Menu::DEFAULT_THEME['headerBg']);
        $this->assertSame('#E8A020', Menu::DEFAULT_THEME['accent']);
        $this->assertSame('flat', Menu::DEFAULT_THEME['cardStyle']);
        $this->assertSame('#ffffff', Menu::DEFAULT_THEME['cardBg']);
        $this->assertSame(12, Menu::DEFAULT_THEME['cardRadius']);
        $this->assertSame('rounded', Menu::DEFAULT_THEME['imageShape']);
        $this->assertSame('accent', Menu::DEFAULT_THEME['priceStyle']);
        $this->assertSame('left', Menu::DEFAULT_THEME['priceAlign']);
        $this->assertSame('Space Grotesk', Menu::DEFAULT_THEME['priceFont']);
        $this->assertSame(0.9, Menu::DEFAULT_THEME['priceSize']);
        $this->assertSame('700', Menu::DEFAULT_THEME['priceWeight']);
        $this->assertSame('#E8A020', Menu::DEFAULT_THEME['priceColor']);
        $this->assertSame('#E8A020', Menu::DEFAULT_THEME['priceBoxColor']);
        $this->assertSame(8, Menu::DEFAULT_THEME['priceRadius']);
        $this->assertSame(8, Menu::DEFAULT_THEME['glassBlur']);
        $this->assertSame(0.15, Menu::DEFAULT_THEME['glassOpacity']);
        $this->assertSame('pill', Menu::DEFAULT_THEME['pillStyle']);
        $this->assertSame('flex-start', Menu::DEFAULT_THEME['logoAlign']);
        $this->assertNull(Menu::DEFAULT_THEME['bgImagePath']);
    }

    public function testFluentSetterChaining(): void
    {
        $business = new Business();
        $menu = new Menu();

        $result = $menu
            ->setBusiness($business)
            ->setName('Dinner Menu')
            ->setSlug('dinner-menu')
            ->setStatus('published')
            ->setCurrency('EUR');

        $this->assertSame($menu, $result);
        $this->assertSame($business, $menu->getBusiness());
        $this->assertSame('Dinner Menu', $menu->getName());
        $this->assertSame('dinner-menu', $menu->getSlug());
        $this->assertSame('published', $menu->getStatus());
        $this->assertSame('EUR', $menu->getCurrency());
    }
}

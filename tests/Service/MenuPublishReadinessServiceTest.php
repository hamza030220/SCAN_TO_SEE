<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use App\Service\MenuPublishReadinessService;
use PHPUnit\Framework\TestCase;

final class MenuPublishReadinessServiceTest extends TestCase
{
    private MenuPublishReadinessService $service;

    protected function setUp(): void
    {
        $this->service = new MenuPublishReadinessService();
    }

    public function testEmptyMenuIsNotReady(): void
    {
        $issues = $this->service->issues(new Menu());

        self::assertCount(2, $issues);
        self::assertFalse($this->service->isReady(new Menu()));
    }

    public function testHiddenCategoryDoesNotMakeMenuReady(): void
    {
        $menu = new Menu();
        $category = (new Category())->setMenu($menu)->setName('Hidden')->setIsVisible(false);
        $category->getItems()->add((new Item())->setCategory($category)->setName('Coffee')->setPrice('4.00'));
        $menu->getCategories()->add($category);

        self::assertFalse($this->service->isReady($menu));
    }

    public function testUnavailableItemsDoNotMakeMenuReady(): void
    {
        $menu = new Menu();
        $category = (new Category())->setMenu($menu)->setName('Drinks')->setIsVisible(true);
        $category->getItems()->add((new Item())->setCategory($category)->setName('Coffee')->setPrice('4.00')->setIsAvailable(false));
        $menu->getCategories()->add($category);

        self::assertSame(['Add at least one available item to a visible category.'], $this->service->issues($menu));
    }

    public function testVisibleAvailableContentIsReady(): void
    {
        $menu = new Menu();
        $category = (new Category())->setMenu($menu)->setName('Drinks')->setIsVisible(true);
        $category->getItems()->add((new Item())->setCategory($category)->setName('Coffee')->setPrice('4.00')->setIsAvailable(true));
        $menu->getCategories()->add($category);

        self::assertTrue($this->service->isReady($menu));
        self::assertSame([], $this->service->issues($menu));
    }
}

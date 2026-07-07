<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use PHPUnit\Framework\TestCase;

class CategoryTest extends TestCase
{
    public function testCategoryCreatesWithDefaultValues(): void
    {
        $category = new Category();

        $this->assertNull($category->getId());
        $this->assertNull($category->getMenu());
        $this->assertNull($category->getParent());
        $this->assertNull($category->getName());
        $this->assertTrue($category->isVisible());
        $this->assertSame(0, $category->getSortOrder());
        $this->assertInstanceOf(\DateTimeImmutable::class, $category->getCreatedAt());
        $this->assertCount(0, $category->getChildren());
        $this->assertCount(0, $category->getItems());
    }

    public function testSetAndGetMenu(): void
    {
        $category = new Category();
        $menu = new Menu();
        $menu->setName('Main Menu');

        $category->setMenu($menu);

        $this->assertSame($menu, $category->getMenu());
    }

    public function testSetAndGetParent(): void
    {
        $parentCategory = new Category();
        $parentCategory->setName('Beverages');

        $childCategory = new Category();
        $childCategory->setName('Hot Drinks');
        $childCategory->setParent($parentCategory);

        $this->assertSame($parentCategory, $childCategory->getParent());
    }

    public function testParentCanBeNull(): void
    {
        $category = new Category();
        $category->setParent(null);

        $this->assertNull($category->getParent());
    }

    public function testSetAndGetName(): void
    {
        $category = new Category();
        $category->setName('Appetizers');

        $this->assertSame('Appetizers', $category->getName());
    }

    public function testSetAndGetIsVisible(): void
    {
        $category = new Category();

        $this->assertTrue($category->isVisible());

        $category->setIsVisible(false);
        $this->assertFalse($category->isVisible());

        $category->setIsVisible(true);
        $this->assertTrue($category->isVisible());
    }

    public function testSetAndGetSortOrder(): void
    {
        $category = new Category();

        $this->assertSame(0, $category->getSortOrder());

        $category->setSortOrder(5);
        $this->assertSame(5, $category->getSortOrder());

        $category->setSortOrder(10);
        $this->assertSame(10, $category->getSortOrder());
    }

    public function testGetChildrenReturnsCollection(): void
    {
        $category = new Category();
        $children = $category->getChildren();

        $this->assertInstanceOf(\Doctrine\Common\Collections\Collection::class, $children);
        $this->assertCount(0, $children);
    }

    public function testGetItemsReturnsCollection(): void
    {
        $category = new Category();
        $items = $category->getItems();

        $this->assertInstanceOf(\Doctrine\Common\Collections\Collection::class, $items);
        $this->assertCount(0, $items);
    }

    public function testCreatedAtIsImmutable(): void
    {
        $category = new Category();
        $originalCreatedAt = $category->getCreatedAt();

        usleep(1000);

        $category2 = new Category();

        $this->assertSame($originalCreatedAt, $category->getCreatedAt());
        $this->assertNotSame($category->getCreatedAt(), $category2->getCreatedAt());
    }

    public function testFluentSetterChaining(): void
    {
        $menu = new Menu();
        $parent = new Category();
        $category = new Category();

        $result = $category
            ->setMenu($menu)
            ->setParent($parent)
            ->setName('Main Courses')
            ->setIsVisible(true)
            ->setSortOrder(2);

        $this->assertSame($category, $result);
        $this->assertSame($menu, $category->getMenu());
        $this->assertSame($parent, $category->getParent());
        $this->assertSame('Main Courses', $category->getName());
        $this->assertTrue($category->isVisible());
        $this->assertSame(2, $category->getSortOrder());
    }

    public function testCanCreateNestedCategoryStructure(): void
    {
        $rootCategory = new Category();
        $rootCategory->setName('Drinks');

        $subCategory1 = new Category();
        $subCategory1->setName('Hot Drinks');
        $subCategory1->setParent($rootCategory);

        $subCategory2 = new Category();
        $subCategory2->setName('Cold Drinks');
        $subCategory2->setParent($rootCategory);

        $this->assertSame($rootCategory, $subCategory1->getParent());
        $this->assertSame($rootCategory, $subCategory2->getParent());
        $this->assertNull($rootCategory->getParent());
    }

    public function testSortOrderCanBeNegative(): void
    {
        $category = new Category();
        $category->setSortOrder(-5);

        $this->assertSame(-5, $category->getSortOrder());
    }
}

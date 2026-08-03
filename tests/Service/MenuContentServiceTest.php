<?php

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use App\Service\MenuContentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class MenuContentServiceTest extends TestCase
{
    public function testDuplicateCategoryCopiesItsItemsWithoutChangingTheSource(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::exactly(2))->method('persist');
        $service = new MenuContentService($entityManager);

        $menu = new Menu();
        $source = (new Category())->setMenu($menu)->setName('Breakfast')->setSortOrder(10);
        $menu->getCategories()->add($source);
        $item = (new Item())->setCategory($source)->setName('Coffee')->setPrice('4.50')->setImagePath('image/items/coffee.webp');
        $source->getItems()->add($item);

        $copy = $service->duplicateCategory($source);

        self::assertSame('Breakfast (copy)', $copy->getName());
        self::assertSame(20, $copy->getSortOrder());
        self::assertSame('Breakfast', $source->getName());
    }

    public function testDuplicateItemUsesTheNextOrder(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist');
        $service = new MenuContentService($entityManager);

        $category = (new Category())->setName('Drinks');
        $source = (new Item())->setCategory($category)->setName('Tea')->setPrice('3.00')->setSortOrder(20);
        $category->getItems()->add($source);

        $copy = $service->duplicateItem($source);

        self::assertSame('Tea (copy)', $copy->getName());
        self::assertSame(30, $copy->getSortOrder());
        self::assertSame($category, $copy->getCategory());
    }

    public function testReorderRejectsMissingOrForeignIds(): void
    {
        $service = new MenuContentService($this->createStub(EntityManagerInterface::class));
        $menu = new Menu();
        $first = (new Category())->setName('One');
        $second = (new Category())->setName('Two');
        $this->setId($first, 10);
        $this->setId($second, 20);
        $menu->getCategories()->add($first);
        $menu->getCategories()->add($second);

        self::assertFalse($service->reorderCategories($menu, [20]));
        self::assertFalse($service->reorderCategories($menu, [20, 99]));
    }

    public function testReorderAssignsStableTenPointPositions(): void
    {
        $service = new MenuContentService($this->createStub(EntityManagerInterface::class));
        $menu = new Menu();
        $first = (new Category())->setName('One');
        $second = (new Category())->setName('Two');
        $this->setId($first, 10);
        $this->setId($second, 20);
        $menu->getCategories()->add($first);
        $menu->getCategories()->add($second);

        self::assertTrue($service->reorderCategories($menu, [20, 10]));
        self::assertSame(20, $first->getSortOrder());
        self::assertSame(10, $second->getSortOrder());
    }

    private function setId(object $entity, int $id): void
    {
        $property = new \ReflectionProperty($entity, 'id');
        $property->setValue($entity, $id);
    }
}

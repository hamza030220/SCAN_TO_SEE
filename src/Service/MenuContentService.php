<?php

namespace App\Service;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use Doctrine\ORM\EntityManagerInterface;

final class MenuContentService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function duplicateCategory(Category $source): Category
    {
        $copy = (new Category())
            ->setMenu($source->getMenu())
            ->setParent($source->getParent())
            ->setName($this->copyName((string) $source->getName()))
            ->setIsVisible($source->isVisible())
            ->setSortOrder($this->nextCategoryOrder($source->getMenu()));

        $this->entityManager->persist($copy);

        foreach ($source->getItems() as $sourceItem) {
            $this->entityManager->persist($this->copyItem($sourceItem, $copy));
        }

        return $copy;
    }

    public function duplicateItem(Item $source): Item
    {
        $copy = $this->copyItem($source, $source->getCategory());
        $copy->setSortOrder($this->nextItemOrder($source->getCategory()));
        $this->entityManager->persist($copy);

        return $copy;
    }

    public function reorderCategories(Menu $menu, array $orderedIds): bool
    {
        $categories = $menu->getCategories()->toArray();
        $ordered = $this->validateOrder($categories, $orderedIds);
        if ($ordered === null) {
            return false;
        }

        foreach ($ordered as $position => $category) {
            $category->setSortOrder(($position + 1) * 10);
        }

        return true;
    }

    public function reorderItems(Category $category, array $orderedIds): bool
    {
        $items = $category->getItems()->toArray();
        $ordered = $this->validateOrder($items, $orderedIds);
        if ($ordered === null) {
            return false;
        }

        foreach ($ordered as $position => $item) {
            $item->setSortOrder(($position + 1) * 10);
        }

        return true;
    }

    private function copyItem(Item $source, ?Category $category): Item
    {
        return (new Item())
            ->setCategory($category)
            ->setName($this->copyName((string) $source->getName()))
            ->setShortDescription($source->getShortDescription())
            ->setPrice((string) $source->getPrice())
            ->setIsAvailable($source->isAvailable())
            ->setSortOrder($source->getSortOrder())
            ->setImagePath($source->getImagePath());
    }

    private function copyName(string $name): string
    {
        $suffix = ' (copy)';

        return mb_substr($name, 0, 150 - mb_strlen($suffix)) . $suffix;
    }

    private function nextCategoryOrder(?Menu $menu): int
    {
        $highest = 0;
        foreach ($menu?->getCategories() ?? [] as $category) {
            $highest = max($highest, $category->getSortOrder());
        }

        return $highest + 10;
    }

    private function nextItemOrder(?Category $category): int
    {
        $highest = 0;
        foreach ($category?->getItems() ?? [] as $item) {
            $highest = max($highest, $item->getSortOrder());
        }

        return $highest + 10;
    }

    private function validateOrder(array $entities, array $orderedIds): ?array
    {
        if (count($entities) !== count($orderedIds)) {
            return null;
        }

        $byId = [];
        foreach ($entities as $entity) {
            if ($entity->getId() === null) {
                return null;
            }
            $byId[(string) $entity->getId()] = $entity;
        }

        $ordered = [];
        foreach ($orderedIds as $id) {
            $key = (string) $id;
            if (!isset($byId[$key]) || in_array($byId[$key], $ordered, true)) {
                return null;
            }
            $ordered[] = $byId[$key];
        }

        return count($ordered) === count($entities) ? $ordered : null;
    }
}

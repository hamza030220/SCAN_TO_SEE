<?php

namespace App\Service;

use App\Entity\Menu;

final class MenuPublishReadinessService
{
    public function issues(Menu $menu): array
    {
        $hasVisibleCategory = false;
        $hasAvailableItem = false;

        foreach ($menu->getCategories() as $category) {
            if (!$category->isVisible()) {
                continue;
            }
            $hasVisibleCategory = true;
            foreach ($category->getItems() as $item) {
                if ($item->isAvailable()) {
                    $hasAvailableItem = true;
                    break 2;
                }
            }
        }

        $issues = [];
        if (!$hasVisibleCategory) {
            $issues[] = 'Add at least one visible category.';
        }
        if (!$hasAvailableItem) {
            $issues[] = 'Add at least one available item to a visible category.';
        }

        return $issues;
    }

    public function isReady(Menu $menu): bool
    {
        return $this->issues($menu) === [];
    }
}

<?php

namespace App\Controller;

use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicMenuController extends AbstractController
{
    /**
     * Entry point: /m/{slug}
     * - If no published menus → "no menu" page
     * - If 1 published menu   → redirect straight to that menu
     * - If 2+ published menus → picker screen
     */
    #[Route('/m/{slug}', name: 'app_public_menu', methods: ['GET'])]
    public function index(
        string $slug,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
    ): Response {
        $business = $businessRepo->findOneBy(['slug' => $slug]);
        if (!$business) {
            throw $this->createNotFoundException('Business not found.');
        }

        $menus = $menuRepo->findBy(
            ['business' => $business, 'status' => 'published'],
            ['name' => 'ASC']
        );

        if (empty($menus)) {
            return $this->render('public/no_menu.html.twig', [
                'business' => $business,
            ]);
        }

        if (count($menus) === 1) {
            return $this->redirectToRoute('app_public_menu_view', [
                'slug'     => $slug,
                'menuSlug' => $menus[0]->getSlug(),
            ]);
        }

        return $this->render('public/picker.html.twig', [
            'business' => $business,
            'menus'    => $menus,
        ]);
    }

    /**
     * Menu view: /m/{slug}/{menuSlug}
     * Shows categories + items for one specific published menu.
     */
    #[Route('/m/{slug}/{menuSlug}', name: 'app_public_menu_view', methods: ['GET'])]
    public function view(
        string $slug,
        string $menuSlug,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
    ): Response {
        $business = $businessRepo->findOneBy(['slug' => $slug]);
        if (!$business) {
            throw $this->createNotFoundException('Business not found.');
        }

        $menu = $menuRepo->findOneBy([
            'slug'     => $menuSlug,
            'business' => $business,
            'status'   => 'published',
        ]);

        if (!$menu) {
            // Menu exists but is not published (or wrong slug) → fall back to picker
            return $this->redirectToRoute('app_public_menu', ['slug' => $slug]);
        }

        // Collect only visible categories (with available items)
        $categories = [];
        foreach ($menu->getCategories() as $cat) {
            if (!$cat->isVisible()) {
                continue;
            }
            $items = array_filter(
                $cat->getItems()->toArray(),
                fn($item) => $item->isAvailable()
            );
            if (count($items) > 0) {
                $categories[] = [
                    'category' => $cat,
                    'items'    => array_values($items),
                ];
            }
        }

        // Check if there are other published menus (for "other menus" link)
        $otherMenus = $menuRepo->findBy(
            ['business' => $business, 'status' => 'published'],
            ['name' => 'ASC']
        );
        $otherMenus = array_filter($otherMenus, fn($m) => $m->getId() !== $menu->getId());

        return $this->render('public/menu.html.twig', [
            'business'   => $business,
            'menu'       => $menu,
            'categories' => $categories,
            'otherMenus' => array_values($otherMenus),
        ]);
    }
}

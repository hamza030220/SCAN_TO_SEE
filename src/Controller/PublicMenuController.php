<?php

namespace App\Controller;

use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use App\Repository\SubscriptionRepository;
use App\Service\EntitlementService;
use App\Service\MenuFontCatalogService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class PublicMenuController extends AbstractController
{
    /**
     * Force browsers, phones, and any proxy (ngrok) to always re-fetch —
     * so a menu re-designed by the owner shows instantly on refresh.
     */
    private function withNoCache(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        return $response;
    }

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
        EntitlementService $entitlements,
    ): Response {
        $business = $businessRepo->findOneBySlugWithOwner($slug);
        if (!$business) {
            throw $this->createNotFoundException('Business not found.');
        }

        if (!$entitlements->hasAccess($business->getOwner()) || $business->getOwner()->isEnforcementRequired()) {
            return $this->withNoCache($this->render('public/no_menu.html.twig', [
                'business' => $business,
            ]));
        }

        $menus = $menuRepo->findBy(
            ['business' => $business, 'status' => 'published'],
            ['name' => 'ASC']
        );

        if (empty($menus)) {
            return $this->withNoCache($this->render('public/no_menu.html.twig', [
                'business' => $business,
            ]));
        }

        if (count($menus) === 1) {
            return $this->withNoCache($this->redirectToRoute('app_public_menu_view', [
                'slug'     => $slug,
                'menuSlug' => $menus[0]->getSlug(),
            ]));
        }

        return $this->withNoCache($this->render('public/picker.html.twig', [
            'business' => $business,
            'menus'    => $menus,
        ]));
    }

    /**
     * Menu view: /m/{slug}/{menuSlug}
     * Shows categories + items for one specific published menu.
     */
    #[Route('/m/{slug}/{menuSlug}', name: 'app_public_menu_view', methods: ['GET'])]
    public function view(
        string $slug,
        string $menuSlug,
        Request $request,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        EntitlementService $entitlements,
        MenuFontCatalogService $fontCatalog,
    ): Response {
        $business = $businessRepo->findOneBySlugWithOwner($slug);
        if (!$business) {
            throw $this->createNotFoundException('Business not found.');
        }

        $viewer = $this->getUser();
        $isOwnerPreview = $request->query->getBoolean('preview')
            && $viewer instanceof \App\Entity\User
            && ($viewer->getRole() === 'admin' || $viewer->getId() === $business->getOwner()?->getId());
        if (!$entitlements->hasAccess($business->getOwner()) || $business->getOwner()->isEnforcementRequired()) {
            return $this->withNoCache($this->render('public/no_menu.html.twig', [
                'business' => $business,
            ]));
        }

        $menu = $menuRepo->findPublicWithContent($business, $menuSlug, $isOwnerPreview);

        if (!$menu) {
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

        $hasOtherMenus = $menuRepo->hasOtherPublishedMenu($business, $menu->getId());
        $hero = $menu->getHero();
        $heroConfig = $isOwnerPreview ? $hero?->getDraftConfig() : $hero?->getPublicConfig();

        return $this->withNoCache($this->render('public/menu.html.twig', [
            'business'   => $business,
            'menu'       => $menu,
            'categories' => $categories,
            'hasOtherMenus' => $hasOtherMenus,
            'customFonts' => $fontCatalog->customFonts(),
            'heroConfig' => $heroConfig,
            'heroPreview' => $isOwnerPreview,
        ]));
    }
}

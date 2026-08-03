<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use App\Repository\BusinessRepository;
use App\Repository\CategoryRepository;
use App\Repository\MenuRepository;
use App\Service\ImageUploadService;
use App\Service\MenuThemeConfigService;
use App\Service\MenuContentService;
use App\Service\MenuPublishReadinessService;
use App\Service\ItemCustomizationService;
use App\Service\EntitlementService;
use App\Service\ScanCaptureService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
final class OwnerMenuController extends AbstractController
{
    // ── Helpers ─────────────────────────────────────────────────────────────

    private function getOwnedMenu(int $id, MenuRepository $menuRepo, BusinessRepository $businessRepo): ?Menu
    {
        /** @var \App\Entity\User $user */
        $user       = $this->getUser();
        $businesses = $businessRepo->findBy(['owner' => $user]);
        if (!$businesses) return null;
        return $menuRepo->createQueryBuilder('m')
            ->where('m.id = :id')
            ->andWhere('m.business IN (:businesses)')
            ->setParameter('id', $id)
            ->setParameter('businesses', $businesses)
            ->getQuery()->getOneOrNullResult();
    }

    private function requireBusiness(BusinessRepository $businessRepo): ?\App\Entity\Business
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        return $businessRepo->findOneBy(['owner' => $user]);
    }

    private function getOwnedBusinesses(BusinessRepository $businessRepo): array
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        return $businessRepo->findBy(['owner' => $user], ['createdAt' => 'ASC']);
    }

    private function uniqueSlug(string $name, MenuRepository $repo): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'menu';
        do {
            $slug = $base . '-' . bin2hex(random_bytes(3));
        } while ($repo->findOneBy(['slug' => $slug]));
        return $slug;
    }

    /**
     * AJAX modal submissions cannot reliably inspect a manual HTTP redirect in
     * the browser. Return the destination explicitly so the modal can navigate
     * without consuming the success flash on an intermediate request.
     */
    private function redirectAfterModalSubmit(Request $request, string $route, array $parameters = []): Response
    {
        $url = $this->generateUrl($route, $parameters);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['redirect' => $url]);
        }

        return $this->redirect($url);
    }

    // ── Menus ────────────────────────────────────────────────────────────────

    #[Route('/owner/menus', name: 'app_owner_menus')]
    public function menuIndex(BusinessRepository $businessRepo, MenuRepository $menuRepo, SubscriptionService $subscriptionService): Response
    {
        $businesses = $this->getOwnedBusinesses($businessRepo);
        if (!$businesses) {
            $this->addFlash('error', 'Please create a business first.');
            return $this->redirectToRoute('app_owner_businesses');
        }

        $allMenus = $menuRepo->createQueryBuilder('m')
            ->where('m.business IN (:businesses)')
            ->setParameter('businesses', $businesses)
            ->orderBy('m.createdAt', 'DESC')
            ->getQuery()->getResult();

        $canCreate = $subscriptionService->canCreateMenu($this->getUser());
        
        // Get subscription info to show helpful hints
        $subscription = $subscriptionService->getSubscription($this->getUser());
        $publishedCount = $subscriptionService->countPublishedMenus($this->getUser());
        $draftCount = $subscriptionService->countDraftMenus($this->getUser());
        $accessContext = $subscriptionService->getAccessContext($this->getUser());

        return $this->render('owner/menu/index.html.twig', [
            'businesses'     => $businesses,
            'menus'          => $allMenus,
            'canCreate'      => $canCreate,
            'subscription'   => $subscription,
            'publishedCount' => $publishedCount,
            'draftCount'     => $draftCount,
            'accessContext'  => $accessContext,
        ]);
    }

    #[Route('/owner/menus/new', name: 'app_owner_menu_new')]
    #[Route('/owner/menus/{id}/edit', name: 'app_owner_menu_edit', requirements: ['id' => '\d+'])]
    public function menuForm(
        Request $request,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        EntityManagerInterface $em,
        SubscriptionService $subscriptionService,
        MenuPublishReadinessService $publishReadiness,
        ?int $id = null,
    ): Response {
        $businesses = $this->getOwnedBusinesses($businessRepo);
        if (!$businesses) {
            $this->addFlash('error', 'Please create a business first.');
            return $this->redirectToRoute('app_owner_businesses');
        }

        $menu  = $id ? $this->getOwnedMenu($id, $menuRepo, $businessRepo) : null;
        if ($id && !$menu) throw $this->createNotFoundException();
        $error = null;
        $limitMessage = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('menu', $request->request->get('_token'))) {
                $error = 'Invalid security token.';
            } else {
                $name        = trim($request->request->get('name', ''));
                $currency    = strtoupper(substr($request->request->get('currency', 'TND'), 0, 3));
                $status      = $request->request->get('status', 'draft');
                $businessId  = (int) $request->request->get('business_id', 0);

                if (!in_array($status, [Menu::STATUS_DRAFT, Menu::STATUS_PUBLISHED], true)) {
                    $error = 'Invalid menu status.';
                }

                $selectedBusiness = null;
                foreach ($businesses as $b) {
                    if ($b->getId() === $businessId) { $selectedBusiness = $b; break; }
                }
                if (!$selectedBusiness) $selectedBusiness = $businesses[0];

                if (!$name) {
                    $error = 'Menu name is required.';
                } elseif (!$error) {
                    $currentStatus = $menu?->getStatus();
                    $menuId = $menu?->getId();
                    $limitResult = $subscriptionService->autoSwapMenuStatus(
                        $this->getUser(),
                        $menuId,
                        $currentStatus,
                        $status,
                    );
                    if (!$limitResult['allowed']) {
                        $limitMessage = $limitResult['message'] ?? 'This plan has no free menu slot.';
                        $error = $limitMessage;
                    }
                }

                if (!$error) {
                    if ($status === Menu::STATUS_PUBLISHED && !$publishReadiness->isReady($menu ?? new Menu())) {
                        $error = implode(' ', $publishReadiness->issues($menu ?? new Menu()));
                    }
                }

                if (!$error) {
                    $isNew = !$menu;
                    if ($isNew) {
                        $menu = new Menu();
                        $menu->setBusiness($selectedBusiness);
                        $menu->setSlug($this->uniqueSlug($name, $menuRepo));
                        $em->persist($menu);
                    }
                    $menu->setName($name);
                    $menu->setCurrency($currency);
                    $menu->setStatus($status);
                    $menu->setUpdatedAt(new \DateTimeImmutable());
                    $em->flush();

                    $this->addFlash('success', $isNew ? 'Menu created.' : 'Menu updated.');
                    return $this->redirectAfterModalSubmit(
                        $request,
                        'app_owner_menu_show',
                        ['id' => $menu->getId()],
                    );
                }
            }
        }

        // Get subscription info for display
        $subscription = $subscriptionService->getSubscription($this->getUser());
        $publishedCount = $subscriptionService->countPublishedMenus($this->getUser());
        $draftCount = $subscriptionService->countDraftMenus($this->getUser());
        $accessContext = $subscriptionService->getAccessContext($this->getUser());

        $canPublish = $subscriptionService->canSetMenuStatus(
            $this->getUser(),
            $menu?->getStatus(),
            Menu::STATUS_PUBLISHED,
        );
        $canDraft = $subscriptionService->canSetMenuStatus(
            $this->getUser(),
            $menu?->getStatus(),
            Menu::STATUS_DRAFT,
        );
        $canCreate = $subscriptionService->canCreateMenu($this->getUser());

        if (!$menu && !$canCreate && $error === null) {
            $limitMessage = sprintf(
                'Your %s currently uses all included menu slots: %d of %s drafts and %d of %s published menus. Change or delete a menu, or compare plans to add more.',
                $accessContext['label'],
                $accessContext['usage']['draft'],
                $accessContext['limits']['draft'] ?? 'Unlimited',
                $accessContext['usage']['published'],
                $accessContext['limits']['published'] ?? 'Unlimited',
            );
            $error = $limitMessage;
        }

        return $this->render('owner/menu/form.html.twig', [
            'menu'           => $menu,
            'businesses'     => $businesses,
            'error'          => $error,
            'canPublish'     => $canPublish,
            'canDraft'       => $canDraft,
            'subscription'   => $subscription,
            'publishedCount' => $publishedCount,
            'draftCount'     => $draftCount,
            'accessContext'  => $accessContext,
            'limitMessage'   => $limitMessage,
            'publishedLimitMessage' => $canPublish ? null : $subscriptionService->menuLimitMessage($this->getUser(), Menu::STATUS_PUBLISHED),
            'draftLimitMessage' => $canDraft ? null : $subscriptionService->menuLimitMessage($this->getUser(), Menu::STATUS_DRAFT),
        ]);
    }

    #[Route('/owner/menus/{id}', name: 'app_owner_menu_show', requirements: ['id' => '\d+'])]
    public function menuShow(int $id, BusinessRepository $businessRepo, MenuRepository $menuRepo): Response
    {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();

        return $this->render('owner/menu/show.html.twig', ['menu' => $menu]);
    }

    #[Route('/owner/menus/{id}/delete', name: 'app_owner_menu_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function menuDelete(int $id, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete-menu-' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try deleting the menu again.');
            return $this->redirectToRoute('app_owner_menus');
        }

        $em->remove($menu);
        $em->flush();
        $this->addFlash('success', 'Menu deleted.');
        return $this->redirectToRoute('app_owner_menus');
    }

    #[Route('/owner/menus/{id}/status', name: 'app_owner_menu_status', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function menuStatus(int $id, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, SubscriptionService $subscriptionService, MenuPublishReadinessService $publishReadiness, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('menu-status-' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $id]);
        }

        $status = $request->request->get('status');
        if (!in_array($status, [Menu::STATUS_DRAFT, Menu::STATUS_PUBLISHED], true)) {
            $this->addFlash('error', 'Unsupported menu status.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $id]);
        }

        if ($status === Menu::STATUS_PUBLISHED) {
            $issues = $publishReadiness->issues($menu);
            if ($issues !== []) {
                $this->addFlash('warning', 'Before publishing: ' . implode(' ', $issues));
                return $this->redirectToRoute('app_owner_menu_show', ['id' => $id]);
            }

            $limit = $subscriptionService->autoSwapMenuStatus($this->getUser(), $menu->getId(), $menu->getStatus(), $status);
            if (!$limit['allowed']) {
                $this->addFlash('info', $limit['message'] ?? 'Your plan has no free published menu slot.');
                return $this->redirectToRoute('app_owner_menu_show', ['id' => $id]);
            }
        }

        $menu->setStatus($status);
        $menu->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', $status === Menu::STATUS_PUBLISHED ? 'Menu published successfully.' : 'Menu moved to draft. Its QR link is no longer public.');

        return $this->redirectToRoute('app_owner_menu_show', ['id' => $id]);
    }

    #[Route('/owner/menus/{id}/theme', name: 'app_owner_menu_theme', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function menuTheme(
        int $id,
        Request $request,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        EntityManagerInterface $em,
        ImageUploadService $imageUpload,
        MenuThemeConfigService $themeConfigService,
    ): Response {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('theme-' . $id, $request->request->get('_token', ''))) {
            return $this->json(['error' => 'Invalid token'], 403);
        }

        $current = $menu->getThemeConfig();
        $data = $themeConfigService->sanitize($request->request->all(), $current);

        /** @var UploadedFile|null $bgFile */
        $bgFile = $request->files->get('bgImage');
        $newBackgroundPath = null;
        if ($bgFile instanceof UploadedFile) {
            try {
                $newBackgroundPath = $imageUpload->uploadMenuBg($bgFile, $menu->getSlug());
                $data['bgImagePath'] = $newBackgroundPath;
            } catch (\RuntimeException $e) {
                return $this->json(['error' => $e->getMessage()], 422);
            }
        }

        $menu->setThemeConfig($data);
        $menu->setUpdatedAt(new \DateTimeImmutable());
        try {
            $em->flush();
        } catch (\Throwable $e) {
            $imageUpload->delete($newBackgroundPath);
            throw $e;
        }

        if ($newBackgroundPath && !empty($current['bgImagePath'])) {
            $imageUpload->delete($current['bgImagePath']);
        }

        return $this->json(['ok' => true]);
    }

    // ── Categories ───────────────────────────────────────────────────────────

    #[Route('/owner/menus/{menuId}/categories/new', name: 'app_owner_category_new', requirements: ['menuId' => '\d+'])]
    #[Route('/owner/menus/{menuId}/categories/{catId}/edit', name: 'app_owner_category_edit', requirements: ['menuId' => '\d+', 'catId' => '\d+'])]
    public function categoryForm(
        Request $request,
        int $menuId,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        CategoryRepository $categoryRepo,
        EntityManagerInterface $em,
        ?int $catId = null,
    ): Response {
        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();

        $category = $catId ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        if ($catId && !$category) throw $this->createNotFoundException();
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('category', $request->request->get('_token'))) {
                $error = 'Invalid security token.';
            } else {
                $name      = trim($request->request->get('name', ''));
                $parentId  = (int) $request->request->get('parent_id', 0);
                $isVisible = (bool) $request->request->get('is_visible', false);
                $sortOrder = (int) $request->request->get('sort_order', 0);

                if (!$name) {
                    $error = 'Category name is required.';
                } else {
                    $isNew = !$category;
                    if ($isNew) {
                        $category = new Category();
                        $category->setMenu($menu);
                        $em->persist($category);
                    }
                    $category->setName($name);
                    $category->setIsVisible($isVisible);
                    $category->setSortOrder($sortOrder);

                    $parent = $parentId ? $categoryRepo->findOneBy(['id' => $parentId, 'menu' => $menu]) : null;
                    $category->setParent($parent);
                    $em->flush();

                    $this->addFlash('success', $isNew ? 'Category added.' : 'Category updated.');
                    return $this->redirectAfterModalSubmit(
                        $request,
                        'app_owner_menu_show',
                        ['id' => $menuId],
                    );
                }
            }
        }

        $allCats = $categoryRepo->findBy(['menu' => $menu]);
        if ($category) {
            $allCats = array_filter($allCats, fn($c) => $c->getId() !== $category->getId());
        }

        return $this->render('owner/menu/category_form.html.twig', [
            'menu'       => $menu,
            'category'   => $category,
            'categories' => array_values($allCats),
            'error'      => $error,
        ]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/delete', name: 'app_owner_category_delete', requirements: ['menuId' => '\d+', 'catId' => '\d+'], methods: ['POST'])]
    public function categoryDelete(int $menuId, int $catId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, EntityManagerInterface $em): Response
    {
        $menu     = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        if (!$menu || !$category) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete-cat-' . $catId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try deleting the category again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $em->remove($category);
        $em->flush();
        $this->addFlash('success', 'Category deleted.');
        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/duplicate', name: 'app_owner_category_duplicate', requirements: ['menuId' => '\d+', 'catId' => '\d+'], methods: ['POST'])]
    public function categoryDuplicate(int $menuId, int $catId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, MenuContentService $contentService, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        if (!$menu || !$category) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('duplicate-category-' . $catId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $contentService->duplicateCategory($category);
        $em->flush();
        $this->addFlash('success', 'Category duplicated.');

        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/visibility', name: 'app_owner_category_visibility', requirements: ['menuId' => '\d+', 'catId' => '\d+'], methods: ['POST'])]
    public function categoryVisibility(int $menuId, int $catId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        if (!$menu || !$category) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('category-visibility-' . $catId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $category->setIsVisible(!$category->isVisible());
        $menu->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', $category->isVisible() ? 'Category is visible to customers.' : 'Category hidden from customers.');

        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    // ── Items ────────────────────────────────────────────────────────────────

    #[Route('/owner/menus/{menuId}/categories/{catId}/items/new', name: 'app_owner_item_new', requirements: ['menuId' => '\d+', 'catId' => '\d+'])]
    #[Route('/owner/menus/{menuId}/categories/{catId}/items/{itemId}/edit', name: 'app_owner_item_edit', requirements: ['menuId' => '\d+', 'catId' => '\d+', 'itemId' => '\d+'])]
    public function itemForm(
        Request $request,
        int $menuId,
        int $catId,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        CategoryRepository $categoryRepo,
        EntityManagerInterface $em,
        ImageUploadService $imageUpload,
        ItemCustomizationService $customization,
        ?int $itemId = null,
    ): Response {
        $menu     = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        if (!$menu || !$category) throw $this->createNotFoundException();

        $item  = null;
        $error = null;

        if ($itemId) {
            foreach ($category->getItems() as $i) {
                if ($i->getId() === $itemId) { $item = $i; break; }
            }
            if (!$item) throw $this->createNotFoundException();
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('item', $request->request->get('_token'))) {
                $error = 'Invalid security token.';
            } else {
                $name        = trim($request->request->get('name', ''));
                $shortDesc   = trim($request->request->get('short_description', '')) ?: null;
                $details     = $customization->text($request->request->get('details'), 2000);
                $badge       = $customization->badge($request->request->get('badge'));
                $dietaryTags = $customization->labels($request->request->get('dietary_tags'));
                $allergens   = $customization->labels($request->request->get('allergens'));
                $availabilityNote = $customization->text($request->request->get('availability_note'), 120);
                $variantNames = $request->request->all()['variant_name'] ?? [];
                $variantPrices = $request->request->all()['variant_price'] ?? [];
                $priceRaw    = $request->request->get('price', '');
                $isAvailable = (bool) $request->request->get('is_available', false);
                $sortOrder   = (int) $request->request->get('sort_order', 0);
                $imageFile   = $request->files->get('image');

                if (!$name) {
                    $error = 'Item name is required.';
                } elseif (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $error = 'Please enter a valid price.';
                } else {
                    try {
                        $variants = $customization->variants(
                            is_array($variantNames) ? $variantNames : [],
                            is_array($variantPrices) ? $variantPrices : [],
                        );
                    } catch (\InvalidArgumentException $e) {
                        $variants = [];
                        $error = $e->getMessage();
                    }
                }

                if (!$error) {
                    $isNew = !$item;
                    if ($isNew) {
                        $item = new Item();
                        $item->setCategory($category);
                        $em->persist($item);
                    }
                    $item->setName($name);
                    $item->setShortDescription($shortDesc);
                    $item->setDetails($details);
                    $item->setBadge($badge);
                    $item->setDietaryTags($dietaryTags);
                    $item->setAllergens($allergens);
                    $item->setVariants($variants);
                    $item->setAvailabilityNote($availabilityNote);
                    $item->setPrice(number_format((float) $priceRaw, 2, '.', ''));
                    $item->setIsAvailable($isAvailable);
                    $item->setSortOrder($sortOrder);

                    if ($imageFile instanceof UploadedFile) {
                        $previousImagePath = $item->getImagePath();
                        $newImagePath = null;
                        try {
                            $newImagePath = $imageUpload->uploadItemImage($imageFile, $name);
                            $item->setImagePath($newImagePath);
                        } catch (\RuntimeException $e) {
                            $error = $e->getMessage();
                        }
                    }

                    if (!$error) {
                        $item->setUpdatedAt(new \DateTimeImmutable());
                        try {
                            $em->flush();
                        } catch (\Throwable $e) {
                            $imageUpload->delete($newImagePath ?? null);
                            throw $e;
                        }
                        if (($newImagePath ?? null) && ($previousImagePath ?? null)) {
                            $imageUpload->delete($previousImagePath);
                        }
                        $this->addFlash('success', $isNew ? 'Item added.' : 'Item updated.');
                        return $this->redirectAfterModalSubmit(
                            $request,
                            'app_owner_menu_show',
                            ['id' => $menuId],
                        );
                    }
                }
            }
        }

        return $this->render('owner/menu/item_form.html.twig', [
            'menu'     => $menu,
            'category' => $category,
            'item'     => $item,
            'error'    => $error,
        ]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/items/{itemId}/delete', name: 'app_owner_item_delete', requirements: ['menuId' => '\d+', 'catId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function itemDelete(int $menuId, int $catId, int $itemId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, EntityManagerInterface $em): Response
    {
        $menu     = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        $item     = null;
        if ($category) {
            foreach ($category->getItems() as $i) {
                if ($i->getId() === $itemId) { $item = $i; break; }
            }
        }
        if (!$menu || !$category || !$item) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete-item-' . $itemId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try deleting the item again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Item deleted.');
        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/items/{itemId}/duplicate', name: 'app_owner_item_duplicate', requirements: ['menuId' => '\d+', 'catId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function itemDuplicate(int $menuId, int $catId, int $itemId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, MenuContentService $contentService, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        $item = null;
        foreach ($category?->getItems() ?? [] as $candidate) {
            if ($candidate->getId() === $itemId) { $item = $candidate; break; }
        }
        if (!$menu || !$category || !$item) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('duplicate-item-' . $itemId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $contentService->duplicateItem($item);
        $em->flush();
        $this->addFlash('success', 'Item duplicated.');

        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    #[Route('/owner/menus/{menuId}/categories/{catId}/items/{itemId}/availability', name: 'app_owner_item_availability', requirements: ['menuId' => '\d+', 'catId' => '\d+', 'itemId' => '\d+'], methods: ['POST'])]
    public function itemAvailability(int $menuId, int $catId, int $itemId, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, EntityManagerInterface $em): Response
    {
        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        $category = $menu ? $categoryRepo->findOneBy(['id' => $catId, 'menu' => $menu]) : null;
        $item = null;
        foreach ($category?->getItems() ?? [] as $candidate) {
            if ($candidate->getId() === $itemId) { $item = $candidate; break; }
        }
        if (!$menu || !$category || !$item) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('item-availability-' . $itemId, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try again.');
            return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
        }

        $item->setIsAvailable(!$item->isAvailable());
        $item->setUpdatedAt(new \DateTimeImmutable());
        $menu->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();
        $this->addFlash('success', $item->isAvailable() ? 'Item is available to customers.' : 'Item marked unavailable.');

        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }

    #[Route('/owner/menus/{id}/reorder', name: 'app_owner_menu_reorder', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function reorder(int $id, Request $request, BusinessRepository $businessRepo, MenuRepository $menuRepo, CategoryRepository $categoryRepo, MenuContentService $contentService, EntityManagerInterface $em): JsonResponse
    {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload) || !$this->isCsrfTokenValid('reorder-menu-' . $id, $payload['_token'] ?? '')) {
            return $this->json(['error' => 'Invalid security token. Refresh and try again.'], 403);
        }

        $ids = is_array($payload['ids'] ?? null) ? $payload['ids'] : [];
        if (($payload['type'] ?? null) === 'categories') {
            $valid = $contentService->reorderCategories($menu, $ids);
        } elseif (($payload['type'] ?? null) === 'items') {
            $category = $categoryRepo->findOneBy(['id' => (int) ($payload['categoryId'] ?? 0), 'menu' => $menu]);
            $valid = $category && $contentService->reorderItems($category, $ids);
        } else {
            $valid = false;
        }

        if (!$valid) {
            return $this->json(['error' => 'The menu order changed elsewhere. Refresh and try again.'], 409);
        }

        $menu->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

        return $this->json(['ok' => true]);
    }

    // ── Menu Scanner (beta) ──────────────────────────────────────────────────

    #[Route('/owner/scanner/workspace', name: 'app_owner_scanner_workspace')]
    public function scannerWorkspace(
        Request           $request,
        MenuRepository    $menuRepo,
        BusinessRepository $businessRepo,
        EntitlementService $entitlements,
    ): Response {
        $menuId = $request->query->getInt('menu');
        $menu   = $menuId ? $this->getOwnedMenu($menuId, $menuRepo, $businessRepo) : null;

        return $this->render('owner/scanner/workspace.html.twig', [
            'menu' => $menu,
            'scannerAllowed' => $menu?->canUseScanner() ?? false,
            'trialAiRemaining' => $entitlements->remainingTrialAiUses($this->getUser()),
            'trialAiUsed' => $entitlements->isTrialAccess($this->getUser())
                ? $this->getUser()->getTrialAiUses()
                : null,
        ]);
    }

    #[Route('/owner/scanner/privacy', name: 'app_owner_scanner_privacy')]
    public function scannerPrivacy(): Response
    {
        return $this->render('owner/scanner/privacy.html.twig');
    }

    /**
     * POST /owner/scanner/scan
     * Receives one image + currency, proxies to FastAPI, returns JSON.
     */
    #[Route('/owner/scanner/scan', name: 'app_owner_scanner_scan', methods: ['POST'])]
    public function scannerScan(
        Request $request,
        ScanCaptureService $captureService,
        MenuRepository $menuRepo,
        BusinessRepository $businessRepo,
        EntitlementService $entitlements,
    ): JsonResponse
    {
        if (!$this->isCsrfTokenValid('scanner-scan', $request->request->get('_token'))) {
            return $this->json(['error' => 'Invalid security token. Refresh the page and try again.'], 403);
        }
        if ($request->request->get('data_terms_accepted') !== '1') {
            return $this->json(['error' => 'Accept the AI Scanner data terms before scanning.'], 422);
        }

        $menuId = $request->request->getInt('menu_id');
        if (!$menuId) {
            return $this->json([
                'error' => 'The scanner can only be used from an empty target menu.',
            ], 409);
        }

        $menu = $this->getOwnedMenu($menuId, $menuRepo, $businessRepo);
        if (!$menu) {
            return $this->json(['error' => 'Menu not found or access denied.'], 404);
        }
        if (!$menu->canUseScanner()) {
            return $this->json([
                'error' => 'This menu already contains data. Scanning is only available for an empty menu.',
            ], 409);
        }

        /** @var UploadedFile|null $imageFile */
        $imageFile = $request->files->get('image');

        if (!$imageFile || !$imageFile->isValid()) {
            return $this->json(['error' => 'No valid image file received.'], 400);
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/jpg'];
        if (!in_array($imageFile->getMimeType(), $allowedMimes, true)) {
            return $this->json(['error' => 'Only JPG and PNG images are accepted.'], 400);
        }

        $currency = strtoupper(substr(trim($request->request->get('currency', 'TND')), 0, 3));

        /** @var \App\Entity\User $owner */
        $owner = $this->getUser();
        $trialUseReserved = $entitlements->isTrialAccess($owner);
        if (!$entitlements->reserveTrialAiUse($owner)) {
            return $this->json(['error' => 'You have used all 3 AI scans included in your free trial. Choose a plan to continue scanning.'], 429);
        }

        try {
            $capture = $captureService->capture($imageFile, $owner, $menu, $currency);
            $response = $capture['response'];
            if ($trialUseReserved) {
                $response['trial_ai_used'] = $owner->getTrialAiUses();
                $response['trial_ai_remaining'] = $entitlements->remainingTrialAiUses($owner);
            }
            return $this->json($response);
        } catch (\RuntimeException $e) {
            if ($trialUseReserved) {
                $entitlements->releaseTrialAiUse($owner);
            }
            return $this->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * POST /owner/scanner/save
     * Persists reviewed categories + items into the target menu.
     * Body: { menu_id: int, images: [ { categories: [ { name, items: [ { name, price } ] } ] } ] }
     */
    #[Route('/owner/scanner/save', name: 'app_owner_scanner_save', methods: ['POST'])]
    public function scannerSave(
        Request            $request,
        MenuRepository     $menuRepo,
        BusinessRepository $businessRepo,
        EntityManagerInterface $em,
        ScanCaptureService $captureService,
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!is_array($data) || empty($data['menu_id'])) {
            return $this->json(['error' => 'Invalid payload.'], 400);
        }
        if (!$this->isCsrfTokenValid('scanner-save', $data['_token'] ?? null)) {
            return $this->json(['error' => 'Invalid security token. Refresh the page and try again.'], 403);
        }

        $menu = $this->getOwnedMenu((int) $data['menu_id'], $menuRepo, $businessRepo);
        if (!$menu) {
            return $this->json(['error' => 'Menu not found or access denied.'], 404);
        }

        $catSortOrder = 0;
        $images = $data['images'] ?? [];

        foreach ($images as $imageData) {
            $categories = $imageData['categories'] ?? [];
            if (!empty($imageData['scan_id'])) {
                try {
                    /** @var \App\Entity\User $owner */
                    $owner = $this->getUser();
                    $captureService->recordReview(
                        (int) $imageData['scan_id'],
                        $owner,
                        $menu,
                        $categories,
                    );
                } catch (\RuntimeException $e) {
                    return $this->json(['error' => $e->getMessage()], 422);
                }
            }
            foreach ($categories as $catData) {
                $catName = trim($catData['name'] ?? '');
                if ($catName === '') continue;

                $category = new Category();
                $category->setMenu($menu);
                $category->setName($catName);
                $category->setSortOrder($catSortOrder++);
                $category->setIsVisible(true);
                $em->persist($category);

                $itemSortOrder = 0;
                foreach ($catData['items'] ?? [] as $itemData) {
                    $itemName = trim($itemData['name'] ?? '');
                    if ($itemName === '') continue;

                    $priceRaw = trim((string) ($itemData['price'] ?? ''));
                    $normalizedPrice = str_replace(',', '.', $priceRaw);
                    $price    = is_numeric($normalizedPrice)
                        ? number_format((float) $normalizedPrice, 2, '.', '')
                        : '0.00';

                    $item = new Item();
                    $item->setCategory($category);
                    $item->setName($itemName);
                    $item->setPrice($price);
                    $item->setSortOrder($itemSortOrder++);
                    $item->setIsAvailable(true);
                    $em->persist($item);
                }
            }
        }

        $em->flush();

        return $this->json([
            'ok'       => true,
            'redirect' => $this->generateUrl('app_owner_menu_show', ['id' => $menu->getId()]),
        ]);
    }
}

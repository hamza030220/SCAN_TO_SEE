<?php

namespace App\Controller;

use App\Entity\Category;
use App\Entity\Item;
use App\Entity\Menu;
use App\Repository\BusinessRepository;
use App\Repository\CategoryRepository;
use App\Repository\MenuRepository;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    // ── Menus ────────────────────────────────────────────────────────────────

    #[Route('/owner/menus', name: 'app_owner_menus')]
    public function menuIndex(BusinessRepository $businessRepo, MenuRepository $menuRepo): Response
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

        return $this->render('owner/menu/index.html.twig', [
            'businesses' => $businesses,
            'menus'      => $allMenus,
        ]);
    }

    #[Route('/owner/menus/new', name: 'app_owner_menu_new')]
    #[Route('/owner/menus/{id}/edit', name: 'app_owner_menu_edit', requirements: ['id' => '\d+'])]
    public function menuForm(
        Request $request,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        EntityManagerInterface $em,
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

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('menu', $request->request->get('_token'))) {
                $error = 'Invalid security token.';
            } else {
                $name        = trim($request->request->get('name', ''));
                $currency    = strtoupper(substr($request->request->get('currency', 'TND'), 0, 3));
                $themePreset = $request->request->get('theme_preset', 'modern');
                $status      = $request->request->get('status', 'draft');
                $businessId  = (int) $request->request->get('business_id', 0);

                $selectedBusiness = null;
                foreach ($businesses as $b) {
                    if ($b->getId() === $businessId) { $selectedBusiness = $b; break; }
                }
                if (!$selectedBusiness) $selectedBusiness = $businesses[0];

                if (!$name) {
                    $error = 'Menu name is required.';
                } else {
                    $isNew = !$menu;
                    if ($isNew) {
                        $menu = new Menu();
                        $menu->setBusiness($selectedBusiness);
                        $menu->setSlug($this->uniqueSlug($name, $menuRepo));
                        $em->persist($menu);
                    }
                    $menu->setName($name);
                    $menu->setCurrency($currency);
                    $menu->setThemePreset($themePreset);
                    $menu->setStatus($status);
                    $menu->setUpdatedAt(new \DateTimeImmutable());
                    $em->flush();

                    $this->addFlash('success', $isNew ? 'Menu created.' : 'Menu updated.');
                    return $this->redirectToRoute('app_owner_menu_show', ['id' => $menu->getId()]);
                }
            }
        }

        return $this->render('owner/menu/form.html.twig', [
            'menu'       => $menu,
            'businesses' => $businesses,
            'error'      => $error,
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
            throw $this->createAccessDeniedException();
        }

        $em->remove($menu);
        $em->flush();
        $this->addFlash('success', 'Menu deleted.');
        return $this->redirectToRoute('app_owner_menus');
    }

    #[Route('/owner/menus/{id}/theme', name: 'app_owner_menu_theme', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function menuTheme(
        int $id,
        Request $request,
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
        EntityManagerInterface $em,
        ImageUploadService $imageUpload,
    ): Response {
        $menu = $this->getOwnedMenu($id, $menuRepo, $businessRepo);
        if (!$menu) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('theme-' . $id, $request->request->get('_token', ''))) {
            return $this->json(['error' => 'Invalid token'], 403);
        }

        $current = $menu->getThemeConfig();
        $data = [
            'theme'           => in_array($request->request->get('theme'), ['light', 'dark']) ? $request->request->get('theme') : 'light',
            'font'            => $request->request->get('font', 'DM Sans'),
            'bgType'          => in_array($request->request->get('bgType'), ['solid', 'gradient', 'image']) ? $request->request->get('bgType') : 'solid',
            'bgColor'         => $request->request->get('bgColor', '#f7f4ef'),
            'bgGradientStart' => $request->request->get('bgGradientStart', '#f7f4ef'),
            'bgGradientEnd'   => $request->request->get('bgGradientEnd', '#e8e0d5'),
            'bgGradientDir'   => $request->request->get('bgGradientDir', 'to bottom'),
            'bgImagePath'     => $current['bgImagePath'] ?? null,
            'headerBg'        => $request->request->get('headerBg', '#18120a'),
            'accent'          => $request->request->get('accent', '#E8A020'),
            'cardStyle'       => in_array($request->request->get('cardStyle'), ['flat', 'glass', 'bordered']) ? $request->request->get('cardStyle') : 'flat',
            'cardBg'          => $request->request->get('cardBg', '#ffffff'),
            'glassBlur'       => min(30, max(2, (int) $request->request->get('glassBlur', 8))),
            'glassOpacity'    => min(0.6, max(0.05, round((float) $request->request->get('glassOpacity', 0.15), 2))),
            'pillStyle'       => in_array($request->request->get('pillStyle'), ['pill', 'underline', 'chip']) ? $request->request->get('pillStyle') : 'pill',
            'logoAlign'       => in_array($request->request->get('logoAlign'), ['flex-start', 'center', 'flex-end']) ? $request->request->get('logoAlign') : 'flex-start',
        ];

        /** @var UploadedFile|null $bgFile */
        $bgFile = $request->files->get('bgImage');
        if ($bgFile instanceof UploadedFile) {
            if (!empty($current['bgImagePath'])) {
                $imageUpload->delete($current['bgImagePath']);
            }
            try {
                $data['bgImagePath'] = $imageUpload->uploadMenuBg($bgFile, $menu->getSlug());
            } catch (\RuntimeException $e) {
                return $this->json(['error' => $e->getMessage()], 422);
            }
        }

        $menu->setThemeConfig($data);
        $menu->setUpdatedAt(new \DateTimeImmutable());
        $em->flush();

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
                    return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
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
            throw $this->createAccessDeniedException();
        }

        $em->remove($category);
        $em->flush();
        $this->addFlash('success', 'Category deleted.');
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
                $priceRaw    = $request->request->get('price', '');
                $isAvailable = (bool) $request->request->get('is_available', false);
                $sortOrder   = (int) $request->request->get('sort_order', 0);
                $imageFile   = $request->files->get('image');

                if (!$name) {
                    $error = 'Item name is required.';
                } elseif (!is_numeric($priceRaw) || (float) $priceRaw < 0) {
                    $error = 'Please enter a valid price.';
                } else {
                    $isNew = !$item;
                    if ($isNew) {
                        $item = new Item();
                        $item->setCategory($category);
                        $em->persist($item);
                    }
                    $item->setName($name);
                    $item->setShortDescription($shortDesc);
                    $item->setPrice(number_format((float) $priceRaw, 2, '.', ''));
                    $item->setIsAvailable($isAvailable);
                    $item->setSortOrder($sortOrder);

                    if ($imageFile instanceof UploadedFile) {
                        try {
                            $imageUpload->delete($item->getImagePath());
                            $item->setImagePath(
                                $imageUpload->uploadItemImage($imageFile, $name)
                            );
                        } catch (\RuntimeException $e) {
                            $error = $e->getMessage();
                        }
                    }

                    if (!$error) {
                        $item->setUpdatedAt(new \DateTimeImmutable());
                        $em->flush();
                        $this->addFlash('success', $isNew ? 'Item added.' : 'Item updated.');
                        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
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
            throw $this->createAccessDeniedException();
        }

        $em->remove($item);
        $em->flush();
        $this->addFlash('success', 'Item deleted.');
        return $this->redirectToRoute('app_owner_menu_show', ['id' => $menuId]);
    }
}

<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
#[Route('/admin')]
final class AdminController extends AbstractController
{
    #[Route('', name: 'app_admin')]
    public function index(
        UserRepository     $users,
        BusinessRepository $businesses,
        MenuRepository     $menus,
    ): Response {
        $allUsers      = $users->findAll();
        $totalOwners   = count(array_filter($allUsers, fn(User $u) => $u->getRole() === 'owner'));
        $totalAdmins   = count(array_filter($allUsers, fn(User $u) => $u->getRole() === 'admin'));
        $activeOwners  = count(array_filter($allUsers, fn(User $u) => $u->getRole() === 'owner' && $u->isActive()));

        return $this->render('admin/index.html.twig', [
            'totalOwners'   => $totalOwners,
            'totalAdmins'   => $totalAdmins,
            'activeOwners'  => $activeOwners,
            'totalBusinesses' => count($businesses->findAll()),
            'totalMenus'    => count($menus->findAll()),
            'recentOwners'  => $users->findBy(['role' => 'owner'], ['createdAt' => 'DESC'], 5),
        ]);
    }

    /* ── Owners list ──────────────────────────────────────────────── */

    #[Route('/owners', name: 'app_admin_owners')]
    public function owners(UserRepository $users): Response
    {
        return $this->render('admin/owners.html.twig', [
            'owners' => $users->findBy(['role' => 'owner'], ['createdAt' => 'DESC']),
        ]);
    }

    /* ── Owner detail ─────────────────────────────────────────────── */

    #[Route('/owners/{id}', name: 'app_admin_owner_show', requirements: ['id' => '\d+'])]
    public function ownerShow(User $owner, BusinessRepository $businesses, MenuRepository $menus): Response
    {
        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        $ownerBusinesses = $businesses->findBy(['owner' => $owner]);

        // Build a map: businessId => Menu[]
        $menusMap = [];
        foreach ($ownerBusinesses as $business) {
            $menusMap[$business->getId()] = $menus->findBy(['business' => $business]);
        }

        return $this->render('admin/owner_show.html.twig', [
            'owner'      => $owner,
            'businesses' => $ownerBusinesses,
            'menusMap'   => $menusMap,
        ]);
    }

    /* ── Toggle active / inactive ─────────────────────────────────── */

    #[Route('/owners/{id}/toggle', name: 'app_admin_owner_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ownerToggle(User $owner, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('toggle-owner-' . $owner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_owners');
        }

        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        $owner->setIsActive(!$owner->isActive());
        $em->flush();

        $state = $owner->isActive() ? 'activated' : 'deactivated';
        $this->addFlash('success', "Account {$state}: {$owner->getEmail()}");

        return $this->redirectToRoute('app_admin_owners');
    }

    /* ── Delete owner ─────────────────────────────────────────────── */

    #[Route('/owners/{id}/delete', name: 'app_admin_owner_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ownerDelete(User $owner, Request $request, EntityManagerInterface $em): Response
    {
        if (!$this->isCsrfTokenValid('delete-owner-' . $owner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_owners');
        }

        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        $email = $owner->getEmail();
        $em->remove($owner);
        $em->flush();

        $this->addFlash('success', "Owner account deleted: {$email}");

        return $this->redirectToRoute('app_admin_owners');
    }
}


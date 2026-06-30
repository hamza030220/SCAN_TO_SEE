<?php

namespace App\Controller;

use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
final class OwnerController extends AbstractController
{
    #[Route('/owner', name: 'app_owner')]
    public function index(
        BusinessRepository $businessRepo,
        MenuRepository $menuRepo,
    ): Response {
        /** @var \App\Entity\User $user */
        $user       = $this->getUser();
        $businesses = $businessRepo->findBy(['owner' => $user], ['createdAt' => 'ASC']);

        $businessData = [];
        $totalMenus   = 0;
        foreach ($businesses as $business) {
            $menus      = $menuRepo->findBy(['business' => $business], ['createdAt' => 'DESC']);
            $menuCount  = count($menus);
            $totalMenus += $menuCount;
            $businessData[] = [
                'business'   => $business,
                'menus'      => $menus,
                'menuCount'  => $menuCount,
            ];
        }

        return $this->render('owner/index.html.twig', [
            'businessData'     => $businessData,
            'totalBusinesses'  => count($businesses),
            'totalMenus'       => $totalMenus,
        ]);
    }
}


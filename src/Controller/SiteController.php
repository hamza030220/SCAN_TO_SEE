<?php

namespace App\Controller;

use App\Entity\Subscription;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SiteController extends AbstractController
{
    #[Route('/', name: 'app_home')]
    public function home(): Response
    {
        return $this->render('site/home.html.twig', [
            'features' => [
                [
                    'label' => 'Permanent QR',
                    'title' => 'Print once, update forever',
                    'body' => 'Every menu keeps one fixed public link while owners update items, prices, photos, and availability behind it.',
                ],
                [
                    'label' => 'Live menu editor',
                    'title' => 'Draft safely before publishing',
                    'body' => 'Owners can prepare changes without changing the live customer menu until they are ready.',
                ],
                [
                    'label' => 'Built for venues',
                    'title' => 'Food, drinks, services, and products',
                    'body' => 'Categories, subcategories, item details, variants, and translations work for restaurants, spas, salons, and more.',
                ],
            ],
            'previewItems' => [
                ['name' => 'Iced Latte', 'detail' => 'Oat milk option available', 'price' => '8.50 TND'],
                ['name' => 'Signature Massage', 'detail' => '60 minutes with aromatherapy', 'price' => '90.00 TND'],
                ['name' => 'Seasonal Tart', 'detail' => 'Limited availability today', 'price' => '7.00 TND'],
            ],
        ]);
    }

    #[Route('/about', name: 'app_about')]
    public function about(): Response
    {
        return $this->render('site/about.html.twig');
    }

    #[Route('/pricing', name: 'app_pricing')]
    public function pricing(): Response
    {
        return $this->render('site/pricing.html.twig', [
            'plans' => [
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_BASIC],
                    'period' => 'For one business',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_BASIC][Subscription::PERIOD_YEARLY],
                    'description' => 'The essentials for a single venue.',
                    'items' => ['1 published menu', '1 draft menu', 'Permanent QR codes', 'Live menu updates'],
                ],
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_PREMIUM],
                    'period' => 'For growing businesses',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_PREMIUM][Subscription::PERIOD_YEARLY],
                    'description' => 'More menus and support for multiple businesses.',
                    'items' => ['3 published menus', '3 draft menus', 'Multiple businesses', 'Permanent QR codes'],
                ],
                [
                    'name' => Subscription::LABELS[Subscription::PLAN_PRO],
                    'period' => 'For larger operations',
                    'monthly' => Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_MONTHLY],
                    'yearly' => Subscription::PRICES[Subscription::PLAN_PRO][Subscription::PERIOD_YEARLY],
                    'description' => 'Unlimited menu capacity and priority support.',
                    'items' => ['Unlimited published menus', 'Unlimited drafts', 'Multiple businesses', 'Priority support'],
                ],
            ],
        ]);
    }

    #[Route('/login', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser()) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('site/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error'         => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by Symfony Security firewall.');
    }
}

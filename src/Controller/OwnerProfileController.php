<?php

namespace App\Controller;

use App\Entity\Business;
use App\Repository\BusinessRepository;
use App\Repository\SubscriptionRepository;
use App\Service\ImageUploadService;
use App\Service\AccountDeletionService;
use App\Service\SubscriptionService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
final class OwnerProfileController extends AbstractController
{
    private function redirectAfterModalSubmit(Request $request, string $route, array $parameters = []): Response
    {
        $url = $this->generateUrl($route, $parameters);

        if ($request->isXmlHttpRequest()) {
            return new JsonResponse(['redirect' => $url]);
        }

        return $this->redirect($url);
    }

    // Redirect old /owner/profile to /owner/businesses
    #[Route('/owner/profile', name: 'app_owner_profile')]
    public function profileRedirect(): Response
    {
        return $this->redirectToRoute('app_owner_businesses');
    }

    #[Route('/owner/businesses', name: 'app_owner_businesses')]
    public function list(
        BusinessRepository $businessRepo,
        SubscriptionService $subscriptionService,
    ): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $businesses = $businessRepo->findBy(['owner' => $user], ['createdAt' => 'ASC']);
        return $this->render('owner/businesses.html.twig', [
            'businesses' => $businesses,
            'canCreateBusiness' => $subscriptionService->canCreateBusiness($user, count($businesses)),
            'accessContext' => $subscriptionService->getAccessContext($user),
        ]);
    }

    #[Route('/owner/businesses/new', name: 'app_owner_business_new')]
    #[Route('/owner/businesses/{id}/edit', name: 'app_owner_business_edit', requirements: ['id' => '\d+'])]
    public function form(
        Request $request,
        BusinessRepository $businessRepo,
        EntityManagerInterface $em,
        ImageUploadService $imageUpload,
        SubscriptionService $subscriptionService,
        ?int $id = null,
    ): Response {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $business = $id ? $businessRepo->findOneBy(['id' => $id, 'owner' => $user]) : null;
        if ($id && !$business) throw $this->createNotFoundException();
        if (!$business) {
            $businessCount = $businessRepo->count(['owner' => $user]);
            if (!$subscriptionService->canCreateBusiness($user, $businessCount)) {
                $this->addFlash(
                    'info',
                    $subscriptionService->businessLimitMessage($user, $businessCount),
                );
                return $this->redirectToRoute('app_owner_businesses');
            }
        }
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('business', $request->request->get('_token'))) {
                $error = 'Invalid security token.';
            } else {
                $name     = trim($request->request->get('name', ''));
                $logoFile = $request->files->get('logo');

                if (!$name) {
                    $error = 'Business name is required.';
                } else {
                    if (!$error) {
                        $isNew = !$business;
                        if ($isNew) {
                            $business = new Business();
                            $business->setOwner($user);
                            $em->persist($business);
                        }
                        $business->setName($name);

                        // Assign slug only if not set yet (stable — never changed after creation)
                        if (!$business->getSlug()) {
                            $business->setSlug($this->uniqueSlug($name, $businessRepo, $business->getId()));
                        }

                        if ($logoFile instanceof UploadedFile) {
                            $previousLogoPath = $business->getLogoPath();
                            $newLogoPath = null;
                            try {
                                $newLogoPath = $imageUpload->uploadBusinessLogo($logoFile, $name);
                                $business->setLogoPath($newLogoPath);
                            } catch (\RuntimeException $e) {
                                $error = $e->getMessage();
                            }
                        }

                        if (!$error) {
                            $business->setUpdatedAt(new \DateTimeImmutable());
                            try {
                                $em->flush();
                            } catch (\Throwable $e) {
                                $imageUpload->delete($newLogoPath ?? null);
                                throw $e;
                            }
                            if (($newLogoPath ?? null) && ($previousLogoPath ?? null)) {
                                $imageUpload->delete($previousLogoPath);
                            }
                            $this->addFlash('success', $isNew ? 'Business created.' : 'Business updated.');
                            return $this->redirectAfterModalSubmit($request, 'app_owner_businesses');
                        }
                    }
                }
            }
        }

        return $this->render('owner/business_form.html.twig', [
            'business' => $business,
            'error'    => $error,
        ]);
    }

    #[Route('/owner/businesses/{id}/delete', name: 'app_owner_business_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function delete(
        int $id,
        Request $request,
        BusinessRepository $businessRepo,
        EntityManagerInterface $em,
    ): Response {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $business = $businessRepo->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$business) throw $this->createNotFoundException();
        if (!$this->isCsrfTokenValid('delete-business-' . $id, $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page and try deleting the business again.');
            return $this->redirectToRoute('app_owner_businesses');
        }
        $em->remove($business);
        $em->flush();
        $this->addFlash('success', 'Business deleted.');
        return $this->redirectToRoute('app_owner_businesses');
    }

    // ── Account Settings ───────────────────────────────────────────────────────

    #[Route('/owner/account', name: 'app_owner_account')]
    public function account(SubscriptionRepository $subRepo): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $sub  = $subRepo->findOneBy(['owner' => $user]);

        return $this->render('owner/account.html.twig', [
            'sub' => $sub,
        ]);
    }

    #[Route('/owner/account/cancel-subscription', name: 'app_owner_account_cancel_sub', methods: ['POST'])]
    public function cancelSubscription(
        Request $request,
        SubscriptionRepository $subRepo,
        SubscriptionService $service,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher,
        TotpAuthenticatorInterface $totpAuth,
    ): Response {
        if (!$this->isCsrfTokenValid('cancel-subscription', $request->request->get('_token'))) {
            $this->addFlash('error', 'Your security session expired. Refresh the page before trying again.');
            return $this->redirectToRoute('app_owner_account');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $sub  = $subRepo->findOneBy(['owner' => $user]);

        // Verify password
        $password = $request->request->get('password', '');
        if (!$passwordHasher->isPasswordValid($user, $password)) {
            $this->addFlash('error', 'Incorrect password. Subscription not cancelled.');
            return $this->redirectToRoute('app_owner_account');
        }

        // Verify 2FA code if enabled
        if ($user->isTotpAuthenticationEnabled()) {
            $code = $request->request->get('auth_code', '');
            if (!$totpAuth->checkCode($user, $code)) {
                $this->addFlash('error', 'Invalid 2FA code. Subscription not cancelled.');
                return $this->redirectToRoute('app_owner_account');
            }
        }

        // Cancel subscription
        if ($sub && ($sub->isActive() || $sub->getStatus() === 'pending')) {
            $service->cancelStripeSubscription($sub);
            $sub->setStatus(\App\Entity\Subscription::STATUS_CANCELLED);
            $em->flush();
            $this->addFlash('success', 'Subscription cancelled successfully. Your menu data has been preserved.');
        } else {
            $this->addFlash('error', 'No active subscription to cancel.');
        }

        return $this->redirectToRoute('app_owner_account');
    }

    #[Route('/owner/account/delete', name: 'app_owner_account_delete', methods: ['POST'])]
    public function deleteAccount(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        TotpAuthenticatorInterface $totpAuth,
        AccountDeletionService $deletion,
        EntitlementService $entitlements,
        TokenStorageInterface $tokenStorage,
    ): Response {
        if (!$this->isCsrfTokenValid('delete-account', $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid security token.');
        }

        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        $failureRoute = $entitlements->hasAccess($user)
            ? 'app_owner_account'
            : 'app_trial_expired';
        if ($request->request->get('confirmation') !== 'YES') {
            $this->addFlash('error', 'Type YES exactly to confirm account deletion.');
            return $this->redirectToRoute($failureRoute);
        }
        if (!$passwordHasher->isPasswordValid($user, (string) $request->request->get('password'))) {
            $this->addFlash('error', 'Incorrect password. Your account was not deleted.');
            return $this->redirectToRoute($failureRoute);
        }
        if ($user->isTotpAuthenticationEnabled()
            && !$totpAuth->checkCode($user, (string) $request->request->get('auth_code'))) {
            $this->addFlash('error', 'Invalid 2FA code. Your account was not deleted.');
            return $this->redirectToRoute($failureRoute);
        }

        try {
            $blockedUntil = $deletion->delete($user);
        } catch (\Throwable) {
            $this->addFlash('error', 'Account deletion could not be completed. Your account remains available; please try again or contact support.');
            return $this->redirectToRoute($failureRoute);
        }

        $tokenStorage->setToken(null);
        $session = $request->getSession();
        $session->invalidate();
        $session->getFlashBag()->add(
            'success',
            sprintf('Your account was deleted. This email can be used again after %s.', $blockedUntil->format('F j, Y')),
        );
        $response = $this->redirectToRoute('app_home');
        $response->headers->clearCookie('REMEMBERME');
        return $response;
    }

    // ── Slug helpers ───────────────────────────────────────────────────────

    private function uniqueSlug(string $name, BusinessRepository $repo, ?int $excludeId = null): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $i    = 1;
        while (true) {
            $existing = $repo->findOneBy(['slug' => $slug]);
            if (!$existing || $existing->getId() === $excludeId) {
                break;
            }
            $slug = $base . '-' . $i++;
        }
        return $slug;
    }

    private function slugify(string $s): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s;
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        return trim($s, '-');
    }
}


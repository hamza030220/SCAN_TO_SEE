<?php

namespace App\Controller;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use App\Repository\AdminAuditLogRepository;
use App\Repository\BusinessRepository;
use App\Repository\MenuRepository;
use App\Repository\UserRepository;
use App\Service\AccountDeletionService;
use App\Service\AdminAuditService;
use Doctrine\ORM\EntityManagerInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
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

    #[Route('/audit', name: 'app_admin_audit', methods: ['GET'])]
    public function audit(Request $request, AdminAuditLogRepository $logs): Response
    {
        $page = max(1, $request->query->getInt('page', 1));
        $perPage = 50;
        $total = $logs->count([]);

        return $this->render('admin/audit.html.twig', [
            'logs' => $logs->findPage($page, $perPage),
            'page' => $page,
            'pageCount' => max(1, (int) ceil($total / $perPage)),
            'total' => $total,
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

    #[Route(
        '/owners/{id}/confirm/{action}',
        name: 'app_admin_owner_confirm',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'action' => 'activate|deactivate|delete'],
    )]
    public function ownerConfirm(User $owner, string $action): Response
    {
        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        if (($action === 'activate' && $owner->isActive())
            || ($action === 'deactivate' && !$owner->isActive())) {
            $this->addFlash('error', 'This account already has the requested status.');
            return $this->redirectToRoute('app_admin_owner_show', ['id' => $owner->getId()]);
        }

        return $this->render('admin/owner_confirm.html.twig', [
            'owner' => $owner,
            'action' => $action,
        ]);
    }

    /* ── Toggle active / inactive ─────────────────────────────────── */

    #[Route('/owners/{id}/toggle', name: 'app_admin_owner_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ownerToggle(
        User $owner,
        Request $request,
        EntityManagerInterface $em,
        AdminAuditService $audit,
    ): Response
    {
        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('toggle-owner-' . $owner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_owners');
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $reason = trim((string) $request->request->get('reason'));
        $desiredValue = (string) $request->request->get('desired_state');
        $desiredState = $desiredValue === '1';
        $action = $desiredState ? 'owner.activate' : 'owner.deactivate';
        $log = $audit->start(
            $admin,
            $action,
            'owner',
            $owner->getId(),
            (string) $owner->getEmail(),
            $reason !== '' ? $reason : null,
            $request,
            ['isActive' => $owner->isActive()],
        );

        $validationError = match (true) {
            !in_array($desiredValue, ['0', '1'], true) => 'Invalid requested account status.',
            $reason === '' => 'A reason is required.',
            mb_strlen($reason) > 1000 => 'The reason is too long.',
            $request->request->get('confirmation') !== 'CONFIRM' => 'Type CONFIRM exactly to continue.',
            $owner->isActive() === $desiredState => 'This account already has the requested status.',
            default => null,
        };
        if ($validationError !== null) {
            $audit->finish($log, AdminAuditLog::OUTCOME_DENIED, errorMessage: $validationError);
            $this->addFlash('error', $validationError);

            return $this->redirectToRoute('app_admin_owner_confirm', [
                'id' => $owner->getId(),
                'action' => $desiredState ? 'activate' : 'deactivate',
            ]);
        }

        try {
            $owner->setIsActive($desiredState);
            $em->flush();
            $audit->finish($log, AdminAuditLog::OUTCOME_SUCCESS, ['isActive' => $desiredState]);
        } catch (\Throwable $e) {
            $audit->finish($log, AdminAuditLog::OUTCOME_FAILED, errorMessage: $e->getMessage());
            $this->addFlash('error', 'The account status could not be changed.');

            return $this->redirectToRoute('app_admin_owner_show', ['id' => $owner->getId()]);
        }

        $state = $desiredState ? 'activated' : 'deactivated';
        $this->addFlash('success', "Account {$state}: {$owner->getEmail()}");

        return $this->redirectToRoute('app_admin_owner_show', ['id' => $owner->getId()]);
    }

    /* ── Delete owner ─────────────────────────────────────────────── */

    #[Route('/owners/{id}/delete', name: 'app_admin_owner_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function ownerDelete(
        User $owner,
        Request $request,
        AccountDeletionService $accountDeletion,
        AdminAuditService $audit,
        UserPasswordHasherInterface $passwordHasher,
        TotpAuthenticatorInterface $totpAuthenticator,
    ): Response
    {
        if ($owner->getRole() !== 'owner') {
            throw $this->createNotFoundException();
        }

        if (!$this->isCsrfTokenValid('delete-owner-' . $owner->getId(), $request->request->get('_token'))) {
            $this->addFlash('error', 'Invalid CSRF token.');
            return $this->redirectToRoute('app_admin_owners');
        }

        $email = (string) $owner->getEmail();
        /** @var User $admin */
        $admin = $this->getUser();
        $reason = trim((string) $request->request->get('reason'));
        $log = $audit->start(
            $admin,
            'owner.delete',
            'owner',
            $owner->getId(),
            $email,
            $reason !== '' ? $reason : null,
            $request,
            ['isActive' => $owner->isActive()],
        );

        $typedEmail = mb_strtolower(trim((string) $request->request->get('target_email')));
        $validationError = match (true) {
            $reason === '' => 'A reason is required.',
            mb_strlen($reason) > 1000 => 'The reason is too long.',
            $typedEmail !== mb_strtolower($email) => 'Type the owner email exactly to continue.',
            $request->request->get('confirmation') !== 'DELETE' => 'Type DELETE exactly to continue.',
            !$passwordHasher->isPasswordValid($admin, (string) $request->request->get('admin_password')) => 'Your administrator password is incorrect.',
            !$admin->isTotpAuthenticationEnabled() => 'Administrator two-factor authentication must be enabled.',
            !$totpAuthenticator->checkCode($admin, (string) $request->request->get('auth_code')) => 'The two-factor authentication code is invalid.',
            default => null,
        };
        if ($validationError !== null) {
            $audit->finish($log, AdminAuditLog::OUTCOME_DENIED, errorMessage: $validationError);
            $this->addFlash('error', $validationError);

            return $this->redirectToRoute('app_admin_owner_confirm', [
                'id' => $owner->getId(),
                'action' => 'delete',
            ]);
        }

        try {
            $blockedUntil = $accountDeletion->delete($owner);
            $audit->finish(
                $log,
                AdminAuditLog::OUTCOME_SUCCESS,
                ['emailBlockedUntil' => $blockedUntil->format(DATE_ATOM)],
            );
        } catch (\Throwable $e) {
            $audit->finish($log, AdminAuditLog::OUTCOME_FAILED, errorMessage: $e->getMessage());
            $this->addFlash(
                'error',
                'The account could not be deleted safely. No local deletion was completed; check Stripe and application logs before retrying.',
            );

            return $this->redirectToRoute('app_admin_owner_show', ['id' => $owner->getId()]);
        }

        $this->addFlash(
            'success',
            sprintf(
                'Owner account deleted: %s. The email remains blocked until %s.',
                $email,
                $blockedUntil->format('F j, Y'),
            ),
        );

        return $this->redirectToRoute('app_admin_owners');
    }
}


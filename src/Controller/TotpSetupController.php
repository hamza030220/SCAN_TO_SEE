<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/2fa/setup')]
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
class TotpSetupController extends AbstractController
{
    public function __construct(
        private TotpAuthenticatorInterface $totpAuthenticator,
        private EntityManagerInterface $em,
    ) {}

    #[Route('', name: 'app_2fa_setup', methods: ['GET'])]
    public function setup(Request $request): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        // Already fully set up (secret + backup codes confirmed) — redirect away
        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Clear any orphaned secret (saved by a previous abandoned setup visit)
        if ($user->getTotpSecret() !== null) {
            $user->setTotpSecret(null);
            $this->em->flush();
        }

        // Keep the secret in the SESSION — never persist to DB until confirmed
        $session = $request->getSession();
        $secret = $session->get('totp_setup_secret');
        if (!$secret) {
            $secret = $this->totpAuthenticator->generateSecret();
            $session->set('totp_setup_secret', $secret);
        }

        // Temporarily set secret on entity for QR generation — do NOT flush
        $user->setTotpSecret($secret);
        $qrCodeUri = $this->generateQrCodeUri($user);
        $user->setTotpSecret(null); // revert: Doctrine sees no net change, nothing persisted

        return $this->render('security/2fa_setup.html.twig', [
            'qr_code_uri' => $qrCodeUri,
            'totp_secret' => $secret,
        ]);
    }

    #[Route('/confirm', name: 'app_2fa_setup_confirm', methods: ['POST'])]
    public function confirm(Request $request, RateLimiterFactory $twoFactorSetupLimiter): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        if ($user->isTotpAuthenticationEnabled()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if (!$this->isCsrfTokenValid('totp-setup', $request->request->get('_token'))) {
            $this->addFlash('2fa_error', 'Your security session expired. Reload the setup page and try again.');
            return $this->redirectToRoute('app_2fa_setup');
        }

        $limit = $twoFactorSetupLimiter->create((string) $user->getId())->consume();
        if (!$limit->isAccepted()) {
            $this->addFlash('2fa_error', 'Too many incorrect attempts. Wait 15 minutes before trying again.');
            return $this->redirectToRoute('app_2fa_setup');
        }

        $session = $request->getSession();
        $secret = $session->get('totp_setup_secret');

        // No session secret means the user skipped the setup page — restart
        if (!$secret) {
            return $this->redirectToRoute('app_2fa_setup');
        }

        $code = $request->request->get('code', '');

        // Temporarily set secret so the authenticator can validate the code
        $user->setTotpSecret($secret);

        if (!$this->totpAuthenticator->checkCode($user, $code)) {
            $user->setTotpSecret(null); // revert — nothing persisted
            $this->addFlash('2fa_error', 'Invalid code. Make sure your phone time is correct and try again.');
            return $this->redirectToRoute('app_2fa_setup');
        }

        // Code confirmed — generate backup codes and persist everything at once
        $backupCodes = [];
        for ($i = 0; $i < 8; $i++) {
            $backupCodes[] = strtoupper(bin2hex(random_bytes(4)));
        }
        $user->setBackupCodes($backupCodes);
        $this->em->flush(); // saves totp_secret + backup_codes together

        $session->remove('totp_setup_secret');

        return $this->render('security/2fa_backup_codes.html.twig', [
            'backup_codes' => $backupCodes,
        ]);
    }

    private function generateQrCodeUri(User $user): string
    {
        $totpUri = $this->totpAuthenticator->getQRContent($user);

        $result = (new Builder(
            data: $totpUri,
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: 250,
            margin: 10,
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        ))->build();

        return $result->getDataUri();
    }
}

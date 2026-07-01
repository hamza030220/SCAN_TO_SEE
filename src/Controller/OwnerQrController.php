<?php

namespace App\Controller;

use App\Repository\BusinessRepository;
use Endroid\QrCode\Builder\BuilderInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
class OwnerQrController extends AbstractController
{
    public function __construct(
        #[Autowire('%env(PUBLIC_BASE_URL)%')]
        private readonly string $publicBaseUrl,
    ) {}

    #[Route('/owner/businesses/{id}/qr', name: 'app_owner_business_qr', requirements: ['id' => '\d+'])]
    public function show(
        int $id,
        BusinessRepository $businessRepo,
        BuilderInterface $defaultQrCodeBuilder,
    ): Response {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $business = $businessRepo->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$business) {
            throw $this->createNotFoundException();
        }

        if (!$business->getSlug()) {
            throw $this->createNotFoundException('Business has no slug.');
        }

        $publicUrl = rtrim($this->publicBaseUrl, '/') . '/m/' . $business->getSlug();

        $result = $defaultQrCodeBuilder->build(
            data:   $publicUrl,
            size:   300,
            margin: 14,
        );

        return $this->render('owner/business_qr.html.twig', [
            'business'  => $business,
            'qrDataUri' => $result->getDataUri(),
            'publicUrl' => $publicUrl,
        ]);
    }

    #[Route('/owner/businesses/{id}/qr/download', name: 'app_owner_business_qr_download', requirements: ['id' => '\d+'])]
    public function download(
        int $id,
        BusinessRepository $businessRepo,
        BuilderInterface $defaultQrCodeBuilder,
    ): Response {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $business = $businessRepo->findOneBy(['id' => $id, 'owner' => $user]);
        if (!$business || !$business->getSlug()) {
            throw $this->createNotFoundException();
        }

        $publicUrl = rtrim($this->publicBaseUrl, '/') . '/m/' . $business->getSlug();

        $result = $defaultQrCodeBuilder->build(
            data:   $publicUrl,
            size:   600,
            margin: 24,
        );

        $filename = 'qr-' . $business->getSlug() . '.png';

        return new Response(
            $result->getString(),
            200,
            [
                'Content-Type'        => $result->getMimeType(),
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            ]
        );
    }
}

<?php

namespace App\Controller;

use App\Entity\Business;
use App\Repository\BusinessRepository;
use App\Service\ImageUploadService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_OWNER')]
final class OwnerProfileController extends AbstractController
{
    // Redirect old /owner/profile to /owner/businesses
    #[Route('/owner/profile', name: 'app_owner_profile')]
    public function profileRedirect(): Response
    {
        return $this->redirectToRoute('app_owner_businesses');
    }

    #[Route('/owner/businesses', name: 'app_owner_businesses')]
    public function list(BusinessRepository $businessRepo): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        return $this->render('owner/businesses.html.twig', [
            'businesses' => $businessRepo->findBy(['owner' => $user], ['createdAt' => 'ASC']),
        ]);
    }

    #[Route('/owner/businesses/new', name: 'app_owner_business_new')]
    #[Route('/owner/businesses/{id}/edit', name: 'app_owner_business_edit', requirements: ['id' => '\d+'])]
    public function form(
        Request $request,
        BusinessRepository $businessRepo,
        EntityManagerInterface $em,
        ImageUploadService $imageUpload,
        ?int $id = null,
    ): Response {
        /** @var \App\Entity\User $user */
        $user     = $this->getUser();
        $business = $id ? $businessRepo->findOneBy(['id' => $id, 'owner' => $user]) : null;
        if ($id && !$business) throw $this->createNotFoundException();
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
                            try {
                                $imageUpload->delete($business->getLogoPath());
                                $business->setLogoPath(
                                    $imageUpload->uploadBusinessLogo($logoFile, $name)
                                );
                            } catch (\RuntimeException $e) {
                                $error = $e->getMessage();
                            }
                        }

                        if (!$error) {
                            $business->setUpdatedAt(new \DateTimeImmutable());
                            $em->flush();
                            $this->addFlash('success', $isNew ? 'Business created.' : 'Business updated.');
                            return $this->redirectToRoute('app_owner_businesses');
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
            throw $this->createAccessDeniedException();
        }
        $em->remove($business);
        $em->flush();
        $this->addFlash('success', 'Business deleted.');
        return $this->redirectToRoute('app_owner_businesses');
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


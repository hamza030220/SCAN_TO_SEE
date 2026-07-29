<?php

namespace App\Service;

use App\Entity\DeletedEmailBlock;
use App\Repository\DeletedEmailBlockRepository;
use Doctrine\ORM\EntityManagerInterface;

class EmailBlockService
{
    public function __construct(
        private readonly DeletedEmailBlockRepository $repository,
        private readonly EntityManagerInterface $em,
        private readonly string $hashSecret,
    ) {}

    public function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function activeBlock(string $email): ?DeletedEmailBlock
    {
        $block = $this->repository->findOneBy(['emailHash' => $this->hash($email)]);
        if (!$block) {
            return null;
        }
        if ($block->getBlockedUntil() <= new \DateTimeImmutable()) {
            $this->em->remove($block);
            $this->em->flush();
            return null;
        }
        return $block;
    }

    public function block(string $email): DeletedEmailBlock
    {
        $hash = $this->hash($email);
        $block = $this->repository->findOneBy(['emailHash' => $hash]) ?? new DeletedEmailBlock();
        $block
            ->setEmailHash($hash)
            ->setBlockedUntil(new \DateTimeImmutable('+30 days'));
        $this->em->persist($block);
        return $block;
    }

    private function hash(string $email): string
    {
        return hash_hmac('sha256', $this->normalize($email), $this->hashSecret);
    }
}

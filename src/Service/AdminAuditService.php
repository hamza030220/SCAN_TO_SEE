<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class AdminAuditService
{
    public function __construct(private readonly EntityManagerInterface $em) {}

    public function start(
        User $actor,
        string $action,
        string $targetType,
        ?int $targetId,
        string $targetLabel,
        ?string $reason,
        Request $request,
        ?array $beforeState = null,
    ): AdminAuditLog {
        $log = (new AdminAuditLog())
            ->setActor($actor)
            ->setActorEmail((string) $actor->getEmail())
            ->setAction($action)
            ->setTargetType($targetType)
            ->setTargetId($targetId)
            ->setTargetLabel($targetLabel)
            ->setReason($reason)
            ->setIpAddress($request->getClientIp())
            ->setBeforeState($beforeState);

        $this->em->persist($log);
        $this->em->flush();

        return $log;
    }

    public function finish(
        AdminAuditLog $log,
        string $outcome,
        ?array $afterState = null,
        ?string $errorMessage = null,
    ): void {
        $log
            ->setOutcome($outcome)
            ->setAfterState($afterState)
            ->setErrorMessage($errorMessage !== null ? mb_substr($errorMessage, 0, 255) : null)
            ->setCompletedAt(new \DateTimeImmutable());

        $this->em->flush();
    }
}

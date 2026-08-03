<?php

namespace App\Service;

use App\Entity\AdminAuditLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;

class AdminAuditService
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {}

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
    ): bool {
        $completedAt = new \DateTimeImmutable();
        $log
            ->setOutcome($outcome)
            ->setAfterState($afterState)
            ->setErrorMessage($errorMessage !== null ? mb_substr($errorMessage, 0, 255) : null)
            ->setCompletedAt($completedAt);

        try {
            if ($log->getId() === null) {
                $this->em->flush();
            } else {
                // A destructive action can remove a large entity graph and
                // complete its own transaction. Finalize the already-created
                // audit row directly so a stale UnitOfWork cannot turn a
                // successful deletion into an HTTP 500 response.
                $this->em->getConnection()->executeStatement(
                    <<<'SQL'
                        UPDATE admin_audit_log
                        SET outcome = :outcome,
                            after_state = :after_state,
                            error_message = :error_message,
                            completed_at = :completed_at
                        WHERE id = :id
                    SQL,
                    [
                        'outcome' => $outcome,
                        'after_state' => $afterState !== null
                            ? json_encode($afterState, JSON_THROW_ON_ERROR)
                            : null,
                        'error_message' => $log->getErrorMessage(),
                        'completed_at' => $completedAt->format('Y-m-d H:i:s'),
                        'id' => $log->getId(),
                    ],
                );
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Administrator audit log could not be finalized.', [
                'audit_id' => $log->getId(),
                'outcome' => $outcome,
                'exception' => $e,
            ]);

            return false;
        }
    }
}

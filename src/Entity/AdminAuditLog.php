<?php

namespace App\Entity;

use App\Repository\AdminAuditLogRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AdminAuditLogRepository::class)]
#[ORM\Index(name: 'IDX_ADMIN_AUDIT_CREATED', columns: ['created_at'])]
#[ORM\Index(name: 'IDX_ADMIN_AUDIT_ACTION', columns: ['action'])]
#[ORM\Index(name: 'IDX_ADMIN_AUDIT_OUTCOME', columns: ['outcome'])]
class AdminAuditLog
{
    public const OUTCOME_STARTED = 'started';
    public const OUTCOME_SUCCESS = 'success';
    public const OUTCOME_DENIED = 'denied';
    public const OUTCOME_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\Column(length: 180)]
    private string $actorEmail;

    #[ORM\Column(length: 64)]
    private string $action;

    #[ORM\Column(length: 64)]
    private string $targetType;

    #[ORM\Column(nullable: true)]
    private ?int $targetId = null;

    #[ORM\Column(length: 255)]
    private string $targetLabel;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(length: 20)]
    private string $outcome = self::OUTCOME_STARTED;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $beforeState = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $afterState = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getActor(): ?User { return $this->actor; }
    public function setActor(?User $value): static { $this->actor = $value; return $this; }
    public function getActorEmail(): string { return $this->actorEmail; }
    public function setActorEmail(string $value): static { $this->actorEmail = $value; return $this; }
    public function getAction(): string { return $this->action; }
    public function setAction(string $value): static { $this->action = $value; return $this; }
    public function getTargetType(): string { return $this->targetType; }
    public function setTargetType(string $value): static { $this->targetType = $value; return $this; }
    public function getTargetId(): ?int { return $this->targetId; }
    public function setTargetId(?int $value): static { $this->targetId = $value; return $this; }
    public function getTargetLabel(): string { return $this->targetLabel; }
    public function setTargetLabel(string $value): static { $this->targetLabel = $value; return $this; }
    public function getReason(): ?string { return $this->reason; }
    public function setReason(?string $value): static { $this->reason = $value; return $this; }
    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $value): static { $this->ipAddress = $value; return $this; }
    public function getOutcome(): string { return $this->outcome; }
    public function setOutcome(string $value): static { $this->outcome = $value; return $this; }
    public function getBeforeState(): ?array { return $this->beforeState; }
    public function setBeforeState(?array $value): static { $this->beforeState = $value; return $this; }
    public function getAfterState(): ?array { return $this->afterState; }
    public function setAfterState(?array $value): static { $this->afterState = $value; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $value): static { $this->errorMessage = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getCompletedAt(): ?\DateTimeImmutable { return $this->completedAt; }
    public function setCompletedAt(?\DateTimeImmutable $value): static { $this->completedAt = $value; return $this; }
}

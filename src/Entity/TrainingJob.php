<?php

namespace App\Entity;

use App\Repository\TrainingJobRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TrainingJobRepository::class)]
#[ORM\Index(name: 'IDX_TRAINING_STATUS_CREATED', columns: ['status', 'created_at'])]
class TrainingJob
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_EXPORTING = 'exporting';
    public const STATUS_TRAINING = 'training';
    public const STATUS_EVALUATING = 'evaluating';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    public const ACTIVE_STATUSES = [
        self::STATUS_QUEUED,
        self::STATUS_EXPORTING,
        self::STATUS_TRAINING,
        self::STATUS_EVALUATING,
    ];

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $requestedBy = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(length: 80)]
    private string $phase = 'Waiting for the training worker';

    #[ORM\Column]
    private int $progress = 0;

    #[ORM\Column(type: 'json')]
    private array $parameters = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $datasetSummary = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $baselineMetrics = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $candidateMetrics = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $datasetPath = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $candidatePath = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $recommendation = null;

    #[ORM\Column]
    private bool $stopRequested = false;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $logExcerpt = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $errorMessage = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    public function __construct() { $this->createdAt = new \DateTimeImmutable(); }

    public function getId(): ?int { return $this->id; }
    public function getRequestedBy(): ?User { return $this->requestedBy; }
    public function setRequestedBy(User $value): static { $this->requestedBy = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): static { $this->status = $value; return $this; }
    public function getPhase(): string { return $this->phase; }
    public function setPhase(string $value): static { $this->phase = $value; return $this; }
    public function getProgress(): int { return $this->progress; }
    public function setProgress(int $value): static { $this->progress = max(0, min(100, $value)); return $this; }
    public function getParameters(): array { return $this->parameters; }
    public function setParameters(array $value): static { $this->parameters = $value; return $this; }
    public function getDatasetSummary(): ?array { return $this->datasetSummary; }
    public function setDatasetSummary(?array $value): static { $this->datasetSummary = $value; return $this; }
    public function getBaselineMetrics(): ?array { return $this->baselineMetrics; }
    public function setBaselineMetrics(?array $value): static { $this->baselineMetrics = $value; return $this; }
    public function getCandidateMetrics(): ?array { return $this->candidateMetrics; }
    public function setCandidateMetrics(?array $value): static { $this->candidateMetrics = $value; return $this; }
    public function getDatasetPath(): ?string { return $this->datasetPath; }
    public function setDatasetPath(?string $value): static { $this->datasetPath = $value; return $this; }
    public function getCandidatePath(): ?string { return $this->candidatePath; }
    public function setCandidatePath(?string $value): static { $this->candidatePath = $value; return $this; }
    public function getRecommendation(): ?string { return $this->recommendation; }
    public function setRecommendation(?string $value): static { $this->recommendation = $value; return $this; }
    public function isStopRequested(): bool { return $this->stopRequested; }
    public function setStopRequested(bool $value): static { $this->stopRequested = $value; return $this; }
    public function getLogExcerpt(): ?string { return $this->logExcerpt; }
    public function setLogExcerpt(?string $value): static { $this->logExcerpt = $value; return $this; }
    public function getErrorMessage(): ?string { return $this->errorMessage; }
    public function setErrorMessage(?string $value): static { $this->errorMessage = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getStartedAt(): ?\DateTimeImmutable { return $this->startedAt; }
    public function setStartedAt(?\DateTimeImmutable $value): static { $this->startedAt = $value; return $this; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function setFinishedAt(?\DateTimeImmutable $value): static { $this->finishedAt = $value; return $this; }
    public function isActive(): bool { return in_array($this->status, self::ACTIVE_STATUSES, true); }
}

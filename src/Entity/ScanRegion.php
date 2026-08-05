<?php

namespace App\Entity;

use App\Repository\ScanRegionRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScanRegionRepository::class)]
#[ORM\Table(name: 'scan_region')]
#[ORM\UniqueConstraint(name: 'uniq_scan_region_box', columns: ['scan_id', 'box_id'])]
#[ORM\Index(name: 'IDX_SCAN_REGION_REVIEW', columns: ['review_outcome'])]
class ScanRegion
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'regions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?ScanCapture $scan = null;

    #[ORM\Column]
    private int $boxId;

    #[ORM\Column(length: 30)]
    private string $role;

    #[ORM\Column(nullable: true)]
    private ?int $pairBoxId = null;

    #[ORM\Column(nullable: true)]
    private ?int $groupBoxId = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $geometry = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $cropUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cropPublicId = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $cropAssetId = null;

    #[ORM\Column(type: 'text')]
    private string $rawText = '';

    #[ORM\Column(type: 'float')]
    private float $confidence = 0.0;

    #[ORM\Column(type: 'json')]
    private array $rawJson = [];

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $correctedText = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $reviewOutcome = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $correctedAt = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $excludedFromTraining = false;

    #[ORM\Column(length: 500, nullable: true)]
    private ?string $exclusionReason = null;

    public function getId(): ?int { return $this->id; }
    public function getScan(): ?ScanCapture { return $this->scan; }
    public function setScan(ScanCapture $value): static { $this->scan = $value; return $this; }
    public function getBoxId(): int { return $this->boxId; }
    public function setBoxId(int $value): static { $this->boxId = $value; return $this; }
    public function getRole(): string { return $this->role; }
    public function setRole(string $value): static { $this->role = $value; return $this; }
    public function getPairBoxId(): ?int { return $this->pairBoxId; }
    public function setPairBoxId(?int $value): static { $this->pairBoxId = $value; return $this; }
    public function getGroupBoxId(): ?int { return $this->groupBoxId; }
    public function setGroupBoxId(?int $value): static { $this->groupBoxId = $value; return $this; }
    public function getGeometry(): ?array { return $this->geometry; }
    public function setGeometry(?array $value): static { $this->geometry = $value; return $this; }
    public function getCropUrl(): ?string { return $this->cropUrl; }
    public function setCropUrl(?string $value): static { $this->cropUrl = $value; return $this; }
    public function getCropPublicId(): ?string { return $this->cropPublicId; }
    public function setCropPublicId(?string $value): static { $this->cropPublicId = $value; return $this; }
    public function getCropAssetId(): ?string { return $this->cropAssetId; }
    public function setCropAssetId(?string $value): static { $this->cropAssetId = $value; return $this; }
    public function getRawText(): string { return $this->rawText; }
    public function setRawText(string $value): static { $this->rawText = $value; return $this; }
    public function getConfidence(): float { return $this->confidence; }
    public function setConfidence(float $value): static { $this->confidence = $value; return $this; }
    public function getRawJson(): array { return $this->rawJson; }
    public function setRawJson(array $value): static { $this->rawJson = $value; return $this; }
    public function getCorrectedText(): ?string { return $this->correctedText; }
    public function setCorrectedText(?string $value): static { $this->correctedText = $value; return $this; }
    public function getReviewOutcome(): ?string { return $this->reviewOutcome; }
    public function setReviewOutcome(?string $value): static { $this->reviewOutcome = $value; return $this; }
    public function getCorrectedAt(): ?\DateTimeImmutable { return $this->correctedAt; }
    public function setCorrectedAt(?\DateTimeImmutable $value): static { $this->correctedAt = $value; return $this; }
    public function isExcludedFromTraining(): bool { return $this->excludedFromTraining; }
    public function setExcludedFromTraining(bool $value): static { $this->excludedFromTraining = $value; return $this; }
    public function getExclusionReason(): ?string { return $this->exclusionReason; }
    public function setExclusionReason(?string $value): static { $this->exclusionReason = $value; return $this; }
}

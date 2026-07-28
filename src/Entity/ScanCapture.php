<?php

namespace App\Entity;

use App\Repository\ScanCaptureRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ScanCaptureRepository::class)]
#[ORM\Table(name: 'scan_capture')]
#[ORM\Index(name: 'IDX_SCAN_CAPTURE_STATUS_CREATED', columns: ['status', 'created_at'])]
#[ORM\Index(name: 'IDX_SCAN_CAPTURE_MODEL', columns: ['model_version'])]
class ScanCapture
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36, unique: true)]
    private string $scanUuid;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?User $owner = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Business $business = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Menu $menu = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $originalImageUrl = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalPublicId = null;

    #[ORM\Column(length: 100)]
    private string $modelVersion = 'unknown';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $inferenceManifest = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $qualityMetrics = null;

    #[ORM\Column(type: 'json')]
    private array $rawResponse = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $correctedResponse = null;

    #[ORM\Column(length: 20)]
    private string $status = 'pending';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $reviewedAt = null;

    #[ORM\OneToMany(mappedBy: 'scan', targetEntity: ScanRegion::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['boxId' => 'ASC'])]
    private Collection $regions;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->regions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getScanUuid(): string { return $this->scanUuid; }
    public function setScanUuid(string $value): static { $this->scanUuid = $value; return $this; }
    public function getOwner(): ?User { return $this->owner; }
    public function setOwner(User $value): static { $this->owner = $value; return $this; }
    public function getBusiness(): ?Business { return $this->business; }
    public function setBusiness(?Business $value): static { $this->business = $value; return $this; }
    public function getMenu(): ?Menu { return $this->menu; }
    public function setMenu(?Menu $value): static { $this->menu = $value; return $this; }
    public function getOriginalImageUrl(): ?string { return $this->originalImageUrl; }
    public function setOriginalImageUrl(?string $value): static { $this->originalImageUrl = $value; return $this; }
    public function getOriginalPublicId(): ?string { return $this->originalPublicId; }
    public function setOriginalPublicId(?string $value): static { $this->originalPublicId = $value; return $this; }
    public function getModelVersion(): string { return $this->modelVersion; }
    public function setModelVersion(string $value): static { $this->modelVersion = $value; return $this; }
    public function getInferenceManifest(): ?array { return $this->inferenceManifest; }
    public function setInferenceManifest(?array $value): static { $this->inferenceManifest = $value; return $this; }
    public function getQualityMetrics(): ?array { return $this->qualityMetrics; }
    public function setQualityMetrics(?array $value): static { $this->qualityMetrics = $value; return $this; }
    public function getRawResponse(): array { return $this->rawResponse; }
    public function setRawResponse(array $value): static { $this->rawResponse = $value; return $this; }
    public function getCorrectedResponse(): ?array { return $this->correctedResponse; }
    public function setCorrectedResponse(?array $value): static { $this->correctedResponse = $value; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $value): static { $this->status = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getReviewedAt(): ?\DateTimeImmutable { return $this->reviewedAt; }
    public function setReviewedAt(?\DateTimeImmutable $value): static { $this->reviewedAt = $value; return $this; }
    public function getRegions(): Collection { return $this->regions; }
    public function addRegion(ScanRegion $region): static
    {
        if (!$this->regions->contains($region)) {
            $this->regions->add($region);
            $region->setScan($this);
        }
        return $this;
    }
}

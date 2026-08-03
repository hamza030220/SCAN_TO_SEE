<?php

namespace App\Entity;

use App\Repository\ItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ItemRepository::class)]
#[ORM\Index(name: 'IDX_ITEM_PUBLIC_ORDER', columns: ['category_id', 'is_available', 'sort_order'])]
class Item
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Category $category = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $shortDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $details = null;

    #[ORM\Column(length: 40, nullable: true)]
    private ?string $badge = null;

    #[ORM\Column(type: 'json')]
    private array $dietaryTags = [];

    #[ORM\Column(type: 'json')]
    private array $allergens = [];

    #[ORM\Column(type: 'json')]
    private array $variants = [];

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $availabilityNote = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    private ?string $price = null;

    #[ORM\Column]
    private bool $isAvailable = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imagePath = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): ?Category { return $this->category; }
    public function setCategory(?Category $c): static { $this->category = $c; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getShortDescription(): ?string { return $this->shortDescription; }
    public function setShortDescription(?string $d): static { $this->shortDescription = $d; return $this; }
    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $details): static { $this->details = $details; return $this; }
    public function getBadge(): ?string { return $this->badge; }
    public function setBadge(?string $badge): static { $this->badge = $badge; return $this; }
    public function getDietaryTags(): array { return $this->dietaryTags; }
    public function setDietaryTags(array $tags): static { $this->dietaryTags = array_values($tags); return $this; }
    public function getAllergens(): array { return $this->allergens; }
    public function setAllergens(array $allergens): static { $this->allergens = array_values($allergens); return $this; }
    public function getVariants(): array { return $this->variants; }
    public function setVariants(array $variants): static { $this->variants = array_values($variants); return $this; }
    public function getAvailabilityNote(): ?string { return $this->availabilityNote; }
    public function setAvailabilityNote(?string $note): static { $this->availabilityNote = $note; return $this; }
    public function getPrice(): ?string { return $this->price; }
    public function setPrice(string $price): static { $this->price = $price; return $this; }
    public function isAvailable(): bool { return $this->isAvailable; }
    public function setIsAvailable(bool $v): static { $this->isAvailable = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $s): static { $this->sortOrder = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): static { $this->updatedAt = $dt; return $this; }
    public function getImagePath(): ?string { return $this->imagePath; }
    public function setImagePath(?string $path): static { $this->imagePath = $path; return $this; }
}

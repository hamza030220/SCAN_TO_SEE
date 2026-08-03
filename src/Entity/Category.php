<?php

namespace App\Entity;

use App\Repository\CategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: CategoryRepository::class)]
#[ORM\Index(name: 'IDX_CATEGORY_PUBLIC_ORDER', columns: ['menu_id', 'is_visible', 'sort_order'])]
class Category
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Menu $menu = null;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Category $parent = null;

    #[ORM\OneToMany(mappedBy: 'parent', targetEntity: self::class)]
    private Collection $children;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column]
    private bool $isVisible = true;

    #[ORM\Column]
    private int $sortOrder = 0;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: Item::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->children  = new ArrayCollection();
        $this->items     = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getMenu(): ?Menu { return $this->menu; }
    public function setMenu(?Menu $menu): static { $this->menu = $menu; return $this; }
    public function getParent(): ?Category { return $this->parent; }
    public function setParent(?Category $p): static { $this->parent = $p; return $this; }
    public function getChildren(): Collection { return $this->children; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function isVisible(): bool { return $this->isVisible; }
    public function setIsVisible(bool $v): static { $this->isVisible = $v; return $this; }
    public function getSortOrder(): int { return $this->sortOrder; }
    public function setSortOrder(int $s): static { $this->sortOrder = $s; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getItems(): Collection { return $this->items; }
}

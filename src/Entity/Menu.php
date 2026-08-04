<?php

namespace App\Entity;

use App\Repository\MenuRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: MenuRepository::class)]
#[ORM\Index(name: 'IDX_MENU_BUSINESS_STATUS', columns: ['business_id', 'status'])]
class Menu
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_ARCHIVED = 'archived';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Business $business = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 80, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_DRAFT;

    #[ORM\Column(length: 3)]
    private string $currency = 'TND';

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $themeConfig = null;

    #[ORM\OneToOne(mappedBy: 'menu', targetEntity: MenuHero::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?MenuHero $hero = null;

    public const DEFAULT_THEME = [
        'theme'           => 'light',
        'font'            => 'DM Sans',
        'fontScale'       => 1.0,
        'layout'          => 'list',
        'density'         => 'comfortable',
        'bgType'          => 'solid',
        'bgColor'         => '#f7f4ef',
        'bgGradientStart' => '#f7f4ef',
        'bgGradientEnd'   => '#e8e0d5',
        'bgGradientDir'   => 'to bottom',
        'bgImagePath'     => null,
        'headerBg'        => '#18120a',
        'accent'          => '#E8A020',
        'cardStyle'       => 'flat',
        'cardBg'          => '#ffffff',
        'cardRadius'      => 12,
        'imageShape'      => 'rounded',
        'priceStyle'      => 'accent',
        'priceAlign'      => 'left',
        'priceFont'       => 'Space Grotesk',
        'priceSize'       => 0.9,
        'priceWeight'     => '700',
        'priceColor'      => '#E8A020',
        'priceBoxColor'   => '#E8A020',
        'priceRadius'     => 8,
        'glassBlur'       => 8,
        'glassOpacity'    => 0.15,
        'pillStyle'       => 'pill',
        'logoAlign'       => 'flex-start',
    ];

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\OneToMany(mappedBy: 'menu', targetEntity: Category::class, cascade: ['remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC', 'id' => 'ASC'])]
    private Collection $categories;

    public function __construct()
    {
        $this->createdAt  = new \DateTimeImmutable();
        $this->updatedAt  = new \DateTimeImmutable();
        $this->categories = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getBusiness(): ?Business { return $this->business; }
    public function setBusiness(?Business $b): static { $this->business = $b; return $this; }
    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }
    public function getSlug(): ?string { return $this->slug; }
    public function setSlug(string $slug): static { $this->slug = $slug; return $this; }
    public function getStatus(): string { return $this->status; }
    public function setStatus(string $s): static { $this->status = $s; return $this; }
    public function getCurrency(): string { return $this->currency; }
    public function setCurrency(string $c): static { $this->currency = $c; return $this; }
    public function getThemeConfig(): array { return array_merge(self::DEFAULT_THEME, $this->themeConfig ?? []); }
    public function setThemeConfig(?array $c): static { $this->themeConfig = $c; return $this; }
    public function getHero(): ?MenuHero { return $this->hero; }
    public function setHero(?MenuHero $hero): static
    {
        $this->hero = $hero;
        if ($hero !== null && $hero->getMenu() !== $this) $hero->setMenu($this);
        return $this;
    }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $dt): static { $this->updatedAt = $dt; return $this; }
    public function getCategories(): Collection { return $this->categories; }
    public function canUseScanner(): bool { return $this->categories->isEmpty(); }
}

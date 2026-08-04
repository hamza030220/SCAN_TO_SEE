<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class MenuHero
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'hero')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Menu $menu = null;

    #[ORM\Column(type: 'json')]
    private array $draftConfig = [];

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $publishedConfig = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $publishedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getMenu(): ?Menu { return $this->menu; }
    public function setMenu(Menu $menu): static { $this->menu = $menu; return $this; }
    public function getDraftConfig(): array { return $this->draftConfig; }
    public function setDraftConfig(array $config): static { $this->draftConfig = $config; $this->touch(); return $this; }
    public function getPublishedConfig(): ?array { return $this->publishedConfig; }
    public function getPublishedAt(): ?\DateTimeImmutable { return $this->publishedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function publish(array $config, ?\DateTimeImmutable $now = null): static
    {
        $this->publishedConfig = $config;
        $this->publishedAt = $now ?? new \DateTimeImmutable();
        $this->touch();

        return $this;
    }

    public function hide(?\DateTimeImmutable $now = null): static
    {
        if ($this->publishedConfig !== null) {
            $this->publishedConfig['enabled'] = false;
            $this->publishedAt = $now ?? new \DateTimeImmutable();
        }
        $this->touch();

        return $this;
    }

    public function getPublicConfig(?\DateTimeImmutable $now = null): ?array
    {
        $config = $this->publishedConfig;
        if ($config === null || !($config['enabled'] ?? false)) {
            return null;
        }

        $now ??= new \DateTimeImmutable();
        $startsAt = $this->date($config['startsAt'] ?? null);
        $expiresAt = $this->date($config['expiresAt'] ?? null);
        if (($startsAt !== null && $now < $startsAt) || ($expiresAt !== null && $now >= $expiresAt)) {
            return null;
        }

        return $config;
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }
}

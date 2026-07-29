<?php

namespace App\Entity;

use App\Repository\DeletedEmailBlockRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: DeletedEmailBlockRepository::class)]
class DeletedEmailBlock
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 64, unique: true)]
    private string $emailHash;

    #[ORM\Column]
    private \DateTimeImmutable $blockedUntil;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getEmailHash(): string { return $this->emailHash; }
    public function setEmailHash(string $value): static { $this->emailHash = $value; return $this; }
    public function getBlockedUntil(): \DateTimeImmutable { return $this->blockedUntil; }
    public function setBlockedUntil(\DateTimeImmutable $value): static { $this->blockedUntil = $value; return $this; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}

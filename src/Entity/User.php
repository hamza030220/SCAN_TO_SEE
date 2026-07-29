<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\ORM\Mapping as ORM;
use Scheb\TwoFactorBundle\Model\BackupCodeInterface;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfiguration;
use Scheb\TwoFactorBundle\Model\Totp\TotpConfigurationInterface;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface as TotpTwoFactorInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, TotpTwoFactorInterface, BackupCodeInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;

    #[ORM\Column(length: 255)]
    private ?string $password = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $fullName = null;

    #[ORM\Column(length: 10)]
    private string $role = 'owner';

    #[ORM\Column]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $totpSecret = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $backupCodes = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $passwordResetToken = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $passwordResetTokenExpiresAt = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $enforcementRequired = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerifiedAt = null;

    #[ORM\Column(length: 64, nullable: true, unique: true)]
    private ?string $emailVerificationTokenHash = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $emailVerificationExpiresAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $trialEndsAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $trialAiUses = 0;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(string $email): static { $this->email = mb_strtolower(trim($email)); return $this; }

    public function getUserIdentifier(): string { return (string) $this->email; }

    public function getRoles(): array
    {
        return $this->role === 'admin'
            ? ['ROLE_ADMIN', 'ROLE_USER']
            : ['ROLE_OWNER', 'ROLE_USER'];
    }

    public function getPassword(): ?string { return $this->password; }
    public function setPassword(string $password): static { $this->password = $password; return $this; }

    public function eraseCredentials(): void {}

    public function getFullName(): ?string { return $this->fullName; }
    public function setFullName(?string $fullName): static { $this->fullName = $fullName; return $this; }

    public function getRole(): string { return $this->role; }
    public function setRole(string $role): static { $this->role = $role; return $this; }

    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $isActive): static { $this->isActive = $isActive; return $this; }

    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    // ── TOTP 2FA ─────────────────────────────────────────────────────────────

    public function isTotpAuthenticationEnabled(): bool
    {
        // Require BOTH secret AND backup codes — backup codes are only
        // saved after the user successfully confirms the setup. This
        // prevents an orphaned secret (from an abandoned setup visit)
        // from being treated as a completed 2FA configuration.
        return $this->totpSecret !== null && !empty($this->backupCodes);
    }

    public function getTotpAuthenticationUsername(): string
    {
        return $this->email;
    }

    public function getTotpAuthenticationConfiguration(): ?TotpConfigurationInterface
    {
        if ($this->totpSecret === null) {
            return null;
        }

        return new TotpConfiguration($this->totpSecret, TotpConfiguration::ALGORITHM_SHA1, 30, 6);
    }

    public function getTotpSecret(): ?string { return $this->totpSecret; }
    public function setTotpSecret(?string $totpSecret): static { $this->totpSecret = $totpSecret; return $this; }

    // ── Backup Codes ─────────────────────────────────────────────────────────

    public function isBackupCode(string $code): bool
    {
        return in_array($code, $this->backupCodes ?? [], true);
    }

    public function invalidateBackupCode(string $code): void
    {
        $this->backupCodes = array_values(array_filter(
            $this->backupCodes ?? [],
            fn(string $c) => $c !== $code
        ));
    }

    public function getBackupCodes(): array { return $this->backupCodes ?? []; }

    public function setBackupCodes(array $codes): static { $this->backupCodes = $codes; return $this; }

    // ── Password reset ────────────────────────────────────────────────────────

    public function getPasswordResetToken(): ?string { return $this->passwordResetToken; }
    public function setPasswordResetToken(?string $token): static { $this->passwordResetToken = $token; return $this; }

    public function getPasswordResetTokenExpiresAt(): ?\DateTimeImmutable { return $this->passwordResetTokenExpiresAt; }
    public function setPasswordResetTokenExpiresAt(?\DateTimeImmutable $dt): static { $this->passwordResetTokenExpiresAt = $dt; return $this; }

    public function isEnforcementRequired(): bool { return $this->enforcementRequired; }
    public function setEnforcementRequired(bool $required): static { $this->enforcementRequired = $required; return $this; }

    public function getEmailVerifiedAt(): ?\DateTimeImmutable { return $this->emailVerifiedAt; }
    public function setEmailVerifiedAt(?\DateTimeImmutable $value): static { $this->emailVerifiedAt = $value; return $this; }
    public function isEmailVerified(): bool { return $this->emailVerifiedAt !== null; }

    public function getEmailVerificationTokenHash(): ?string { return $this->emailVerificationTokenHash; }
    public function setEmailVerificationTokenHash(?string $value): static { $this->emailVerificationTokenHash = $value; return $this; }
    public function getEmailVerificationExpiresAt(): ?\DateTimeImmutable { return $this->emailVerificationExpiresAt; }
    public function setEmailVerificationExpiresAt(?\DateTimeImmutable $value): static { $this->emailVerificationExpiresAt = $value; return $this; }

    public function getTrialEndsAt(): ?\DateTimeImmutable { return $this->trialEndsAt; }
    public function setTrialEndsAt(?\DateTimeImmutable $value): static { $this->trialEndsAt = $value; return $this; }
    public function isTrialActive(?\DateTimeImmutable $now = null): bool
    {
        return $this->trialEndsAt !== null && $this->trialEndsAt > ($now ?? new \DateTimeImmutable());
    }
    public function getTrialAiUses(): int { return $this->trialAiUses; }
    public function setTrialAiUses(int $value): static { $this->trialAiUses = max(0, $value); return $this; }
}

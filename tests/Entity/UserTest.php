<?php

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testUserCreatesWithDefaultValues(): void
    {
        $user = new User();

        $this->assertSame('owner', $user->getRole());
        $this->assertTrue($user->isActive());
        $this->assertInstanceOf(\DateTimeImmutable::class, $user->getCreatedAt());
        $this->assertFalse($user->isEnforcementRequired());
    }

    public function testSetAndGetEmail(): void
    {
        $user = new User();
        $user->setEmail('  Test@Example.COM ');

        $this->assertSame('test@example.com', $user->getEmail());
    }

    public function testEmailVerificationAndTrialState(): void
    {
        $user = new User();
        $this->assertFalse($user->isEmailVerified());
        $this->assertFalse($user->isTrialActive());

        $user->setEmailVerifiedAt(new \DateTimeImmutable());
        $user->setTrialEndsAt(new \DateTimeImmutable('+5 days'));
        $this->assertTrue($user->isEmailVerified());
        $this->assertTrue($user->isTrialActive());

        $user->setTrialEndsAt(new \DateTimeImmutable('-1 second'));
        $this->assertFalse($user->isTrialActive());
    }

    public function testGetUserIdentifierReturnsEmail(): void
    {
        $user = new User();
        $user->setEmail('user@example.com');

        $this->assertSame('user@example.com', $user->getUserIdentifier());
    }

    public function testGetRolesForOwner(): void
    {
        $user = new User();
        $user->setRole('owner');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_OWNER', $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertNotContains('ROLE_ADMIN', $roles);
    }

    public function testGetRolesForAdmin(): void
    {
        $user = new User();
        $user->setRole('admin');

        $roles = $user->getRoles();

        $this->assertContains('ROLE_ADMIN', $roles);
        $this->assertContains('ROLE_USER', $roles);
        $this->assertNotContains('ROLE_OWNER', $roles);
    }

    public function testSetAndGetPassword(): void
    {
        $user = new User();
        $user->setPassword('hashed_password_123');

        $this->assertSame('hashed_password_123', $user->getPassword());
    }

    public function testSetAndGetFullName(): void
    {
        $user = new User();
        $user->setFullName('John Doe');

        $this->assertSame('John Doe', $user->getFullName());
    }

    public function testSetAndGetIsActive(): void
    {
        $user = new User();
        
        $user->setIsActive(false);
        $this->assertFalse($user->isActive());

        $user->setIsActive(true);
        $this->assertTrue($user->isActive());
    }

    public function testSetAndGetEnforcementRequired(): void
    {
        $user = new User();
        
        $this->assertFalse($user->isEnforcementRequired());

        $user->setEnforcementRequired(true);
        $this->assertTrue($user->isEnforcementRequired());

        $user->setEnforcementRequired(false);
        $this->assertFalse($user->isEnforcementRequired());
    }

    public function testSetAndGetTotpSecret(): void
    {
        $user = new User();
        $user->setTotpSecret('SECRET123');

        $this->assertSame('SECRET123', $user->getTotpSecret());
    }

    public function testIsTotpAuthenticationEnabledReturnsTrueWhenSecretSet(): void
    {
        $user = new User();
        $user->setTotpSecret('SECRET123');
        $user->setBackupCodes(['CODE1', 'CODE2', 'CODE3']); // Required for 2FA to be considered enabled

        $this->assertTrue($user->isTotpAuthenticationEnabled());
    }

    public function testIsTotpAuthenticationEnabledReturnsFalseWhenNoSecret(): void
    {
        $user = new User();

        $this->assertFalse($user->isTotpAuthenticationEnabled());
    }

    public function testSetAndGetBackupCodes(): void
    {
        $user = new User();
        $codes = ['CODE1', 'CODE2', 'CODE3'];
        
        $user->setBackupCodes($codes);

        $this->assertSame($codes, $user->getBackupCodes());
    }

    public function testIsBackupCodeReturnsTrueForValidCode(): void
    {
        $user = new User();
        $user->setBackupCodes(['CODE1', 'CODE2', 'CODE3']);

        $this->assertTrue($user->isBackupCode('CODE2'));
    }

    public function testIsBackupCodeReturnsFalseForInvalidCode(): void
    {
        $user = new User();
        $user->setBackupCodes(['CODE1', 'CODE2', 'CODE3']);

        $this->assertFalse($user->isBackupCode('INVALID'));
    }

    public function testInvalidateBackupCodeRemovesCode(): void
    {
        $user = new User();
        $user->setBackupCodes(['CODE1', 'CODE2', 'CODE3']);

        $user->invalidateBackupCode('CODE2');

        $codes = $user->getBackupCodes();
        $this->assertNotContains('CODE2', $codes);
        $this->assertContains('CODE1', $codes);
        $this->assertContains('CODE3', $codes);
    }

    public function testSetAndGetPasswordResetToken(): void
    {
        $user = new User();
        $token = bin2hex(random_bytes(32));
        
        $user->setPasswordResetToken($token);

        $this->assertSame($token, $user->getPasswordResetToken());
    }

    public function testSetAndGetPasswordResetTokenExpiresAt(): void
    {
        $user = new User();
        $expiresAt = (new \DateTimeImmutable())->modify('+1 hour');
        
        $user->setPasswordResetTokenExpiresAt($expiresAt);

        $this->assertSame($expiresAt, $user->getPasswordResetTokenExpiresAt());
    }

    public function testEraseCredentialsDoesNothing(): void
    {
        $user = new User();
        $user->setPassword('hashed_password');

        $user->eraseCredentials();

        // Password should remain unchanged (sensitive plaintext would be removed, but we don't store that)
        $this->assertSame('hashed_password', $user->getPassword());
    }
}

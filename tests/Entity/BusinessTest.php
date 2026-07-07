<?php

namespace App\Tests\Entity;

use App\Entity\Business;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class BusinessTest extends TestCase
{
    public function testBusinessCreatesWithDefaultValues(): void
    {
        $business = new Business();

        $this->assertNull($business->getId());
        $this->assertNull($business->getOwner());
        $this->assertNull($business->getName());
        $this->assertNull($business->getSlug());
        $this->assertNull($business->getLogoPath());
        $this->assertInstanceOf(\DateTimeImmutable::class, $business->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $business->getUpdatedAt());
    }

    public function testSetAndGetOwner(): void
    {
        $business = new Business();
        $user = new User();
        $user->setEmail('owner@example.com');

        $business->setOwner($user);

        $this->assertSame($user, $business->getOwner());
    }

    public function testSetAndGetName(): void
    {
        $business = new Business();
        $business->setName('My Restaurant');

        $this->assertSame('My Restaurant', $business->getName());
    }

    public function testSetAndGetSlug(): void
    {
        $business = new Business();
        $business->setSlug('my-restaurant');

        $this->assertSame('my-restaurant', $business->getSlug());
    }

    public function testSlugCanBeNull(): void
    {
        $business = new Business();
        $business->setSlug(null);

        $this->assertNull($business->getSlug());
    }

    public function testSetAndGetLogoPath(): void
    {
        $business = new Business();
        $business->setLogoPath('/uploads/logos/logo.png');

        $this->assertSame('/uploads/logos/logo.png', $business->getLogoPath());
    }

    public function testLogoPathCanBeNull(): void
    {
        $business = new Business();
        $business->setLogoPath(null);

        $this->assertNull($business->getLogoPath());
    }

    public function testCreatedAtIsImmutable(): void
    {
        $business = new Business();
        $originalCreatedAt = $business->getCreatedAt();

        // Wait a tiny bit to ensure time difference
        usleep(1000);

        // Create another business
        $business2 = new Business();

        // Original should not change
        $this->assertSame($originalCreatedAt, $business->getCreatedAt());
        $this->assertNotSame($business->getCreatedAt(), $business2->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $business = new Business();
        $newDate = (new \DateTimeImmutable())->modify('+1 hour');

        $business->setUpdatedAt($newDate);

        $this->assertSame($newDate, $business->getUpdatedAt());
    }

    public function testUpdatedAtDefaultsToCurrentTime(): void
    {
        $beforeCreation = new \DateTimeImmutable();
        $business = new Business();
        $afterCreation = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($beforeCreation, $business->getUpdatedAt());
        $this->assertLessThanOrEqual($afterCreation, $business->getUpdatedAt());
    }

    public function testFluentSetterChaining(): void
    {
        $user = new User();
        $business = new Business();

        $result = $business
            ->setOwner($user)
            ->setName('Test Business')
            ->setSlug('test-business')
            ->setLogoPath('/logo.png');

        $this->assertSame($business, $result);
        $this->assertSame($user, $business->getOwner());
        $this->assertSame('Test Business', $business->getName());
        $this->assertSame('test-business', $business->getSlug());
        $this->assertSame('/logo.png', $business->getLogoPath());
    }
}

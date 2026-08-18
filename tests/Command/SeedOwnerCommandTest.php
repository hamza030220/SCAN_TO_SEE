<?php

namespace App\Tests\Command;

use App\Command\SeedOwnerCommand;
use App\Entity\Menu;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SeedOwnerCommandTest extends TestCase
{
    public function testItSeedsMenusUsingTheCurrentThemeConfigModel(): void
    {
        $owner = (new User())
            ->setEmail('owner@example.test')
            ->setFullName('Test Owner')
            ->setRole('owner')
            ->setPassword('already-hashed');

        $repository = $this->createMock(EntityRepository::class);
        $repository->method('findOneBy')->willReturn($owner);

        $persisted = [];
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });

        $command = new SeedOwnerCommand(
            $entityManager,
            $this->createMock(UserPasswordHasherInterface::class),
        );
        $tester = new CommandTester($command);

        $status = $tester->execute([
            'email' => 'owner@example.test',
            'password' => 'unused-bootstrap-password',
        ]);

        self::assertSame(Command::SUCCESS, $status);
        $menus = array_values(array_filter($persisted, static fn (object $entity): bool => $entity instanceof Menu));
        self::assertCount(5, $menus);
        foreach ($menus as $menu) {
            self::assertSame(Menu::DEFAULT_THEME, $menu->getThemeConfig());
        }
    }
}

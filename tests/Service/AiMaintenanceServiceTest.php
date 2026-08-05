<?php

namespace App\Tests\Service;

use App\Entity\TrainingJob;
use App\Repository\TrainingJobRepository;
use App\Service\AiMaintenanceService;
use PHPUnit\Framework\TestCase;

final class AiMaintenanceServiceTest extends TestCase
{
    public function testMaintenanceFollowsActiveTrainingJob(): void
    {
        $repository = $this->createMock(TrainingJobRepository::class);
        $repository->method('findActive')->willReturn(new TrainingJob());

        self::assertTrue((new AiMaintenanceService($repository))->isActive());
    }

    public function testScanningRemainsAvailableWithoutActiveJob(): void
    {
        $repository = $this->createMock(TrainingJobRepository::class);
        $repository->method('findActive')->willReturn(null);

        self::assertFalse((new AiMaintenanceService($repository))->isActive());
    }
}

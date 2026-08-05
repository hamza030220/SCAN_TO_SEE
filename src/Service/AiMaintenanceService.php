<?php

namespace App\Service;

use App\Repository\TrainingJobRepository;

final class AiMaintenanceService
{
    public function __construct(private readonly TrainingJobRepository $jobs) {}
    public function isActive(): bool { return $this->jobs->findActive() !== null; }
    public function message(): string { return 'The AI Scanner is temporarily unavailable while we safely improve the recognition model. Your menus remain available, and scanning will return automatically when the comparison is complete.'; }
}

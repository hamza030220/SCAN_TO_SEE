<?php

namespace App\Message;

final readonly class RunTrainingJob
{
    public function __construct(public int $jobId) {}
}

<?php

namespace App\Tests\Entity;

use App\Entity\ScanCapture;
use App\Entity\ScanRegion;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

class ScanCaptureTest extends TestCase
{
    public function testCaptureOwnsRegionAndPreservesRawResponse(): void
    {
        $owner = (new User())->setEmail('owner@example.test');
        $region = (new ScanRegion())
            ->setBoxId(4)
            ->setRole('item_name')
            ->setRawText('Espreso')
            ->setConfidence(0.72)
            ->setRawJson(['raw_text' => 'Espreso']);

        $scan = (new ScanCapture())
            ->setScanUuid('09e8c341-b92f-41ea-b4a8-c2719110a873')
            ->setOwner($owner)
            ->setModelVersion('test-v1')
            ->setRawResponse(['items' => []])
            ->addRegion($region);

        self::assertSame($scan, $region->getScan());
        self::assertCount(1, $scan->getRegions());
        self::assertSame(['items' => []], $scan->getRawResponse());
        self::assertSame('pending', $scan->getStatus());
    }
}

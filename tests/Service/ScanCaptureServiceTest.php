<?php

namespace App\Tests\Service;

use App\Entity\Menu;
use App\Entity\ScanCapture;
use App\Entity\ScanRegion;
use App\Entity\User;
use App\Repository\ScanCaptureRepository;
use App\Service\MenuScannerClient;
use App\Service\ScanCaptureService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

class ScanCaptureServiceTest extends TestCase
{
    public function testReviewLabelsSeparateNameAndPriceRegions(): void
    {
        $owner = new User();
        $menu = new Menu();
        $name = (new ScanRegion())
            ->setBoxId(10)->setRole('item_name')->setRawText('Espreso')
            ->setConfidence(.7)->setRawJson([]);
        $price = (new ScanRegion())
            ->setBoxId(11)->setRole('item_price')->setRawText('3,50')
            ->setConfidence(.8)->setRawJson([]);
        $unused = (new ScanRegion())
            ->setBoxId(12)->setRole('unresolved')->setRawText('Noise')
            ->setConfidence(.2)->setRawJson([]);
        $scan = (new ScanCapture())
            ->setScanUuid('00000000-0000-0000-0000-000000000001')
            ->setOwner($owner)->setMenu($menu)->setRawResponse([])
            ->addRegion($name)->addRegion($price)->addRegion($unused);

        $repo = $this->createMock(ScanCaptureRepository::class);
        $repo->method('findOneBy')->willReturn($scan);
        $service = new ScanCaptureService(
            $this->createMock(MenuScannerClient::class),
            $repo,
            $this->createMock(EntityManagerInterface::class),
        );

        $service->recordReview(1, $owner, $menu, [[
            'name' => 'Coffee',
            'box_id' => null,
            'items' => [[
                'name' => 'Espresso',
                'price' => '3.50',
                'name_box_id' => 10,
                'price_box_id' => 11,
            ]],
        ]]);

        self::assertSame('Espresso', $name->getCorrectedText());
        self::assertSame('modified', $name->getReviewOutcome());
        self::assertSame('3.50', $price->getCorrectedText());
        self::assertSame('modified', $price->getReviewOutcome());
        self::assertSame('deleted', $unused->getReviewOutcome());
        self::assertSame('reviewed', $scan->getStatus());
    }
}

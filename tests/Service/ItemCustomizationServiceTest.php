<?php

namespace App\Tests\Service;

use App\Service\ItemCustomizationService;
use PHPUnit\Framework\TestCase;

final class ItemCustomizationServiceTest extends TestCase
{
    private ItemCustomizationService $service;

    protected function setUp(): void
    {
        $this->service = new ItemCustomizationService();
    }

    public function testLabelsAreTrimmedDeduplicatedAndLimited(): void
    {
        $labels = $this->service->labels(' Vegan, spicy, vegan, Gluten free ');

        self::assertSame(['Vegan', 'spicy', 'Gluten free'], $labels);
    }

    public function testVariantsAreNormalized(): void
    {
        self::assertSame(
            [['name' => 'Small', 'price' => '4.50'], ['name' => 'Large', 'price' => '7.00']],
            $this->service->variants(['Small', 'Large'], ['4.5', '7']),
        );
    }

    public function testIncompleteVariantIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->service->variants(['Large'], ['']);
    }

    public function testBadgeIsRestrictedToSupportedLabels(): void
    {
        self::assertSame('Popular', $this->service->badge('Popular'));
        self::assertNull($this->service->badge('position: fixed'));
    }
}

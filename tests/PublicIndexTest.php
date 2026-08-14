<?php

namespace App\Tests;

use PHPUnit\Framework\TestCase;

final class PublicIndexTest extends TestCase
{
    public function testBuiltInServerRouterLetsExistingAssetsPassThrough(): void
    {
        $index = file_get_contents(dirname(__DIR__).'/public/index.php');

        self::assertIsString($index);
        self::assertStringContainsString("PHP_SAPI === 'cli-server'", $index);
        self::assertStringContainsString('is_file($requestedFile)', $index);
        self::assertStringContainsString('return false;', $index);
    }
}

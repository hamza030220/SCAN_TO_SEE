<?php

namespace App\Tests\Entity;

use App\Entity\Category;
use App\Entity\Item;
use PHPUnit\Framework\TestCase;

class ItemTest extends TestCase
{
    public function testItemCreatesWithDefaultValues(): void
    {
        $item = new Item();

        $this->assertNull($item->getId());
        $this->assertNull($item->getCategory());
        $this->assertNull($item->getName());
        $this->assertNull($item->getShortDescription());
        $this->assertNull($item->getPrice());
        $this->assertTrue($item->isAvailable());
        $this->assertSame(0, $item->getSortOrder());
        $this->assertNull($item->getImagePath());
        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getCreatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $item->getUpdatedAt());
    }

    public function testSetAndGetCategory(): void
    {
        $item = new Item();
        $category = new Category();
        $category->setName('Appetizers');

        $item->setCategory($category);

        $this->assertSame($category, $item->getCategory());
    }

    public function testSetAndGetName(): void
    {
        $item = new Item();
        $item->setName('Caesar Salad');

        $this->assertSame('Caesar Salad', $item->getName());
    }

    public function testSetAndGetShortDescription(): void
    {
        $item = new Item();
        $description = 'Fresh romaine lettuce with parmesan';
        
        $item->setShortDescription($description);

        $this->assertSame($description, $item->getShortDescription());
    }

    public function testShortDescriptionCanBeNull(): void
    {
        $item = new Item();
        $item->setShortDescription(null);

        $this->assertNull($item->getShortDescription());
    }

    public function testSetAndGetPrice(): void
    {
        $item = new Item();
        
        $item->setPrice('12.50');
        $this->assertSame('12.50', $item->getPrice());

        $item->setPrice('99.99');
        $this->assertSame('99.99', $item->getPrice());
    }

    public function testPriceStoresAsString(): void
    {
        $item = new Item();
        $item->setPrice('10.00');

        // Price is stored as string to preserve decimal precision
        $this->assertIsString($item->getPrice());
    }

    public function testSetAndGetIsAvailable(): void
    {
        $item = new Item();

        $this->assertTrue($item->isAvailable());

        $item->setIsAvailable(false);
        $this->assertFalse($item->isAvailable());

        $item->setIsAvailable(true);
        $this->assertTrue($item->isAvailable());
    }

    public function testSetAndGetSortOrder(): void
    {
        $item = new Item();

        $this->assertSame(0, $item->getSortOrder());

        $item->setSortOrder(3);
        $this->assertSame(3, $item->getSortOrder());

        $item->setSortOrder(15);
        $this->assertSame(15, $item->getSortOrder());
    }

    public function testSetAndGetImagePath(): void
    {
        $item = new Item();
        $imagePath = '/uploads/items/salad.jpg';
        
        $item->setImagePath($imagePath);

        $this->assertSame($imagePath, $item->getImagePath());
    }

    public function testImagePathCanBeNull(): void
    {
        $item = new Item();
        $item->setImagePath(null);

        $this->assertNull($item->getImagePath());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $item = new Item();
        $newDate = (new \DateTimeImmutable())->modify('+1 hour');

        $item->setUpdatedAt($newDate);

        $this->assertSame($newDate, $item->getUpdatedAt());
    }

    public function testCreatedAtIsImmutable(): void
    {
        $item = new Item();
        $originalCreatedAt = $item->getCreatedAt();

        usleep(1000);

        $item2 = new Item();

        $this->assertSame($originalCreatedAt, $item->getCreatedAt());
        $this->assertNotSame($item->getCreatedAt(), $item2->getCreatedAt());
    }

    public function testUpdatedAtDefaultsToCurrentTime(): void
    {
        $beforeCreation = new \DateTimeImmutable();
        $item = new Item();
        $afterCreation = new \DateTimeImmutable();

        $this->assertGreaterThanOrEqual($beforeCreation, $item->getUpdatedAt());
        $this->assertLessThanOrEqual($afterCreation, $item->getUpdatedAt());
    }

    public function testFluentSetterChaining(): void
    {
        $category = new Category();
        $item = new Item();

        $result = $item
            ->setCategory($category)
            ->setName('Grilled Chicken')
            ->setShortDescription('Marinated and grilled to perfection')
            ->setPrice('18.99')
            ->setIsAvailable(true)
            ->setSortOrder(1)
            ->setImagePath('/images/chicken.jpg');

        $this->assertSame($item, $result);
        $this->assertSame($category, $item->getCategory());
        $this->assertSame('Grilled Chicken', $item->getName());
        $this->assertSame('Marinated and grilled to perfection', $item->getShortDescription());
        $this->assertSame('18.99', $item->getPrice());
        $this->assertTrue($item->isAvailable());
        $this->assertSame(1, $item->getSortOrder());
        $this->assertSame('/images/chicken.jpg', $item->getImagePath());
    }

    public function testSortOrderCanBeNegative(): void
    {
        $item = new Item();
        $item->setSortOrder(-10);

        $this->assertSame(-10, $item->getSortOrder());
    }

    public function testPriceCanHandleDecimalValues(): void
    {
        $item = new Item();
        
        $item->setPrice('0.99');
        $this->assertSame('0.99', $item->getPrice());

        $item->setPrice('1234.56');
        $this->assertSame('1234.56', $item->getPrice());

        $item->setPrice('9999999.99');
        $this->assertSame('9999999.99', $item->getPrice());
    }

    public function testCanCreateCompleteMenuItem(): void
    {
        $category = new Category();
        $category->setName('Main Courses');

        $item = new Item();
        $item->setCategory($category)
            ->setName('Beef Burger')
            ->setShortDescription('Juicy beef patty with cheese and bacon')
            ->setPrice('14.99')
            ->setIsAvailable(true)
            ->setSortOrder(5)
            ->setImagePath('/uploads/items/burger.jpg');

        $this->assertSame('Main Courses', $item->getCategory()->getName());
        $this->assertSame('Beef Burger', $item->getName());
        $this->assertSame('Juicy beef patty with cheese and bacon', $item->getShortDescription());
        $this->assertSame('14.99', $item->getPrice());
        $this->assertTrue($item->isAvailable());
        $this->assertSame(5, $item->getSortOrder());
        $this->assertSame('/uploads/items/burger.jpg', $item->getImagePath());
    }
}

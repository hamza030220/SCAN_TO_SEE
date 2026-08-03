<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite indexes for public menu, category, and item lookups';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('menu')->hasIndex('IDX_MENU_BUSINESS_STATUS')) {
            $this->addSql('CREATE INDEX IDX_MENU_BUSINESS_STATUS ON menu (business_id, status)');
        }
        if (!$schema->getTable('category')->hasIndex('IDX_CATEGORY_PUBLIC_ORDER')) {
            $this->addSql('CREATE INDEX IDX_CATEGORY_PUBLIC_ORDER ON category (menu_id, is_visible, sort_order)');
        }
        if (!$schema->getTable('item')->hasIndex('IDX_ITEM_PUBLIC_ORDER')) {
            $this->addSql('CREATE INDEX IDX_ITEM_PUBLIC_ORDER ON item (category_id, is_available, sort_order)');
        }
    }

    public function down(Schema $schema): void
    {
        if ($schema->getTable('item')->hasIndex('IDX_ITEM_PUBLIC_ORDER')) {
            $this->addSql('DROP INDEX IDX_ITEM_PUBLIC_ORDER ON item');
        }
        if ($schema->getTable('category')->hasIndex('IDX_CATEGORY_PUBLIC_ORDER')) {
            $this->addSql('DROP INDEX IDX_CATEGORY_PUBLIC_ORDER ON category');
        }
        if ($schema->getTable('menu')->hasIndex('IDX_MENU_BUSINESS_STATUS')) {
            $this->addSql('DROP INDEX IDX_MENU_BUSINESS_STATUS ON menu');
        }
    }
}

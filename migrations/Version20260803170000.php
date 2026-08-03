<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add item details, labels, variants, and availability notes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item ADD details LONGTEXT DEFAULT NULL, ADD badge VARCHAR(40) DEFAULT NULL, ADD dietary_tags JSON DEFAULT NULL, ADD allergens JSON DEFAULT NULL, ADD variants JSON DEFAULT NULL, ADD availability_note VARCHAR(120) DEFAULT NULL');
        $this->addSql("UPDATE item SET dietary_tags = '[]', allergens = '[]', variants = '[]' WHERE dietary_tags IS NULL OR allergens IS NULL OR variants IS NULL");
        $this->addSql('ALTER TABLE item CHANGE dietary_tags dietary_tags JSON NOT NULL, CHANGE allergens allergens JSON NOT NULL, CHANGE variants variants JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE item DROP details, DROP badge, DROP dietary_tags, DROP allergens, DROP variants, DROP availability_note');
    }
}

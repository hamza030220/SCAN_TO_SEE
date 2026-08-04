<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260804100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add isolated draft and published menu hero configurations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE menu_hero (id INT AUTO_INCREMENT NOT NULL, menu_id INT NOT NULL, draft_config JSON NOT NULL, published_config JSON DEFAULT NULL, published_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_MENU_HERO_MENU (menu_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE menu_hero ADD CONSTRAINT FK_MENU_HERO_MENU FOREIGN KEY (menu_id) REFERENCES menu (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE menu_hero');
    }
}

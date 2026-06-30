<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260630135720 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE business (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, logo_path VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, owner_id INT UNSIGNED NOT NULL, INDEX IDX_8D36E387E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE category (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, is_visible TINYINT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL, menu_id INT NOT NULL, parent_id INT DEFAULT NULL, INDEX IDX_64C19C1CCD7E912 (menu_id), INDEX IDX_64C19C1727ACA70 (parent_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE item (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, short_description VARCHAR(255) DEFAULT NULL, price NUMERIC(10, 2) NOT NULL, is_available TINYINT NOT NULL, sort_order INT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, category_id INT NOT NULL, INDEX IDX_1F1B251E12469DE2 (category_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE menu (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(150) NOT NULL, slug VARCHAR(80) NOT NULL, status VARCHAR(20) NOT NULL, currency VARCHAR(3) NOT NULL, theme_preset VARCHAR(40) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, business_id INT NOT NULL, UNIQUE INDEX UNIQ_7D053A93989D9B62 (slug), INDEX IDX_7D053A93A89DB457 (business_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE business ADD CONSTRAINT FK_8D36E387E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1CCD7E912 FOREIGN KEY (menu_id) REFERENCES menu (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE category ADD CONSTRAINT FK_64C19C1727ACA70 FOREIGN KEY (parent_id) REFERENCES category (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE item ADD CONSTRAINT FK_1F1B251E12469DE2 FOREIGN KEY (category_id) REFERENCES category (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE menu ADD CONSTRAINT FK_7D053A93A89DB457 FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE business DROP FOREIGN KEY FK_8D36E387E3C61F9');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1CCD7E912');
        $this->addSql('ALTER TABLE category DROP FOREIGN KEY FK_64C19C1727ACA70');
        $this->addSql('ALTER TABLE item DROP FOREIGN KEY FK_1F1B251E12469DE2');
        $this->addSql('ALTER TABLE menu DROP FOREIGN KEY FK_7D053A93A89DB457');
        $this->addSql('DROP TABLE business');
        $this->addSql('DROP TABLE category');
        $this->addSql('DROP TABLE item');
        $this->addSql('DROP TABLE menu');
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260706103610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE subscription (id INT AUTO_INCREMENT NOT NULL, plan VARCHAR(20) NOT NULL, billing_period VARCHAR(10) NOT NULL, status VARCHAR(20) NOT NULL, stripe_customer_id VARCHAR(255) DEFAULT NULL, stripe_subscription_id VARCHAR(255) DEFAULT NULL, current_period_end DATETIME DEFAULT NULL, expiry_reminder_sent TINYINT NOT NULL, created_at DATETIME NOT NULL, owner_id INT UNSIGNED NOT NULL, UNIQUE INDEX UNIQ_A3C664D37E3C61F9 (owner_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE subscription ADD CONSTRAINT FK_A3C664D37E3C61F9 FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE subscription DROP FOREIGN KEY FK_A3C664D37E3C61F9');
        $this->addSql('DROP TABLE subscription');
    }
}

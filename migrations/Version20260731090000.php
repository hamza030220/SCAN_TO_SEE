<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260731090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add persistent administrator audit log';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE admin_audit_log (id INT AUTO_INCREMENT NOT NULL, actor_id INT UNSIGNED DEFAULT NULL, actor_email VARCHAR(180) NOT NULL, action VARCHAR(64) NOT NULL, target_type VARCHAR(64) NOT NULL, target_id INT DEFAULT NULL, target_label VARCHAR(255) NOT NULL, reason LONGTEXT DEFAULT NULL, ip_address VARCHAR(45) DEFAULT NULL, outcome VARCHAR(20) NOT NULL, before_state JSON DEFAULT NULL, after_state JSON DEFAULT NULL, error_message VARCHAR(255) DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_ADMIN_AUDIT_ACTOR (actor_id), INDEX IDX_ADMIN_AUDIT_CREATED (created_at), INDEX IDX_ADMIN_AUDIT_ACTION (action), INDEX IDX_ADMIN_AUDIT_OUTCOME (outcome), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_ADMIN_AUDIT_ACTOR FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE admin_audit_log');
    }
}

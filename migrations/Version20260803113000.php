<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260803113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Synchronize audit, deleted-email, and verification index metadata with Doctrine mapping';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_ADMIN_AUDIT_ACTOR');
        $this->addSql('ALTER TABLE admin_audit_log CHANGE created_at created_at DATETIME NOT NULL, CHANGE completed_at completed_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX IDX_ADMIN_AUDIT_ACTOR ON admin_audit_log');
        $this->addSql('CREATE INDEX IDX_1F16C5C710DAF24A ON admin_audit_log (actor_id)');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_ADMIN_AUDIT_ACTOR FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('DROP INDEX UNIQ_A397BB88CE2D7B2D ON deleted_email_block');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8F12D4B74E8E423D ON deleted_email_block (email_hash)');
        $this->addSql('DROP INDEX UNIQ_8D93D649A253787A ON `user`');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D6497B0098D1 ON `user` (email_verification_token_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_8D93D6497B0098D1 ON `user`');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649A253787A ON `user` (email_verification_token_hash)');
        $this->addSql('DROP INDEX UNIQ_8F12D4B74E8E423D ON deleted_email_block');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_A397BB88CE2D7B2D ON deleted_email_block (email_hash)');
        $this->addSql('ALTER TABLE admin_audit_log DROP FOREIGN KEY FK_ADMIN_AUDIT_ACTOR');
        $this->addSql('DROP INDEX IDX_1F16C5C710DAF24A ON admin_audit_log');
        $this->addSql('CREATE INDEX IDX_ADMIN_AUDIT_ACTOR ON admin_audit_log (actor_id)');
        $this->addSql('ALTER TABLE admin_audit_log ADD CONSTRAINT FK_ADMIN_AUDIT_ACTOR FOREIGN KEY (actor_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }
}

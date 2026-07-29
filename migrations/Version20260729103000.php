<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260729103000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add five-day trials, email verification, AI quota, deleted-email blocks, and detachable training scans';
    }

    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` ADD COLUMN IF NOT EXISTS email_verified_at DATETIME DEFAULT NULL, ADD COLUMN IF NOT EXISTS email_verification_token_hash VARCHAR(64) DEFAULT NULL, ADD COLUMN IF NOT EXISTS email_verification_expires_at DATETIME DEFAULT NULL, ADD COLUMN IF NOT EXISTS trial_ends_at DATETIME DEFAULT NULL, ADD COLUMN IF NOT EXISTS trial_ai_uses INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_8D93D649A253787A ON `user` (email_verification_token_hash)');
        $this->addSql("UPDATE `user` u LEFT JOIN subscription s ON s.owner_id = u.id AND s.status = 'active' AND s.current_period_end > NOW() SET u.trial_ends_at = DATE_ADD(NOW(), INTERVAL 5 DAY) WHERE u.role = 'owner' AND s.id IS NULL");
        $this->addSql("UPDATE `user` SET email_verified_at = NOW() WHERE role = 'admin'");

        $this->addSql('CREATE TABLE IF NOT EXISTS deleted_email_block (id INT AUTO_INCREMENT NOT NULL, email_hash VARCHAR(64) NOT NULL, blocked_until DATETIME NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A397BB88CE2D7B2D (email_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_OWNER');
        $this->addSql('ALTER TABLE scan_capture CHANGE owner_id owner_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_OWNER FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_OWNER');
        $this->addSql('DELETE FROM scan_capture WHERE owner_id IS NULL');
        $this->addSql('ALTER TABLE scan_capture CHANGE owner_id owner_id INT UNSIGNED NOT NULL');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_OWNER FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('DROP TABLE deleted_email_block');
        $this->addSql('DROP INDEX UNIQ_8D93D649A253787A ON `user`');
        $this->addSql('ALTER TABLE `user` DROP email_verified_at, DROP email_verification_token_hash, DROP email_verification_expires_at, DROP trial_ends_at, DROP trial_ai_uses');
    }
}

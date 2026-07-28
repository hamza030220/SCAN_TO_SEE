<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align scan capture index metadata with Doctrine naming';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_BUSINESS');
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_MENU');
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_OWNER');
        $this->addSql('ALTER TABLE scan_capture CHANGE created_at created_at DATETIME NOT NULL, CHANGE reviewed_at reviewed_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX uniq_scan_capture_uuid ON scan_capture');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_D127C8DB2404B23D ON scan_capture (scan_uuid)');
        $this->addSql('DROP INDEX idx_scan_capture_owner ON scan_capture');
        $this->addSql('CREATE INDEX IDX_D127C8DB7E3C61F9 ON scan_capture (owner_id)');
        $this->addSql('DROP INDEX idx_scan_capture_business ON scan_capture');
        $this->addSql('CREATE INDEX IDX_D127C8DBA89DB457 ON scan_capture (business_id)');
        $this->addSql('DROP INDEX idx_scan_capture_menu ON scan_capture');
        $this->addSql('CREATE INDEX IDX_D127C8DBCCD7E912 ON scan_capture (menu_id)');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_MENU FOREIGN KEY (menu_id) REFERENCES menu (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_OWNER FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scan_region DROP FOREIGN KEY FK_SCAN_REGION_SCAN');
        $this->addSql('ALTER TABLE scan_region CHANGE corrected_at corrected_at DATETIME DEFAULT NULL');
        $this->addSql('DROP INDEX idx_scan_region_scan ON scan_region');
        $this->addSql('CREATE INDEX IDX_D3D3D4882827AAD3 ON scan_region (scan_id)');
        $this->addSql('ALTER TABLE scan_region ADD CONSTRAINT FK_SCAN_REGION_SCAN FOREIGN KEY (scan_id) REFERENCES scan_capture (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // Index naming has no behavioral effect; keep the Doctrine-aligned form.
    }
}

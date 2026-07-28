<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260728120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Store OCR scans and region-level reviewed labels for training data';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE scan_capture (
                id INT AUTO_INCREMENT NOT NULL,
                owner_id INT UNSIGNED NOT NULL,
                business_id INT DEFAULT NULL,
                menu_id INT DEFAULT NULL,
                scan_uuid VARCHAR(36) NOT NULL,
                original_image_url LONGTEXT DEFAULT NULL,
                original_public_id VARCHAR(255) DEFAULT NULL,
                model_version VARCHAR(100) NOT NULL,
                inference_manifest JSON DEFAULT NULL,
                quality_metrics JSON DEFAULT NULL,
                raw_response JSON NOT NULL,
                corrected_response JSON DEFAULT NULL,
                status VARCHAR(20) NOT NULL,
                created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                reviewed_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                UNIQUE INDEX UNIQ_SCAN_CAPTURE_UUID (scan_uuid),
                INDEX IDX_SCAN_CAPTURE_OWNER (owner_id),
                INDEX IDX_SCAN_CAPTURE_BUSINESS (business_id),
                INDEX IDX_SCAN_CAPTURE_MENU (menu_id),
                INDEX IDX_SCAN_CAPTURE_STATUS_CREATED (status, created_at),
                INDEX IDX_SCAN_CAPTURE_MODEL (model_version),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );
        $this->addSql(
            'CREATE TABLE scan_region (
                id INT AUTO_INCREMENT NOT NULL,
                scan_id INT NOT NULL,
                box_id INT NOT NULL,
                role VARCHAR(30) NOT NULL,
                pair_box_id INT DEFAULT NULL,
                group_box_id INT DEFAULT NULL,
                geometry JSON DEFAULT NULL,
                crop_url LONGTEXT DEFAULT NULL,
                crop_public_id VARCHAR(255) DEFAULT NULL,
                crop_asset_id VARCHAR(255) DEFAULT NULL,
                raw_text LONGTEXT NOT NULL,
                confidence DOUBLE PRECISION NOT NULL,
                raw_json JSON NOT NULL,
                corrected_text LONGTEXT DEFAULT NULL,
                review_outcome VARCHAR(20) DEFAULT NULL,
                corrected_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
                INDEX IDX_SCAN_REGION_SCAN (scan_id),
                INDEX IDX_SCAN_REGION_REVIEW (review_outcome),
                UNIQUE INDEX uniq_scan_region_box (scan_id, box_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_OWNER FOREIGN KEY (owner_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_BUSINESS FOREIGN KEY (business_id) REFERENCES business (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scan_capture ADD CONSTRAINT FK_SCAN_CAPTURE_MENU FOREIGN KEY (menu_id) REFERENCES menu (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE scan_region ADD CONSTRAINT FK_SCAN_REGION_SCAN FOREIGN KEY (scan_id) REFERENCES scan_capture (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE scan_region DROP FOREIGN KEY FK_SCAN_REGION_SCAN');
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_OWNER');
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_BUSINESS');
        $this->addSql('ALTER TABLE scan_capture DROP FOREIGN KEY FK_SCAN_CAPTURE_MENU');
        $this->addSql('DROP TABLE scan_region');
        $this->addSql('DROP TABLE scan_capture');
    }
}

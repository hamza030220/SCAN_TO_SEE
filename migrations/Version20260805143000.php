<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805143000 extends AbstractMigration
{
    public function getDescription(): string { return 'Add AI training jobs and dataset exclusion controls'; }

    public function up(Schema $schema): void
    {
        if (!$schema->getTable('scan_region')->hasColumn('excluded_from_training')) {
            $this->addSql('ALTER TABLE scan_region ADD excluded_from_training TINYINT(1) DEFAULT 0 NOT NULL, ADD exclusion_reason VARCHAR(500) DEFAULT NULL');
        }
        $hadTrainingTable = $schema->hasTable('training_job');
        if (!$hadTrainingTable) {
            $this->addSql('CREATE TABLE training_job (id INT AUTO_INCREMENT NOT NULL, requested_by_id INT UNSIGNED NOT NULL, status VARCHAR(20) NOT NULL, phase VARCHAR(80) NOT NULL, progress INT NOT NULL, parameters JSON NOT NULL, dataset_summary JSON DEFAULT NULL, baseline_metrics JSON DEFAULT NULL, candidate_metrics JSON DEFAULT NULL, dataset_path LONGTEXT DEFAULT NULL, candidate_path LONGTEXT DEFAULT NULL, recommendation VARCHAR(20) DEFAULT NULL, stop_requested TINYINT(1) NOT NULL, log_excerpt LONGTEXT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, started_at DATETIME DEFAULT NULL, finished_at DATETIME DEFAULT NULL, INDEX IDX_616697B14DA1E751 (requested_by_id), INDEX IDX_TRAINING_STATUS_CREATED (status, created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        } else {
            $this->addSql('ALTER TABLE training_job MODIFY requested_by_id INT UNSIGNED NOT NULL');
        }
        if (!$hadTrainingTable || !$schema->getTable('training_job')->hasForeignKey('FK_8E8C2F94EF176F5F')) {
            $this->addSql('ALTER TABLE training_job ADD CONSTRAINT FK_8E8C2F94EF176F5F FOREIGN KEY (requested_by_id) REFERENCES `user` (id) ON DELETE CASCADE');
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE training_job');
        $this->addSql('ALTER TABLE scan_region DROP excluded_from_training, DROP exclusion_reason');
    }
}

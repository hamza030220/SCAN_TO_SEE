<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Preserve paid access until scheduled cancellation and during a payment retry grace period.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD cancel_at_period_end TINYINT NOT NULL, ADD payment_grace_ends_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP cancel_at_period_end, DROP payment_grace_ends_at');
    }
}

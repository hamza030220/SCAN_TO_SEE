<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260806113000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Schedule paid plan downgrades at the current billing period end.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription ADD pending_plan VARCHAR(20) DEFAULT NULL, ADD pending_billing_period VARCHAR(10) DEFAULT NULL, ADD pending_plan_effective_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE subscription DROP pending_plan, DROP pending_billing_period, DROP pending_plan_effective_at');
    }
}

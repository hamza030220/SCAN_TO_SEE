<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260805120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Hash existing two-factor backup codes at rest';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, backup_codes FROM `user` WHERE backup_codes IS NOT NULL',
        );

        foreach ($rows as $row) {
            $codes = json_decode((string) $row['backup_codes'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($codes)) {
                continue;
            }

            $changed = false;
            foreach ($codes as &$code) {
                if (!is_string($code) || password_get_info($code)['algo'] !== null) {
                    continue;
                }

                $code = password_hash($code, PASSWORD_DEFAULT);
                $changed = true;
            }
            unset($code);

            if ($changed) {
                $this->connection->update(
                    'user',
                    ['backup_codes' => json_encode($codes, JSON_THROW_ON_ERROR)],
                    ['id' => $row['id']],
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->write('Backup-code hashes are intentionally irreversible; no data change was made.');
    }
}

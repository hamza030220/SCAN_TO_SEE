<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

final class AdminAnalyticsService
{
    public function __construct(private readonly Connection $connection) {}

    public function overview(): array
    {
        $owners = $this->row("SELECT
            COUNT(*) total,
            SUM(is_active = 1) active,
            SUM(email_verified_at IS NOT NULL) verified,
            SUM(trial_ends_at > NOW()) trials
            FROM `user` WHERE role = 'owner'");
        $menus = $this->groupCounts('SELECT status, COUNT(*) total FROM menu GROUP BY status');
        $subscriptions = $this->groupCounts('SELECT status, COUNT(*) total FROM subscription GROUP BY status');
        $plans = $this->groupCounts('SELECT plan, COUNT(*) total FROM subscription WHERE status = \'active\' GROUP BY plan');
        $scans = $this->row("SELECT
            COUNT(*) total,
            SUM(status = 'reviewed') reviewed,
            SUM(created_at >= CURDATE()) today,
            SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)) week
            FROM scan_capture");
        $regions = $this->row("SELECT
            COUNT(*) total,
            SUM(review_outcome = 'accepted') accepted,
            SUM(review_outcome = 'modified') modified,
            SUM(review_outcome = 'deleted') deleted,
            SUM(excluded_from_training = 1) excluded,
            AVG(confidence) average_confidence
            FROM scan_region");

        $verified = (int) ($owners['verified'] ?? 0);
        $paid = (int) ($subscriptions['active'] ?? 0);

        return [
            'owners' => array_map('intval', $owners),
            'businesses' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM business'),
            'menus' => $menus,
            'subscriptions' => $subscriptions,
            'plans' => $plans,
            'scans' => array_map('intval', $scans),
            'regions' => [
                'total' => (int) ($regions['total'] ?? 0),
                'accepted' => (int) ($regions['accepted'] ?? 0),
                'modified' => (int) ($regions['modified'] ?? 0),
                'deleted' => (int) ($regions['deleted'] ?? 0),
                'excluded' => (int) ($regions['excluded'] ?? 0),
                'averageConfidence' => round(((float) ($regions['average_confidence'] ?? 0)) * 100, 1),
            ],
            'conversionRate' => $verified > 0 ? round($paid / $verified * 100, 1) : 0.0,
            'ownerTrend' => $this->dailySeries('user', "role = 'owner'"),
            'scanTrend' => $this->dailySeries('scan_capture'),
        ];
    }

    private function row(string $sql): array
    {
        return $this->connection->fetchAssociative($sql) ?: [];
    }

    private function groupCounts(string $sql): array
    {
        $result = [];
        foreach ($this->connection->fetchAllAssociative($sql) as $row) {
            $key = (string) array_shift($row);
            $result[$key] = (int) ($row['total'] ?? 0);
        }
        return $result;
    }

    private function dailySeries(string $table, ?string $condition = null): array
    {
        $since = (new \DateTimeImmutable('-13 days midnight'))->format('Y-m-d H:i:s');
        $where = 'created_at >= :since' . ($condition ? ' AND ' . $condition : '');
        $rows = $this->connection->fetchAllKeyValue(
            sprintf('SELECT DATE(created_at) day, COUNT(*) total FROM `%s` WHERE %s GROUP BY DATE(created_at)', $table, $where),
            ['since' => $since],
        );
        $series = [];
        $max = max(1, ...array_map('intval', $rows ?: [1]));
        for ($i = 13; $i >= 0; --$i) {
            $date = new \DateTimeImmutable("-{$i} days");
            $value = (int) ($rows[$date->format('Y-m-d')] ?? 0);
            $series[] = [
                'date' => $date->format('M j'),
                'value' => $value,
                'height' => max(4, (int) round($value / $max * 100)),
            ];
        }
        return $series;
    }
}

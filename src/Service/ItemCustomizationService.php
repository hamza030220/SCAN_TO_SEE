<?php

namespace App\Service;

final class ItemCustomizationService
{
    private const BADGES = ['', 'Popular', 'New', 'Chef choice', 'Limited'];

    public function text(?string $value, int $maxLength): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $maxLength);
    }

    public function badge(?string $value): ?string
    {
        $value = trim((string) $value);

        return in_array($value, self::BADGES, true) && $value !== '' ? $value : null;
    }

    public function labels(?string $value): array
    {
        $labels = preg_split('/\s*,\s*/u', trim((string) $value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $result = [];
        foreach ($labels as $label) {
            $normalized = mb_substr(trim($label), 0, 30);
            $key = mb_strtolower($normalized);
            if ($normalized !== '' && !isset($result[$key])) {
                $result[$key] = $normalized;
            }
            if (count($result) === 10) break;
        }

        return array_values($result);
    }

    public function variants(array $names, array $prices): array
    {
        $variants = [];
        foreach ($names as $index => $rawName) {
            $name = $this->text(is_scalar($rawName) ? (string) $rawName : '', 60);
            $rawPrice = $prices[$index] ?? null;
            if ($name === null && ($rawPrice === null || $rawPrice === '')) {
                continue;
            }
            if ($name === null || !is_scalar($rawPrice) || !is_numeric((string) $rawPrice)) {
                throw new \InvalidArgumentException('Each variant needs a name and a valid price.');
            }
            $price = (float) $rawPrice;
            if ($price < 0 || $price > 9_999_999.99) {
                throw new \InvalidArgumentException('Variant prices must be between 0 and 9,999,999.99.');
            }
            $variants[] = ['name' => $name, 'price' => number_format($price, 2, '.', '')];
            if (count($variants) === 12) break;
        }

        return $variants;
    }
}

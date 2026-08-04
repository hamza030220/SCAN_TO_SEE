<?php

namespace App\Service;

final class MenuHeroConfigService
{
    public const MAX_LAYERS = 20;

    public function __construct(private readonly MenuFontCatalogService $fontCatalog)
    {
    }

    public function defaults(): array
    {
        return [
            'version' => 1,
            'enabled' => false,
            'startsAt' => null,
            'expiresAt' => null,
            'desktopHeight' => 360,
            'mobileHeight' => 320,
            'layers' => [$this->backgroundLayer()],
        ];
    }

    public function sanitize(array $input): array
    {
        $defaults = $this->defaults();
        $layers = [$this->sanitizeBackground($this->findBackground($input['layers'] ?? []))];
        $seen = ['background' => true];

        foreach (is_array($input['layers'] ?? null) ? $input['layers'] : [] as $index => $layer) {
            if (!is_array($layer) || ($layer['type'] ?? null) === 'background' || count($layers) >= self::MAX_LAYERS) {
                continue;
            }

            $type = $this->enum($layer['type'] ?? null, ['text', 'image', 'shape', 'countdown'], 'text');
            $id = is_string($layer['id'] ?? null) && preg_match('/^[a-zA-Z0-9_-]{1,40}$/D', $layer['id'])
                ? $layer['id']
                : 'layer-'.bin2hex(random_bytes(5));
            if (isset($seen[$id])) {
                $id .= '-'.($index + 1);
            }
            $seen[$id] = true;

            $sanitized = [
                'id' => $id,
                'name' => $this->text($layer['name'] ?? null, ucfirst($type).' '.count($layers), 50),
                'type' => $type,
                'visible' => (bool) ($layer['visible'] ?? true),
                'locked' => (bool) ($layer['locked'] ?? false),
                'opacity' => $this->number($layer['opacity'] ?? 1, 0, 1, 1, 2),
                'rotation' => $this->number($layer['rotation'] ?? 0, -180, 180, 0),
                'desktop' => $this->position($layer['desktop'] ?? null, ['x' => 10, 'y' => 15, 'width' => 80, 'height' => 24]),
                'mobile' => $this->position($layer['mobile'] ?? null, ['x' => 8, 'y' => 15, 'width' => 84, 'height' => 24]),
            ];

            $layers[] = array_merge($sanitized, match ($type) {
                'image' => $this->imageSettings($layer),
                'shape' => $this->shapeSettings($layer),
                'countdown' => $this->countdownSettings($layer),
                default => $this->textSettings($layer),
            });
        }

        return [
            'version' => 1,
            'enabled' => (bool) ($input['enabled'] ?? false),
            'startsAt' => $this->date($input['startsAt'] ?? null),
            'expiresAt' => $this->date($input['expiresAt'] ?? null),
            'desktopHeight' => (int) $this->number($input['desktopHeight'] ?? $defaults['desktopHeight'], 240, 600, 360),
            'mobileHeight' => (int) $this->number($input['mobileHeight'] ?? $defaults['mobileHeight'], 220, 520, 320),
            'layers' => $layers,
        ];
    }

    /** @return list<string> */
    public function publishingErrors(array $config, ?\DateTimeImmutable $now = null): array
    {
        $errors = [];
        $now ??= new \DateTimeImmutable();
        if (!($config['enabled'] ?? false)) {
            $errors[] = 'Turn on “Show hero” before publishing it.';
        }

        $contentLayers = array_values(array_filter($config['layers'] ?? [], static fn (array $layer): bool => $layer['type'] !== 'background' && ($layer['visible'] ?? false)));
        if ($contentLayers === []) {
            $errors[] = 'Add at least one visible text, image, shape, or countdown layer.';
        }

        $startsAt = $this->parseDate($config['startsAt'] ?? null);
        $expiresAt = $this->parseDate($config['expiresAt'] ?? null);
        if ($expiresAt !== null && $expiresAt <= $now) {
            $errors[] = 'The expiration time must be in the future.';
        }
        if ($startsAt !== null && $expiresAt !== null && $startsAt >= $expiresAt) {
            $errors[] = 'The start time must be earlier than the expiration time.';
        }

        foreach ($contentLayers as $layer) {
            if ($layer['type'] === 'text' && trim((string) ($layer['content'] ?? '')) === '') {
                $errors[] = sprintf('%s needs some text.', $layer['name']);
            }
            if ($layer['type'] === 'image' && ($layer['imagePath'] ?? null) === null) {
                $errors[] = sprintf('%s needs an uploaded image.', $layer['name']);
            }
            if ($layer['type'] === 'countdown' && $expiresAt === null) {
                $errors[] = sprintf('%s needs an expiration date and time.', $layer['name']);
            }
        }

        return array_values(array_unique($errors));
    }

    private function backgroundLayer(): array
    {
        return [
            'id' => 'background', 'name' => 'Background', 'type' => 'background',
            'visible' => true, 'locked' => true, 'color' => '#18120A',
            'imagePath' => null, 'overlayColor' => '#000000', 'overlayOpacity' => 0,
        ];
    }

    private function findBackground(mixed $layers): array
    {
        if (is_array($layers)) {
            foreach ($layers as $layer) {
                if (is_array($layer) && ($layer['type'] ?? null) === 'background') return $layer;
            }
        }
        return $this->backgroundLayer();
    }

    private function sanitizeBackground(array $layer): array
    {
        $path = $this->heroImagePath($layer['imagePath'] ?? null);
        return [
            'id' => 'background', 'name' => 'Background', 'type' => 'background',
            'visible' => true, 'locked' => true,
            'color' => $this->color($layer['color'] ?? null, '#18120A'),
            'imagePath' => $path,
            'overlayColor' => $this->color($layer['overlayColor'] ?? null, '#000000'),
            'overlayOpacity' => $this->number($layer['overlayOpacity'] ?? 0, 0, .85, 0, 2),
        ];
    }

    private function textSettings(array $layer): array
    {
        return [
            'content' => $this->text($layer['content'] ?? null, 'Special offer', 300),
            'font' => $this->font($layer['font'] ?? null, 'Space Grotesk'),
            'fontSize' => (int) $this->number($layer['fontSize'] ?? 38, 12, 96, 38),
            'fontWeight' => $this->enum($layer['fontWeight'] ?? null, ['400', '600', '700', '800'], '700'),
            'color' => $this->color($layer['color'] ?? null, '#FFFFFF'),
            'align' => $this->enum($layer['align'] ?? null, ['left', 'center', 'right'], 'center'),
            'backgroundEnabled' => (bool) ($layer['backgroundEnabled'] ?? false),
            'backgroundColor' => $this->color($layer['backgroundColor'] ?? null, '#000000'),
        ];
    }

    private function imageSettings(array $layer): array
    {
        return [
            'imagePath' => $this->heroImagePath($layer['imagePath'] ?? null),
            'alt' => $this->text($layer['alt'] ?? null, '', 150),
            'fit' => $this->enum($layer['fit'] ?? null, ['contain', 'cover'], 'contain'),
            'radius' => (int) $this->number($layer['radius'] ?? 12, 0, 50, 12),
        ];
    }

    private function shapeSettings(array $layer): array
    {
        return [
            'shape' => $this->enum($layer['shape'] ?? null, ['rectangle', 'circle'], 'rectangle'),
            'fill' => $this->color($layer['fill'] ?? null, '#E8A020'),
            'borderColor' => $this->color($layer['borderColor'] ?? null, '#FFFFFF'),
            'borderWidth' => (int) $this->number($layer['borderWidth'] ?? 0, 0, 12, 0),
        ];
    }

    private function countdownSettings(array $layer): array
    {
        return [
            'font' => $this->font($layer['font'] ?? null, 'Space Grotesk'),
            'fontSize' => (int) $this->number($layer['fontSize'] ?? 28, 12, 64, 28),
            'fontWeight' => $this->enum($layer['fontWeight'] ?? null, ['400', '600', '700', '800'], '700'),
            'color' => $this->color($layer['color'] ?? null, '#FFFFFF'),
            'backgroundColor' => $this->color($layer['backgroundColor'] ?? null, '#18120A'),
            'showLabels' => (bool) ($layer['showLabels'] ?? true),
        ];
    }

    private function position(mixed $position, array $default): array
    {
        $position = is_array($position) ? $position : [];
        $x = $this->number($position['x'] ?? $default['x'], 0, 95, $default['x'], 2);
        $y = $this->number($position['y'] ?? $default['y'], 0, 95, $default['y'], 2);
        return [
            'x' => $x,
            'y' => $y,
            'width' => $this->number($position['width'] ?? $default['width'], 5, 100 - $x, min($default['width'], 100 - $x), 2),
            'height' => $this->number($position['height'] ?? $default['height'], 5, 100 - $y, min($default['height'], 100 - $y), 2),
        ];
    }

    private function heroImagePath(mixed $value): ?string
    {
        return is_string($value) && preg_match('#^image/hero/[a-zA-Z0-9._-]+$#D', $value) ? $value : null;
    }

    private function font(mixed $value, string $default): string
    {
        return is_string($value) && in_array($value, $this->fontCatalog->allowedFamilies(), true) ? $value : $default;
    }

    private function color(mixed $value, string $default): string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value) ? strtoupper($value) : $default;
    }

    private function enum(mixed $value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function text(mixed $value, string $default, int $max): string
    {
        return is_string($value) ? mb_substr(trim(strip_tags($value)), 0, $max) : $default;
    }

    private function number(mixed $value, float $min, float $max, float $default, int $precision = 0): float
    {
        $number = is_numeric($value) ? (float) $value : $default;
        return round(min($max, max($min, $number)), $precision);
    }

    private function date(mixed $value): ?string
    {
        $date = $this->parseDate($value);
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format(DATE_ATOM);
    }

    private function parseDate(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || trim($value) === '') return null;
        try { return new \DateTimeImmutable($value); } catch (\Exception) { return null; }
    }
}

<?php

namespace App\Service;

use App\Entity\Menu;

final class MenuThemeConfigService
{
    private const GRADIENT_DIRECTIONS = ['to bottom', 'to right', '135deg', '45deg'];

    public function __construct(private readonly MenuFontCatalogService $fontCatalog)
    {
    }

    public function sanitize(array $input, array $current = []): array
    {
        $defaults = Menu::DEFAULT_THEME;

        return [
            'theme' => $this->enum($input, 'theme', ['light', 'dark'], $defaults['theme']),
            'font' => $this->enum($input, 'font', $this->fontCatalog->allowedFamilies(), $defaults['font']),
            'fontScale' => min(1.2, max(0.85, round((float) ($input['fontScale'] ?? $defaults['fontScale']), 2))),
            'layout' => $this->enum($input, 'layout', ['list', 'grid', 'compact'], $defaults['layout']),
            'density' => $this->enum($input, 'density', ['compact', 'comfortable', 'spacious'], $defaults['density']),
            'bgType' => $this->enum($input, 'bgType', ['solid', 'gradient', 'image'], $defaults['bgType']),
            'bgColor' => $this->color($input, 'bgColor', $defaults['bgColor']),
            'bgGradientStart' => $this->color($input, 'bgGradientStart', $defaults['bgGradientStart']),
            'bgGradientEnd' => $this->color($input, 'bgGradientEnd', $defaults['bgGradientEnd']),
            'bgGradientDir' => $this->enum($input, 'bgGradientDir', self::GRADIENT_DIRECTIONS, $defaults['bgGradientDir']),
            'bgImagePath' => $current['bgImagePath'] ?? null,
            'headerBg' => $this->color($input, 'headerBg', $defaults['headerBg']),
            'accent' => $this->color($input, 'accent', $defaults['accent']),
            'cardStyle' => $this->enum($input, 'cardStyle', ['flat', 'glass', 'bordered'], $defaults['cardStyle']),
            'cardBg' => $this->color($input, 'cardBg', $defaults['cardBg']),
            'cardRadius' => min(24, max(0, (int) ($input['cardRadius'] ?? $defaults['cardRadius']))),
            'imageShape' => $this->enum($input, 'imageShape', ['square', 'rounded', 'circle'], $defaults['imageShape']),
            'priceStyle' => $this->enum($input, 'priceStyle', ['accent', 'neutral', 'badge'], $defaults['priceStyle']),
            'glassBlur' => min(30, max(2, (int) ($input['glassBlur'] ?? $defaults['glassBlur']))),
            'glassOpacity' => min(0.6, max(0.05, round((float) ($input['glassOpacity'] ?? $defaults['glassOpacity']), 2))),
            'pillStyle' => $this->enum($input, 'pillStyle', ['pill', 'underline', 'chip'], $defaults['pillStyle']),
            'logoAlign' => $this->enum($input, 'logoAlign', ['flex-start', 'center', 'flex-end'], $defaults['logoAlign']),
        ];
    }

    private function enum(array $input, string $key, array $allowed, string $default): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    private function color(array $input, string $key, string $default): string
    {
        $value = $input[$key] ?? null;

        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value)
            ? strtoupper($value)
            : $default;
    }
}

<?php

namespace App\Service;

use App\Entity\Menu;

final class MenuThemeConfigService
{
    public const FONTS = [
        'DM Sans',
        'Space Grotesk',
        'Playfair Display',
        'Poppins',
        'Montserrat',
        'Inter',
    ];

    private const GRADIENT_DIRECTIONS = ['to bottom', 'to right', '135deg', '45deg'];

    public function sanitize(array $input, array $current = []): array
    {
        $defaults = Menu::DEFAULT_THEME;

        return [
            'theme' => $this->enum($input, 'theme', ['light', 'dark'], $defaults['theme']),
            'font' => $this->enum($input, 'font', self::FONTS, $defaults['font']),
            'layout' => $this->enum($input, 'layout', ['list', 'grid', 'compact'], $defaults['layout']),
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

<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final class MenuFontCatalogService
{
    public const BUILT_IN_FONTS = [
        'DM Sans',
        'Space Grotesk',
        'Playfair Display',
        'Poppins',
        'Montserrat',
        'Inter',
        'Roboto',
        'Open Sans',
        'Lato',
        'Nunito',
        'Raleway',
        'Oswald',
        'Merriweather',
        'Bebas Neue',
    ];

    private const SUPPORTED_EXTENSIONS = ['ttf', 'otf', 'woff', 'woff2'];

    private string $fontDirectory;

    public function __construct(#[Autowire('%kernel.project_dir%')] string $projectDir)
    {
        $this->fontDirectory = rtrim($projectDir, '/\\').'/public/fontes_desgne_tool';
    }

    /** @return list<array{family: string, filename: string, path: string, format: string}> */
    public function customFonts(): array
    {
        if (!is_dir($this->fontDirectory)) {
            return [];
        }

        $fonts = [];
        $entries = scandir($this->fontDirectory) ?: [];
        foreach ($entries as $filename) {
            $absolutePath = $this->fontDirectory.DIRECTORY_SEPARATOR.$filename;
            $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            if (!is_file($absolutePath)
                || !in_array($extension, self::SUPPORTED_EXTENSIONS, true)
                || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._ -]*$/D', $filename)) {
                continue;
            }

            $label = trim((string) preg_replace('/[_-]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));
            if ($label === '') {
                continue;
            }

            $fonts[] = [
                'family' => 'Custom '.$label,
                'filename' => $filename,
                'path' => 'fontes_desgne_tool/'.rawurlencode($filename),
                'format' => match ($extension) {
                    'ttf' => 'truetype',
                    'otf' => 'opentype',
                    'woff' => 'woff',
                    default => 'woff2',
                },
            ];
        }

        usort($fonts, static fn (array $a, array $b): int => strcasecmp($a['family'], $b['family']));

        return $fonts;
    }

    /** @return list<string> */
    public function allowedFamilies(): array
    {
        return array_merge(
            self::BUILT_IN_FONTS,
            array_column($this->customFonts(), 'family'),
        );
    }
}

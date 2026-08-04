<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageUploadService
{
    /** Max upload size: 2 MB */
    private const MAX_BYTES = 2_097_152;

    /** Accepted MIME types */
    private const ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {}

    // ── Public API ────────────────────────────────────────────────────────

    /**
     * Validate and upload a business logo.
     * Returns the web-relative path stored in the DB (e.g. "image/business/logo_cafearabica.jpg").
     * Throws \RuntimeException on validation failure — catch in the controller and show as $error.
     */
    public function uploadBusinessLogo(UploadedFile $file, string $businessName): string
    {
        $this->validate($file);
        return $this->upload($file, 'business', 'logo_' . $this->slugify($businessName));
    }

    /**
     * Validate and upload an item image.
     * Returns the web-relative path stored in the DB (e.g. "image/items/image_espresso.jpg").
     */
    public function uploadItemImage(UploadedFile $file, string $itemName): string
    {
        $this->validate($file);
        return $this->upload($file, 'items', 'image_' . $this->slugify($itemName));
    }

    /**
     * Validate and upload a menu background image.
     * Returns the web-relative path (e.g. "image/menu/bg_my-menu.jpg").
     */
    public function uploadMenuBg(UploadedFile $file, string $menuSlug): string
    {
        $this->validate($file);
        return $this->upload($file, 'menu', 'bg_' . $this->slugify($menuSlug));
    }

    /** Upload an image used by the menu hero background or an image layer. */
    public function uploadHeroImage(UploadedFile $file, string $menuSlug): string
    {
        $this->validate($file);
        return $this->upload($file, 'hero', 'hero_' . $this->slugify($menuSlug));
    }

    /**
     * Delete a previously stored image (pass the web-relative path from the DB).
     * Silently ignores missing files.
     */
    public function delete(?string $webPath): void
    {
        if (!$webPath) {
            return;
        }
        $full = $this->projectDir . '/public/' . $webPath;
        if (is_file($full)) {
            @unlink($full);
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────

    private function validate(UploadedFile $file): void
    {
        if (!in_array($file->getMimeType(), self::ALLOWED_MIME, true)) {
            throw new \RuntimeException('Image must be JPG, PNG, or WEBP.');
        }
        if ($file->getSize() > self::MAX_BYTES) {
            throw new \RuntimeException('Image must be under 2 MB.');
        }
    }

    private function upload(UploadedFile $file, string $subfolder, string $baseName): string
    {
        $ext = strtolower($file->guessExtension() ?? 'jpg');
        $dir = $this->projectDir . '/public/image/' . $subfolder;

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        // Deduplicate: logo_cafearabica.jpg → logo_cafearabica_1.jpg → ...
        $filename = $baseName . '.' . $ext;
        $counter  = 1;
        while (file_exists($dir . '/' . $filename)) {
            $filename = $baseName . '_' . $counter++ . '.' . $ext;
        }

        $file->move($dir, $filename);

        return 'image/' . $subfolder . '/' . $filename;
    }

    /**
     * Converts a UTF-8 string to a safe ASCII filename slug.
     * "Café Arabica" → "cafe_arabica"
     */
    private function slugify(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        // Transliterate accented chars to ASCII
        if (function_exists('iconv')) {
            $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($ascii !== false) {
                $s = $ascii;
            }
        }
        // Keep only alphanumeric + underscores
        $s = preg_replace('/[^a-z0-9]+/', '_', $s);
        return trim($s, '_') ?: 'file';
    }
}

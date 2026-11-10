<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support;

/**
 * Decides whether bytes must go through the SVG sanitiser.
 *
 * Any signal counts: a declared SVG type or extension, a sniffed SVG type, or
 * an "<svg" element anywhere in the content. Looking only at a leading prologue
 * fails open, because a long comment, doctype or whitespace prefix pushes the
 * element past the window and the markup is then stored and served unsanitised.
 */
final class SvgContentDetector
{
    public static function isSvg(string $contents, ?string $declaredMimeType = null, ?string $declaredExtension = null): bool
    {
        if (self::declaresSvg($declaredMimeType, $declaredExtension)) {
            return true;
        }

        $sniffed = self::sniffMimeType($contents);

        if ($sniffed !== null && str_contains($sniffed, 'svg')) {
            return true;
        }

        return stripos($contents, '<svg') !== false;
    }

    public static function declaresSvg(?string $declaredMimeType, ?string $declaredExtension): bool
    {
        $extension = strtolower(ltrim(trim((string) $declaredExtension), '.'));

        return in_array($extension, ['svg', 'svgz'], true)
            || str_contains(strtolower((string) $declaredMimeType), 'svg');
    }

    private static function sniffMimeType(string $contents): ?string
    {
        if ($contents === '' || ! function_exists('finfo_open')) {
            return null;
        }

        $fileInfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($fileInfo === false) {
            return null;
        }

        try {
            $mimeType = finfo_buffer($fileInfo, $contents);
        } finally {
            finfo_close($fileInfo);
        }

        return is_string($mimeType) && $mimeType !== '' ? strtolower($mimeType) : null;
    }
}

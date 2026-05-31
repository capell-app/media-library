<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Models;

use Awcodes\Curator\Models\Media as BaseCuratorMedia;
use Capell\Core\Contracts\Media\MediaContract;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Curator's Media model, extended to satisfy Capell's backend-agnostic
 * MediaContract. Subclasses Awcodes\Curator\Models\Media so Curator-native
 * features (picker, library, glide) continue to work untouched.
 *
 * Curator exposes Glide-backed thumbnail, medium, and large URLs rather than
 * Spatie conversion files. The contract methods map those existing URLs into
 * conversion/srcset semantics without changing Curator storage.
 */
final class CuratorMedia extends BaseCuratorMedia implements MediaContract
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    public function getUrl(string $conversion = ''): string
    {
        $conversionUrl = match ($conversion) {
            'thumb', 'thumbnail' => $this->thumbnail_url,
            'medium' => $this->medium_url,
            'large' => $this->large_url,
            default => null,
        };

        if (is_string($conversionUrl) && $conversionUrl !== '') {
            return $conversionUrl;
        }

        // `url` is an Eloquent accessor on BaseCuratorMedia; reading
        // $this->url triggers it and returns a storage-resolved URL.
        return (string) $this->url;
    }

    public function getFullUrl(string $conversion = ''): string
    {
        return $this->getUrl($conversion);
    }

    /**
     * @param  array<int, string>  $conversions
     */
    public function getAvailableFullUrl(array $conversions): string
    {
        foreach ($conversions as $conversion) {
            if ($this->hasConversion($conversion)) {
                return $this->getUrl($conversion);
            }
        }

        return $this->getUrl();
    }

    public function getSrcset(): string
    {
        $responsiveImages = $this->metadataArray('responsive_images');

        foreach ($responsiveImages as $responsiveImage) {
            if (is_array($responsiveImage) && is_string($responsiveImage['srcset'] ?? null) && $responsiveImage['srcset'] !== '') {
                return $responsiveImage['srcset'];
            }
        }

        if (! str_starts_with($this->type, 'image/')) {
            return '';
        }

        $candidates = [
            ['conversion' => 'thumbnail', 'width' => 200],
            ['conversion' => 'medium', 'width' => 640],
            ['conversion' => 'large', 'width' => 1024],
        ];

        $srcset = collect($candidates)
            ->filter(fn (array $candidate): bool => $this->hasConversion($candidate['conversion']))
            ->map(fn (array $candidate): string => sprintf(
                '%s %dw',
                $this->getUrl($candidate['conversion']),
                $candidate['width'],
            ))
            ->values()
            ->all();

        return implode(', ', $srcset);
    }

    public function hasResponsiveImages(): bool
    {
        return $this->getSrcset() !== '';
    }

    public function hasConversion(string $conversion): bool
    {
        if (! str_starts_with($this->type, 'image/')) {
            return false;
        }

        if (in_array($conversion, ['thumb', 'thumbnail', 'medium', 'large'], true)) {
            return true;
        }

        $generatedConversions = $this->metadataArray('generated_conversions');

        return (bool) ($generatedConversions[$conversion] ?? false);
    }

    public function getName(): string
    {
        $name = $this->name ?? null;

        if ($name !== null && $name !== '') {
            return $name;
        }

        $prettyName = $this->pretty_name ?? null;

        return $prettyName ?? '';
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getMimeType(): string
    {
        return $this->type;
    }

    public function getWidth(): int
    {
        return (int) ($this->width ?? 0);
    }

    public function getHeight(): int
    {
        return (int) ($this->height ?? 0);
    }

    public function getCustomProperty(string $key, mixed $default = null): mixed
    {
        return match ($key) {
            'alt' => $this->alt ?? $default,
            'title' => $this->title ?? $default,
            'description' => $this->description ?? $default,
            'caption' => $this->caption ?? $default,
            'width' => $this->width ?? $default,
            'height' => $this->height ?? $default,
            default => $default,
        };
    }

    /**
     * @return array<array-key, mixed>
     */
    private function metadataArray(string $attribute): array
    {
        $value = $this->getAttribute($attribute);

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

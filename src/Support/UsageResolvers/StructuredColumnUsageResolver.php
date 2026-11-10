<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support\UsageResolvers;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver;
use Capell\MediaLibrary\Support\Database\StructuredColumnTextCast;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Media referenced from JSON, widget settings or rich text. A media counts as
 * referenced when a configured column contains its stored path, holds its id
 * under a media-like key (at any depth), or carries a rich-text media id
 * attribute. Matching is deliberately generous.
 */
final class StructuredColumnUsageResolver implements MediaUsageReferenceResolver
{
    private const array MEDIA_KEYS = [
        'media', 'image', 'file', 'attachment', 'document', 'logo',
        'icon', 'avatar', 'thumbnail', 'background', 'photo', 'picture', 'poster', 'cover', 'banner',
    ];

    private const int PATH_PATTERNS_PER_QUERY = 100;

    public function referencedMediaIds(Collection $media, ?array $ownerForeignKeys = null): array
    {
        /** @var array<int, string> $candidates id => stored path */
        $candidates = [];

        foreach ($media as $item) {
            $key = $item->getKey();
            $path = $item->getAttribute('path');

            if (is_numeric($key) && (int) $key > 0) {
                $candidates[(int) $key] = is_string($path) ? $path : '';
            }
        }

        if ($candidates === []) {
            return [];
        }

        $referenced = [];

        foreach ($this->columns() as [$table, $column]) {
            foreach ($this->scan($table, $column, $candidates) as $mediaId) {
                $referenced[$mediaId] = $mediaId;
            }

            if (count($referenced) === count($candidates)) {
                break;
            }
        }

        return array_values($referenced);
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function columns(): array
    {
        $configured = config('capell.media_library.structured_reference_columns', []);
        $schema = resolve(RuntimeSchemaState::class);
        $columns = [];

        foreach (is_array($configured) ? $configured : [] as $entry) {
            $table = is_array($entry) ? ($entry['table'] ?? null) : null;
            $column = is_array($entry) ? ($entry['column'] ?? null) : null;

            if (! is_string($table) || ! is_string($column) || preg_match('/^\w+$/', $table . $column) !== 1) {
                continue;
            }

            foreach ($this->tableNames($table) as $resolved) {
                if ($schema->hasTable($resolved) && $schema->hasColumn($resolved, $column)) {
                    $columns[$resolved . '.' . $column] = [$resolved, $column];

                    break;
                }
            }
        }

        return array_values($columns);
    }

    /**
     * @return list<string>
     */
    private function tableNames(string $table): array
    {
        try {
            return array_values(array_unique([TableName::resolve($table), $table]));
        } catch (Throwable) {
            return [$table];
        }
    }

    /**
     * @param  array<int, string>  $candidates
     * @return list<int>
     */
    private function scan(string $table, string $column, array $candidates): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $wrapped = resolve(StructuredColumnTextCast::class)->expression($connection, $grammar->wrap($column));
        $referenced = [];
        $pathChunks = array_chunk($this->searchableNames($candidates), self::PATH_PATTERNS_PER_QUERY);

        foreach ($pathChunks === [] ? [[]] : $pathChunks as $index => $names) {
            $patterns = $names;

            if ($index === 0) {
                $patterns = [...$patterns, ...self::MEDIA_KEYS, 'data-media-id', 'data-curator', 'data-id'];
            }

            $query = DB::table($table)->select($column)->whereNotNull($column);
            $query->where(function ($where) use ($patterns, $wrapped): void {
                foreach ($patterns as $pattern) {
                    $where->orWhereRaw('lower(' . $wrapped . ") like ? escape '!'", ['%' . $this->escapeLike(strtolower($pattern)) . '%']);
                }
            });

            foreach ($query->cursor() as $row) {
                $value = $row->{$column} ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                foreach ($this->referencedIn($value, $candidates) as $mediaId) {
                    $referenced[$mediaId] = $mediaId;
                }
            }
        }

        return array_values($referenced);
    }

    /**
     * File names are used to narrow the SQL, because JSON storage may escape
     * the slashes in a path. The exact path is confirmed in PHP afterwards.
     *
     * @param  array<int, string>  $candidates
     * @return list<string>
     */
    private function searchableNames(array $candidates): array
    {
        $names = [];

        foreach ($candidates as $path) {
            $name = basename($path);

            if ($name !== '' && $name !== '.') {
                $names[$name] = $name;
                $names[rawurlencode($name)] = rawurlencode($name);
            }
        }

        return array_values($names);
    }

    /**
     * @param  array<int, string>  $candidates
     * @return list<int>
     */
    private function referencedIn(string $value, array $candidates): array
    {
        $normalised = str_replace('\\/', '/', $value);
        $found = [];

        foreach ($candidates as $id => $path) {
            if ($path !== '' && (str_contains($normalised, $path) || str_contains($normalised, $this->encodePath($path)))) {
                $found[$id] = $id;
            }
        }

        $ids = [];

        if (preg_match_all('/data-(?:curator-id|curator-media-id|media-id|id)\s*=\s*\\\\*["\']?(\d+)/i', $value, $matches) > 0) {
            foreach ($matches[1] as $match) {
                $ids[(int) $match] = true;
            }
        }

        $decoded = $this->decode($value);

        if ($decoded !== null) {
            $this->collectIds($decoded, false, $ids);
        }

        foreach (array_keys($ids) as $id) {
            if (isset($candidates[$id])) {
                $found[$id] = $id;
            }
        }

        return array_values($found);
    }

    private function encodePath(string $path): string
    {
        return implode('/', array_map(rawurlencode(...), explode('/', $path)));
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function decode(string $value): ?array
    {
        $trimmed = ltrim($value);

        if ($trimmed === '' || ! in_array($trimmed[0], ['{', '[', '"'], true)) {
            return null;
        }

        $decoded = json_decode($value, true);

        // JSON stored as a JSON string (double encoding) is unwrapped once.
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, bool>  $ids
     */
    private function collectIds(array $data, bool $insideMediaKey, array &$ids): void
    {
        foreach ($data as $key => $value) {
            $isMediaKey = $insideMediaKey || $this->isMediaKey($key);

            if (is_array($value)) {
                $this->collectIds($value, $isMediaKey, $ids);

                continue;
            }

            if (is_string($value) && ! is_numeric($value)) {
                if (preg_match_all('/data-(?:curator-id|curator-media-id|media-id|id)\s*=\s*["\']?(\d+)/i', $value, $matches) > 0) {
                    foreach ($matches[1] as $match) {
                        $ids[(int) $match] = true;
                    }
                }

                continue;
            }

            $isIdKey = is_string($key) && strtolower($key) === 'id';

            if (($isMediaKey || ($insideMediaKey && $isIdKey)) && (is_int($value) || (is_string($value) && ctype_digit($value)))) {
                $ids[(int) $value] = true;
            }
        }
    }

    private function isMediaKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        $lower = strtolower($key);

        foreach (self::MEDIA_KEYS as $mediaKey) {
            if (str_contains($lower, $mediaKey)) {
                return true;
            }
        }

        return false;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}

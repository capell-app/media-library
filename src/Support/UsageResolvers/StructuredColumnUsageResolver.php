<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Support\UsageResolvers;

use Capell\Core\Support\Database\RuntimeSchemaState;
use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Contracts\MediaUsageReferenceResolver;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\Database\StructuredColumnTextCast;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
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

        foreach ($this->referenceColumns() as [$table, $column]) {
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
     * The sites that content referencing the media belongs to, derived row by row from the same structured columns the
     * usage check reads. A row with no resolvable site counts as unscoped.
     *
     * @return array{siteIds: list<int>, unscoped: bool}
     */
    public function siteUsage(CuratorMedia $media): array
    {
        $key = $media->getKey();
        $path = $media->getAttribute('path');

        if (! is_numeric($key) || (int) $key < 1) {
            return ['siteIds' => [], 'unscoped' => false];
        }

        $id = (int) $key;
        $candidates = [$id => is_string($path) ? $path : ''];
        $siteIds = [];
        $unscoped = false;
        $schema = resolve(RuntimeSchemaState::class);

        foreach ($this->referenceColumns() as [$table, $column]) {
            $extra = array_values(array_filter(
                ['id', 'site_id', 'translatable_type', 'translatable_id'],
                static fn (string $name): bool => $schema->hasColumn($table, $name),
            ));

            foreach ($this->scanRows($table, $column, $candidates, $extra) as [$row, $ids]) {
                if (! in_array($id, $ids, true)) {
                    continue;
                }

                $siteId = $this->siteOfRow($table, $row);

                $siteId === null ? $unscoped = true : $siteIds[$siteId] = $siteId;
            }
        }

        return ['siteIds' => array_values($siteIds), 'unscoped' => $unscoped];
    }

    /**
     * The Curator media one stored column value references, by the same rules the usage check applies.
     *
     * @return list<int>
     */
    public function mediaIdsIn(string $value): array
    {
        if ($value === '') {
            return [];
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

        $names = [];

        if (preg_match_all('~[^/\\\\"\'\s<>()=]+\.[a-z0-9]{2,5}\b~i', str_replace('\\/', '/', $value), $files) > 0) {
            foreach (array_slice(array_unique($files[0]), 0, self::PATH_PATTERNS_PER_QUERY) as $file) {
                $names[] = rawurldecode($file);
            }
        }

        if ($ids === [] && $names === []) {
            return [];
        }

        $model = new CuratorMedia;
        $candidates = [];
        $query = DB::table($model->getTable())->select(['id', 'path'])->where(function ($where) use ($ids, $names): void {
            if ($ids !== []) {
                $where->orWhereIn('id', array_keys($ids));
            }

            foreach ($names as $name) {
                $where->orWhere('path', $name)->orWhereRaw("path like ? escape '!'", ['%/' . $this->escapeLike($name)]);
            }
        });

        foreach ($query->cursor() as $row) {
            if (is_numeric($row->id ?? null)) {
                $candidates[(int) $row->id] = is_string($row->path ?? null) ? $row->path : '';
            }
        }

        return $candidates === [] ? [] : $this->referencedIn($value, $candidates);
    }

    /**
     * The configured structured columns that exist, as resolved table and column names.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function referenceColumns(): array
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
     * The site a stored row belongs to: its own site_id, the site itself, or its translatable owner's site.
     */
    public function siteOfRow(string $table, object $row): ?int
    {
        if (is_numeric($row->site_id ?? null)) {
            return (int) $row->site_id;
        }

        if ($table === TableName::resolve('sites') && is_numeric($row->id ?? null)) {
            return (int) $row->id;
        }

        $type = $row->translatable_type ?? null;
        $ownerId = $row->translatable_id ?? null;

        if (! is_string($type) || ! is_numeric($ownerId)) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        $owner = new $class;
        $ownerTable = $owner->getTable();

        if ($ownerTable === TableName::resolve('sites')) {
            return (int) $ownerId;
        }

        if (! resolve(RuntimeSchemaState::class)->hasColumn($ownerTable, 'site_id')) {
            return null;
        }

        $siteId = DB::table($ownerTable)->where($owner->getKeyName(), $ownerId)->value('site_id');

        return is_numeric($siteId) ? (int) $siteId : null;
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
        $referenced = [];

        foreach ($this->scanRows($table, $column, $candidates, []) as [, $ids]) {
            foreach ($ids as $mediaId) {
                $referenced[$mediaId] = $mediaId;
            }
        }

        return array_values($referenced);
    }

    /**
     * @param  array<int, string>  $candidates
     * @param  list<string>  $extraColumns
     * @return Generator<int, array{0: object, 1: list<int>}>
     */
    private function scanRows(string $table, string $column, array $candidates, array $extraColumns): Generator
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $wrapped = resolve(StructuredColumnTextCast::class)->expression($connection, $grammar->wrap($column));
        $pathChunks = array_chunk($this->searchableNames($candidates), self::PATH_PATTERNS_PER_QUERY);

        foreach ($pathChunks === [] ? [[]] : $pathChunks as $index => $names) {
            $patterns = $names;

            if ($index === 0) {
                $patterns = [...$patterns, ...self::MEDIA_KEYS, 'data-media-id', 'data-curator', 'data-id'];
            }

            $query = DB::table($table)->select([$column, ...$extraColumns])->whereNotNull($column);
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

                $ids = $this->referencedIn($value, $candidates);

                if ($ids !== []) {
                    yield [$row, $ids];
                }
            }
        }
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

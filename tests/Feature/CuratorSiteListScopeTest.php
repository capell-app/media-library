<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Filament\Resources\CuratorMediaResource;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Support\CuratorMediaListContext;
use Capell\MediaLibrary\Tests\Fixtures\SiteScopedMediaUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function (): void {
    Cache::flush();
});

/**
 * @return list<string>
 */
function listScopePickerIds(mixed $files): array
{
    $ids = [];

    foreach (is_array($files) ? $files : [] as $file) {
        $id = is_array($file) ? ($file['id'] ?? null) : null;

        if (is_int($id) || is_string($id)) {
            $ids[] = (string) $id;
        }
    }

    sort($ids);

    return $ids;
}

function listScopeKey(CuratorMedia $media): int|string
{
    $key = $media->getKey();

    return is_int($key) || is_string($key) ? $key : throw new RuntimeException('Expected a media key.');
}

/**
 * @param  list<int|null>  $usedBySites
 */
function listScopeMedia(string $name, array $usedBySites = []): CuratorMedia
{
    $id = DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => null, 'visibility' => 'public', 'name' => $name,
        'path' => $name . '.jpg', 'width' => 8, 'height' => 8, 'size' => 10, 'type' => 'image/jpeg',
        'ext' => 'jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);

    foreach ($usedBySites as $siteId) {
        DB::table(TableName::resolve('content_graph_edges'))->insert([
            'source_type' => 'page', 'source_id' => random_int(1, 1_000_000), 'target_type' => CuratorMedia::class, 'target_id' => $id,
            'kind' => 'uses_media', 'strength' => 'weak', 'source_package' => 'test', 'site_id' => $siteId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return CuratorMedia::query()->findOrFail($id);
}

it('lists only unused media and media used on the editor\'s own sites in the library resource', function (): void {
    listScopeMedia('site-a-only', [1]);
    listScopeMedia('site-b-only', [2]);
    listScopeMedia('shared', [1, 2]);
    listScopeMedia('unused');
    listScopeMedia('siteless', [null]);
    $this->actingAs(new SiteScopedMediaUser([1]));

    $names = CuratorMediaResource::getEloquentQuery()->orderBy('name')->pluck('name')->all();

    expect($names)->toEqualCanonicalizing(['shared', 'siteless', 'site-a-only', 'unused']);
});

it('lists every item for a global administrator and nothing used on another site for an editor with no sites', function (): void {
    listScopeMedia('site-a-only', [1]);
    listScopeMedia('unused');

    $this->actingAs(new SiteScopedMediaUser([], true));
    expect(CuratorMediaResource::getEloquentQuery()->count())->toBe(2);

    $this->actingAs(new SiteScopedMediaUser([]));
    expect(CuratorMediaResource::getEloquentQuery()->pluck('name')->all())->toBe(['unused']);
});

it('restricts the picker list and search to the editor\'s sites, and nothing else', function (): void {
    $own = listScopeMedia('own-hero', [1]);
    listScopeMedia('other-hero', [2]);
    $shared = listScopeMedia('shared-hero', [1, 2]);
    $this->actingAs(new SiteScopedMediaUser([1]));

    $panel = Livewire::test('curator-panel', ['settings' => ['diskName' => 'public']]);
    $listed = listScopePickerIds($panel->get('files'));

    $panel->set('search', 'hero');
    $searched = listScopePickerIds($panel->get('files'));

    $expected = [(string) listScopeKey($own), (string) listScopeKey($shared)];
    sort($expected);

    expect($listed)->toBe($expected)->and($searched)->toBe($expected);
});

it('keeps resolving media shared with another site outside list queries', function (): void {
    $other = listScopeMedia('other-hero', [2]);
    $this->actingAs(new SiteScopedMediaUser([1]));

    expect(CuratorMedia::query()->whereKey($other->getKey())->exists())->toBeTrue()
        ->and(CuratorMedia::query()->count())->toBe(1)
        ->and(resolve(CuratorMediaListContext::class)->active())->toBeFalse();
});

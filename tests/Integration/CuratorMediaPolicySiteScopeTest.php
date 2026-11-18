<?php

declare(strict_types=1);

use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Policies\CuratorMediaPolicy;
use Capell\MediaLibrary\Tests\Fixtures\SiteScopedMediaUser;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
});

function siteScopeMedia(string $name): CuratorMedia
{
    $id = DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public', 'name' => $name,
        'path' => 'media/' . $name . '.jpg', 'width' => 8, 'height' => 8, 'size' => 10, 'type' => 'image/jpeg',
        'ext' => 'jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return CuratorMedia::query()->findOrFail($id);
}

function siteScopeUsedBy(CuratorMedia $media, ?int $siteId): void
{
    DB::table(TableName::resolve('content_graph_edges'))->insert([
        'source_type' => 'page', 'source_id' => random_int(1, 1_000_000), 'target_type' => CuratorMedia::class, 'target_id' => $media->getKey(),
        'kind' => 'uses_media', 'strength' => 'weak', 'source_package' => 'test', 'site_id' => $siteId,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/**
 * @return array<string, bool>
 */
function siteScopeVerdicts(SiteScopedMediaUser $actor, CuratorMedia $media): array
{
    $policy = new CuratorMediaPolicy;

    return [
        'view' => $policy->view($actor, $media),
        'update' => $policy->update($actor, $media),
        'delete' => $policy->delete($actor, $media),
        'restore' => $policy->restore($actor, $media),
        'forceDelete' => $policy->forceDelete($actor, $media),
    ];
}

it('denies an editor on site A every action on media used only by site B', function (): void {
    $media = siteScopeMedia('site-b-only');
    siteScopeUsedBy($media, 2);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $media))->each->toBeFalse();
});

it('allows an editor on site A to act on media used by site A, or by both sites', function (): void {
    $own = siteScopeMedia('site-a');
    siteScopeUsedBy($own, 1);
    $shared = siteScopeMedia('both');
    siteScopeUsedBy($shared, 1);
    siteScopeUsedBy($shared, 2);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $own))->each->toBeTrue()
        ->and(siteScopeVerdicts(new SiteScopedMediaUser([1]), $shared))->each->toBeTrue();
});

it('keeps unused media and media used only by site-less content available, but site-less usage never opens another site\'s media', function (): void {
    $unused = siteScopeMedia('unused');
    $siteless = siteScopeMedia('siteless');
    siteScopeUsedBy($siteless, null);
    $mixed = siteScopeMedia('siteless-and-b');
    siteScopeUsedBy($mixed, null);
    siteScopeUsedBy($mixed, 2);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $unused))->each->toBeTrue()
        ->and(siteScopeVerdicts(new SiteScopedMediaUser([1]), $siteless))->each->toBeTrue()
        ->and(siteScopeVerdicts(new SiteScopedMediaUser([1]), $mixed))->each->toBeFalse();
});

it('denies media referenced only from another site\'s layout JSON, and allows it on that site', function (): void {
    $media = siteScopeMedia('layout-json');
    DB::table(TableName::resolve('layouts'))->insert([
        'name' => 'B layout', 'key' => 'b-layout', 'site_id' => 2,
        'elements' => json_encode([['widget' => 'hero', 'settings' => ['backgroundImageId' => $media->getKey()]]]),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $media))->each->toBeFalse()
        ->and(siteScopeVerdicts(new SiteScopedMediaUser([2]), $media))->each->toBeTrue();
});

it('ignores edges that target Core media ids rather than Curator ids', function (): void {
    $media = siteScopeMedia('core-edge');
    DB::table(TableName::resolve('content_graph_edges'))->insert([
        'source_type' => 'page', 'source_id' => 5, 'target_type' => 'media', 'target_id' => $media->getKey(),
        'kind' => 'uses_media', 'strength' => 'weak', 'source_package' => 'test', 'site_id' => 2,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $media))->each->toBeTrue();
});

it('denies media used only by an owner row on another site (owner foreign key)', function (): void {
    $media = siteScopeMedia('owner-fk');
    DB::table(TableName::resolve('page_property_values'))->insert([
        'site_id' => 2, 'page_id' => 1, 'property_definition_id' => 1, 'media_id' => $media->getKey(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([1]), $media))->each->toBeFalse()
        ->and(siteScopeVerdicts(new SiteScopedMediaUser([2]), $media))->each->toBeTrue();
});

it('lets a global administrator act on any media', function (): void {
    $media = siteScopeMedia('global-admin');
    siteScopeUsedBy($media, 2);

    expect(siteScopeVerdicts(new SiteScopedMediaUser([], global: true), $media))->each->toBeTrue();
});

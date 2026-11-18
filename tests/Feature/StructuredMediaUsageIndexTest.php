<?php

declare(strict_types=1);

use Capell\Core\Models\Layout;
use Capell\Core\Models\Page;
use Capell\Core\Models\Site;
use Capell\Core\Support\TableName;
use Capell\MediaLibrary\Actions\Usage\SyncStructuredMediaUsageAction;
use Capell\MediaLibrary\Filament\Resources\CuratorMediaResource;
use Capell\MediaLibrary\Models\CuratorMedia;
use Capell\MediaLibrary\Tests\Fixtures\SiteScopedMediaUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    Cache::flush();
});

function structuredUsageMedia(string $name): CuratorMedia
{
    $id = DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public', 'name' => $name,
        'path' => 'media/' . $name . '.jpg', 'width' => 8, 'height' => 8, 'size' => 10, 'type' => 'image/jpeg',
        'ext' => 'jpg', 'created_at' => now(), 'updated_at' => now(),
    ]);

    return CuratorMedia::query()->findOrFail($id);
}

/** @return list<string> */
function structuredUsageListedNames(): array
{
    return array_values(array_map(strval(...), array_filter(
        CuratorMediaResource::getEloquentQuery()->orderBy('name')->pluck('name')->all(),
        is_string(...),
    )));
}

it('hides media referenced only from another site\'s layout JSON from the picker list', function (): void {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $byId = structuredUsageMedia('layout-hero');
    $byPath = structuredUsageMedia('layout-banner');
    structuredUsageMedia('unused');
    $layout = Layout::factory()->site($siteB)->create();
    $layout->forceFill(['containers' => [['widgets' => [['data' => ['image' => $byId->getKey(), 'background' => '/storage/media/layout-banner.jpg']]]]]])->save();

    $this->actingAs(new SiteScopedMediaUser([$siteA->getKey()]));
    expect(structuredUsageListedNames())->toBe(['unused']);

    $this->actingAs(new SiteScopedMediaUser([$siteB->getKey()]));
    expect(structuredUsageListedNames())->toBe(['layout-banner', 'layout-hero', 'unused']);

    Auth::forgetUser();
    $layout->forceFill(['containers' => []])->save();
    $this->actingAs(new SiteScopedMediaUser([$siteA->getKey()]));
    expect(structuredUsageListedNames())->toBe(['layout-banner', 'layout-hero', 'unused'])
        ->and($byPath->exists)->toBeTrue();
});

it('indexes block content written without model events when the index is rebuilt', function (): void {
    $siteA = Site::factory()->create();
    $siteB = Site::factory()->create();
    $media = structuredUsageMedia('block-logo');
    $page = Page::factory()->site($siteB)->create();
    $translationId = DB::table(TableName::resolve('translations'))->insertGetId([
        'language_id' => $siteB->language_id, 'translatable_type' => $page->getMorphClass(), 'translatable_id' => $page->getKey(),
        'title' => 'Blocks', 'content' => json_encode([['type' => 'logos', 'data' => ['logo' => ['id' => $media->getKey()]]]], JSON_THROW_ON_ERROR),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->actingAs(new SiteScopedMediaUser([$siteA->getKey()]));

    expect(structuredUsageListedNames())->toBe(['block-logo']);

    $this->artisan('capell:media-rebuild-structured-usage')->assertSuccessful();

    expect(structuredUsageListedNames())->toBe([])
        ->and(DB::table(SyncStructuredMediaUsageAction::TABLE)->where('source_id', $translationId)->value('site_id'))->toBe($siteB->getKey());

    DB::table(TableName::resolve('translations'))->where('id', $translationId)->delete();
    $this->artisan('capell:media-rebuild-structured-usage')->assertSuccessful();

    expect(structuredUsageListedNames())->toBe(['block-logo']);
});

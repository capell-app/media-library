<?php

declare(strict_types=1);

use Capell\MediaLibrary\Actions\DashboardReports\BuildDuplicateMediaQueryAction;
use Capell\MediaLibrary\Actions\DashboardReports\DiscoverOwnerForeignKeysAction;
use Capell\MediaLibrary\Jobs\CalculateMediaChecksumJob;
use Capell\MediaLibrary\Models\CuratorMedia;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function checksumMedia(string $name, string $contents): int
{
    Storage::disk('public')->put('media/' . $name . '.txt', $contents);

    return DB::table('curator')->insertGetId([
        'disk' => 'public', 'directory' => 'media', 'visibility' => 'public', 'name' => $name,
        'path' => 'media/' . $name . '.txt', 'size' => strlen($contents), 'type' => 'text/plain',
        'ext' => 'txt', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

test('the duplicates report groups on the stored checksum without reading any blob', function (): void {
    Cache::flush();
    $first = checksumMedia('first', 'same bytes');
    $second = checksumMedia('second', 'same bytes');
    $unique = checksumMedia('unique', 'other bytes');

    foreach ([$first, $second, $unique] as $id) {
        CalculateMediaChecksumJob::dispatchSync($id);
    }

    // Deleting the blobs proves the report no longer streams files.
    Storage::disk('public')->deleteDirectory('media');

    $rows = BuildDuplicateMediaQueryAction::run(useCache: false)->orderBy('curator.id')->get();

    expect($rows->pluck('id')->all())->toBe([$first, $second])
        ->and($rows->pluck('duplicate_count')->unique()->all())->toBe([2])
        ->and($rows->first()?->getAttribute('duplicate_hash'))->toBe(hash('sha256', 'same bytes'));
});

test('the checksum job streams the blob and retries with backoff', function (): void {
    $job = new CalculateMediaChecksumJob(1);

    expect($job->tries)->toBe(3)
        ->and($job->backoff())->toBe([10, 60, 300]);
});

test('the backfill command fills only media that has no checksum', function (): void {
    Queue::fake();
    $missing = checksumMedia('missing', 'abc');
    $done = checksumMedia('done', 'def');
    DB::table('curator')->where('id', $done)->update(['capell_checksum_sha256' => hash('sha256', 'def')]);

    Artisan::call('capell:media-backfill-checksums');

    Queue::assertPushed(CalculateMediaChecksumJob::class, 1);
    Queue::assertPushed(CalculateMediaChecksumJob::class, fn (CalculateMediaChecksumJob $job): bool => $job->mediaId === $missing);

});

test('the backfill command can calculate checksums in the same process', function (): void {
    $missing = checksumMedia('sync-missing', 'abc');

    Artisan::call('capell:media-backfill-checksums', ['--sync' => true]);

    expect(CuratorMedia::query()->whereKey($missing)->value('capell_checksum_sha256'))->toBe(hash('sha256', 'abc'));
});

test('owner foreign key discovery reads the schema once until a migration flushes it', function (): void {
    config()->set('capell.media_library.auto_discover_owner_foreign_keys', true);
    DiscoverOwnerForeignKeysAction::flush();

    $first = DiscoverOwnerForeignKeysAction::run();
    expect($first)->toContain(['table' => 'test_curator_owners', 'column' => 'image_id']);

    DB::enableQueryLog();
    DB::flushQueryLog();
    $second = DiscoverOwnerForeignKeysAction::run(force: true);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second)->toBe($first)
        ->and($queries)->toBe([]);

    event(new MigrationsEnded('up'));
    DB::enableQueryLog();
    DiscoverOwnerForeignKeysAction::run();
    $afterMigration = DB::getQueryLog();
    DB::disableQueryLog();

    expect($afterMigration)->not->toBe([]);
});

test('the quarantine purge runs on the daily schedule', function (): void {
    $commands = collect(resolve(Schedule::class)->events())->pluck('command')->implode("\n");

    expect($commands)->toContain('capell:media-purge-quarantined');
});

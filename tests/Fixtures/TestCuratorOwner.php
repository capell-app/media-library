<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Tests\Fixtures;

use Capell\Core\Contracts\Media\HasMediaContract;
use Capell\MediaLibrary\Concerns\InteractsWithCuratorMedia;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Minimal owner fixture model used by the Curator backend test suite.
 *
 * Uses the `InteractsWithCuratorMedia` trait for single-FK media behaviour.
 * The `image` collection maps to the `image_id` column on this model's table,
 * as defined in the TestCase's `defineDatabaseMigrations()`.
 *
 * @property int|null $image_id
 * @property int|null $thumbnail_id
 */
class TestCuratorOwner extends Model implements HasMediaContract
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    use InteractsWithCuratorMedia;

    /** @var string|null */
    protected $table = 'test_curator_owners';

    /** @var list<string> */
    protected $fillable = ['name', 'image_id', 'thumbnail_id'];
}

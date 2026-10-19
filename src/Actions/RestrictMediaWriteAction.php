<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Closure;

/** Flysystem applies visibility after writing; restrict creation before any bytes. */
final class RestrictMediaWriteAction
{
    /**
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    public function handle(Closure $write): mixed
    {
        $previous = umask(0077);
        try {
            return $write();
        } finally {
            umask($previous);
        }
    }
}

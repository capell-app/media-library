<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use Capell\Core\Exceptions\UnsafeSvgUploadException;
use Capell\Core\Support\Security\SvgUploadSanitizer;
use Lorisleiva\Actions\Concerns\AsFake;
use Lorisleiva\Actions\Concerns\AsObject;

/**
 * Sanitises uploaded SVG markup with Core's SVG upload policy.
 *
 * Stored SVGs should still be served defensively (Content-Disposition: attachment
 * or a sandboxed CSP) by the host application, which owns the serving route.
 *
 * @method static string run(string $contents)
 */
final class SanitizeSvgUploadAction
{
    use AsFake;
    use AsObject;

    /** @throws UnsafeSvgUploadException when the upload cannot be made safe */
    public function handle(string $contents): string
    {
        return resolve(SvgUploadSanitizer::class)->sanitize($contents);
    }
}

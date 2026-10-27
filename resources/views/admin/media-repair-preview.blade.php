@if ($preview->url !== null)
    <figure class="space-y-2">
        <img
            src="{{ $preview->url }}"
            alt="{{ __('capell-media-library::package.media_health.repair_preview_image') }}"
            class="max-h-80 w-auto max-w-full rounded-lg object-contain"
        />
        <figcaption>
            <a
                href="{{ $preview->url }}"
                target="_blank"
                rel="noopener noreferrer"
                class="text-primary-600 underline focus-visible:outline focus-visible:outline-2"
            >
                {{ __('capell-media-library::package.media_health.repair_preview_full_size') }}
            </a>
        </figcaption>
    </figure>
@else
    <p role="status">{{ __('capell-media-library::package.media_health.repair_preview_unavailable') }}</p>
@endif

<?php

declare(strict_types=1);

namespace Capell\MediaLibrary\Actions;

use DOMAttr;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Lorisleiva\Actions\Concerns\AsObject;
use RuntimeException;
use Throwable;

/**
 * Defensive, dependency-free SVG sanitizer for uploaded markup.
 *
 * This is a hardening layer, not a full XML security suite. For
 * defence-in-depth a vetted library such as enshrined/svg-sanitize is
 * recommended in addition to this action; it is implemented inline here to
 * avoid adding a runtime dependency to the package. Stored SVGs should also be
 * served defensively (Content-Disposition: attachment and/or a sandboxed CSP)
 * by the host application — Curator/the host controls the serving route, so
 * that header policy is out of this package's scope.
 *
 * @method static string run(string $contents)
 */
final class SanitizeSvgUploadAction
{
    use AsObject;

    /**
     * Elements whose href/xlink:href may pull in external or data-URI
     * documents (SVG-in-SVG / external-use XSS vectors). For these we only
     * permit same-document fragment references (e.g. "#icon").
     *
     * @var list<string>
     */
    private const array REFERENCE_ELEMENTS = [
        'use',
        'image',
    ];

    /** @var list<string> */
    private const array DANGEROUS_TAGS = [
        'script',
        'foreignObject',
        'iframe',
        'object',
        'embed',
        'handler',
        'style',
        'animate',
        'animateMotion',
        'animateTransform',
        'set',
    ];

    public function handle(string $contents): string
    {
        throw_if($this->containsDoctype($contents), RuntimeException::class, 'SVG uploads may not contain a DOCTYPE or ENTITY declaration.');

        $document = new DOMDocument('1.0', 'UTF-8');
        $document->preserveWhiteSpace = false;

        $previousErrorState = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn (?string $publicId, ?string $systemId, array $context): ?string => null);

        try {
            $loaded = $document->loadXML($contents, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable $throwable) {
            throw new RuntimeException('SVG upload could not be parsed safely.', 0, $throwable);
        } finally {
            libxml_set_external_entity_loader(null);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorState);
        }

        throw_unless($loaded, RuntimeException::class, 'SVG upload could not be parsed safely.');

        $root = $document->documentElement;
        throw_unless($root instanceof DOMElement && $root->localName === 'svg', RuntimeException::class, 'SVG upload must contain an SVG root element.');

        $this->sanitize($document, $root);

        $sanitized = $document->saveXML($root);

        return is_string($sanitized) ? $sanitized : '';
    }

    private function containsDoctype(string $contents): bool
    {
        $prologueWindow = substr($contents, 0, 4096);

        return stripos($prologueWindow, '<!DOCTYPE') !== false
            || stripos($prologueWindow, '<!ENTITY') !== false;
    }

    private function sanitize(DOMDocument $document, DOMElement $root): void
    {
        $xpath = new DOMXPath($document);
        $dangerousSelector = implode('|', array_map(
            static fn (string $tag): string => '//*[local-name()="' . $tag . '"]',
            self::DANGEROUS_TAGS,
        ));

        $matches = $xpath->query($dangerousSelector);
        if ($matches !== false) {
            foreach (iterator_to_array($matches) as $node) {
                if ($node instanceof DOMNode) {
                    $node->parentNode?->removeChild($node);
                }
            }
        }

        $this->stripDangerousAttributes($root);
    }

    private function stripDangerousAttributes(DOMElement $element): void
    {
        /** @var list<DOMAttr> $attributes */
        $attributes = [];
        foreach ($element->attributes ?? [] as $attribute) {
            if ($attribute instanceof DOMAttr) {
                $attributes[] = $attribute;
            }
        }

        $elementName = strtolower($element->localName);
        $isReferenceElement = in_array($elementName, self::REFERENCE_ELEMENTS, true);

        foreach ($attributes as $attribute) {
            $name = strtolower($attribute->name);
            $value = $attribute->value;

            if (str_starts_with($name, 'on')) {
                $element->removeAttributeNode($attribute);

                continue;
            }

            if ($name === 'href' || $name === 'xlink:href') {
                // <use>/<image> may only reference same-document fragments;
                // any external or data-URI document reference is stripped to
                // close SVG-in-SVG and external-use injection vectors.
                $unsafe = $isReferenceElement
                    ? ! $this->isSameDocumentFragment($value)
                    : $this->isUnsafeReferenceUrl($value);

                if ($unsafe) {
                    $element->removeAttributeNode($attribute);
                }

                continue;
            }

            if ($name === 'style' && $this->containsUnsafeCss($value)) {
                $element->removeAttributeNode($attribute);
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $this->stripDangerousAttributes($child);
            }
        }
    }

    private function containsUnsafeCss(string $css): bool
    {
        $normalized = strtolower($css);

        if (str_contains($normalized, '@import')
            || str_contains($normalized, 'expression(')
            || str_contains($normalized, '-moz-binding')) {
            return true;
        }

        preg_match_all('/url\(\s*([^)]+?)\s*\)/i', $css, $matches);

        foreach ($matches[1] as $rawUrl) {
            $url = trim($rawUrl, " \t\n\r\0\x0B'\"");

            if ($this->isUnsafeReferenceUrl($url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True only for same-document fragment references like "#icon". Empty,
     * external (http/data/etc.), and cross-document fragment ("file.svg#x")
     * references are rejected for <use>/<image>.
     */
    private function isSameDocumentFragment(string $url): bool
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === '' || ! str_starts_with($normalized, '#')) {
            return false;
        }

        return preg_match('/^#[A-Za-z0-9._:-]+$/', $normalized) === 1;
    }

    private function isUnsafeReferenceUrl(string $url): bool
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === '') {
            return true;
        }

        if (str_starts_with($normalized, 'javascript:')
            || str_starts_with($normalized, 'data:')
            || str_starts_with($normalized, 'vbscript:')) {
            return true;
        }

        if (str_starts_with($normalized, 'http://')
            || str_starts_with($normalized, 'https://')
            || str_starts_with($normalized, '//')) {
            return true;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1) {
            return true;
        }

        return preg_match('/^[A-Za-z0-9._~\/#?=&:%-]+$/', $url) !== 1;
    }

    private function normalizeUrl(string $url): string
    {
        $decoded = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strtolower((string) preg_replace('/[\x00-\x20]+/', '', $decoded));
    }
}

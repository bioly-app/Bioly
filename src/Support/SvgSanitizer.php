<?php

declare(strict_types=1);

namespace Bioly\Support;

use DOMDocument;
use DOMElement;

/**
 * Cleans an uploaded SVG with an allow list: only drawing elements and
 * presentation attributes survive. No scripts, styles, events, external
 * references or embedded content. The result is a new, minimal SVG document.
 */
final class SvgSanitizer
{
    private const NS = 'http://www.w3.org/2000/svg';

    private const ELEMENTS = [
        'svg', 'g', 'path', 'circle', 'ellipse', 'rect', 'line', 'polyline', 'polygon',
        'defs', 'linearGradient', 'radialGradient', 'stop', 'clipPath', 'mask', 'title',
    ];

    private const ATTRIBUTES = [
        'viewBox', 'width', 'height', 'preserveAspectRatio', 'id',
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'clip-path', 'mask', 'opacity',
        'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit',
        'stroke-dasharray', 'stroke-dashoffset', 'stroke-opacity',
        'transform', 'd', 'cx', 'cy', 'r', 'rx', 'ry', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'fx', 'fy', 'points',
        'offset', 'stop-color', 'stop-opacity', 'gradientUnits', 'gradientTransform', 'spreadMethod',
        'clipPathUnits', 'maskUnits', 'maskContentUnits',
    ];

    /** @return string|null clean SVG, or null if the file is not a usable SVG */
    public static function sanitize(string $svg): ?string
    {
        // Entities and DTDs are never needed for an icon and are a classic attack path.
        if ($svg === '' || preg_match('/<!DOCTYPE|<!ENTITY/i', $svg) === 1) {
            return null;
        }

        $source = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $source->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $source->documentElement;

        if (!$loaded || $root === null || $root->localName !== 'svg' || $root->namespaceURI !== self::NS) {
            return null;
        }

        $target = new DOMDocument('1.0', 'UTF-8');
        $clean = self::copy($root, $target);

        if ($clean === null || (!$clean->hasAttribute('viewBox') && !($clean->hasAttribute('width') && $clean->hasAttribute('height')))) {
            return null;
        }

        $target->appendChild($clean);
        self::scopeIds($target, 's' . bin2hex(random_bytes(4)) . '-');

        return (string) $target->saveXML($target->documentElement);
    }

    /**
     * Several SVGs end up inline on one page. Gives every id (and every url(#id) pointing
     * to it) a prefix of its own, so two icons with a gradient "a" never mix up.
     */
    private static function scopeIds(DOMDocument $document, string $prefix): void
    {
        $elements = $document->getElementsByTagNameNS(self::NS, '*');
        $ids = [];

        foreach ($elements as $element) {
            if ($element->hasAttribute('id')) {
                $ids[$element->getAttribute('id')] = true;
                $element->setAttribute('id', $prefix . $element->getAttribute('id'));
            }
        }

        if ($ids === []) {
            return;
        }

        foreach ($elements as $element) {
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                if (str_contains($attribute->value, 'url(')) {
                    $element->setAttribute($attribute->name, (string) preg_replace_callback(
                        '/url\(\s*([\'"]?)#([\w.-]+)\1\s*\)/',
                        static fn(array $m): string => isset($ids[$m[2]]) ? 'url(#' . $prefix . $m[2] . ')' : $m[0],
                        $attribute->value,
                    ));
                }
            }
        }
    }

    private static function copy(DOMElement $element, DOMDocument $target): ?DOMElement
    {
        if ($element->namespaceURI !== self::NS || !in_array($element->localName, self::ELEMENTS, true)) {
            return null;
        }

        $copy = $target->createElementNS(self::NS, $element->localName);

        foreach ($element->attributes ?? [] as $attribute) {
            // Namespaced attributes (xlink:href and friends) are dropped on purpose.
            if ($attribute->namespaceURI !== null || !in_array($attribute->localName, self::ATTRIBUTES, true)) {
                continue;
            }

            $value = trim($attribute->value);

            if (self::safeValue($value)) {
                $copy->setAttribute($attribute->localName, $value);
            }
        }

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $clean = self::copy($child, $target);

                if ($clean !== null) {
                    $copy->appendChild($clean);
                }
            } elseif ($element->localName === 'title' && $child->nodeType === XML_TEXT_NODE) {
                $copy->appendChild($target->createTextNode((string) $child->nodeValue));
            }
        }

        return $copy;
    }

    private static function safeValue(string $value): bool
    {
        if (preg_match('/javascript:|data:|expression\s*\(|@import|<|>/i', $value) === 1) {
            return false;
        }

        // url() may only point to an element inside the same file: url(#gradient)
        if (preg_match_all('/url\s*\(([^)]*)\)/i', $value, $matches) > 0) {
            foreach ($matches[1] as $target) {
                if (preg_match('/^\s*[\'"]?#[A-Za-z][\w.-]*[\'"]?\s*$/', $target) !== 1) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Turns a clean SVG into a one-color icon: every color becomes currentColor, so CSS
     * (and the icon style) decides the color. "none" and gradient references stay.
     */
    public static function monochrome(string $svg): string
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($svg, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;

        if (!$loaded || $root === null) {
            return $svg;
        }

        foreach ($document->getElementsByTagName('*') as $element) {
            foreach (['fill', 'stroke', 'stop-color'] as $name) {
                $value = strtolower(trim($element->getAttribute($name)));

                if ($value !== '' && $value !== 'none' && !str_starts_with($value, 'url(')) {
                    $element->setAttribute($name, 'currentColor');
                }
            }
        }

        // Shapes without a fill are black by default; make them follow the color too.
        if (!$root->hasAttribute('fill')) {
            $root->setAttribute('fill', 'currentColor');
        }

        return (string) $document->saveXML($root);
    }
}

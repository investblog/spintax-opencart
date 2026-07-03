<?php
/**
 * Allow-list HTML purifier — the OpenCart replacement for WP `wp_kses_post()`
 * (spec §9.4). This is the ONLY output sanitizer in the pipeline, applied as the
 * terminal stage on every `description_column` / `eav_attribute` write.
 *
 * Everything not on the allow-list is stripped: disallowed tags are unwrapped
 * (their safe text/children survive), the dangerous script/layout tags are
 * removed with their content, and disallowed attributes are dropped.
 *
 * @package Spintax\Shim
 */

declare(strict_types=1);

namespace Spintax\Shim;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlSanitizer implements HtmlSanitizerInterface
{
    /**
     * Allowed tags => allowed attributes (spec §9.4 table). `h1` is excluded on
     * purpose (the storefront theme owns the page heading).
     *
     * @var array<string, string[]>
     */
    private const ALLOWED = array(
        'p' => array('class', 'style'),
        'br' => array(),
        'strong' => array(), 'b' => array(), 'em' => array(), 'i' => array(), 'u' => array(), 's' => array(),
        'ul' => array(), 'ol' => array(), 'li' => array(),
        'a' => array('href', 'title', 'rel', 'target'),
        'h2' => array(), 'h3' => array(), 'h4' => array(),
        'span' => array('class', 'style'),
        'blockquote' => array(),
        'table' => array('colspan', 'rowspan'), 'thead' => array('colspan', 'rowspan'),
        'tbody' => array('colspan', 'rowspan'), 'tr' => array('colspan', 'rowspan'),
        'th' => array('colspan', 'rowspan'), 'td' => array('colspan', 'rowspan'),
        'img' => array('src', 'alt', 'title', 'width', 'height'),
    );

    /** Tags removed WITH their content — always rejected regardless of context. */
    private const REMOVE_WITH_CONTENT = array('script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'svg');

    /** The only CSS properties allowed inside a surviving `style` attribute. */
    private const STYLE_PROPS = array('text-align', 'font-weight', 'font-style', 'color', 'text-decoration');

    /** Attributes whose value is a URL and must pass the scheme allow-list. */
    private const URL_ATTRS = array('href' => true, 'src' => true);

    private bool $allow_style;

    /**
     * @param bool $allow_style When false, `style` attributes are dropped entirely
     *                          (for stores that forbid inline CSS — spec §9.4 rule 3).
     */
    public function __construct(bool $allow_style = true)
    {
        $this->allow_style = $allow_style;
    }

    public function filter(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // The meta charset keeps UTF-8 intact; the wrapper gives us a stable root.
        $doc->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8"><body>' . $html . '</body>',
            LIBXML_HTML_NODEFDTD | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $body = $doc->getElementsByTagName('body')->item(0);
        if (null === $body) {
            return '';
        }

        $this->clean($body);

        // Serialise inner HTML of body.
        $out = '';
        foreach (iterator_to_array($body->childNodes) as $child) {
            $out .= $doc->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * Depth-first clean of an element's children (children first, then decide
     * keep / unwrap / remove for each element).
     */
    private function clean(DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMComment) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue; // text nodes survive verbatim
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::REMOVE_WITH_CONTENT, true)) {
                $node->removeChild($child);
                continue;
            }

            // Sanitise the subtree first.
            $this->clean($child);

            if (!isset(self::ALLOWED[$tag])) {
                // Disallowed but non-dangerous tag: unwrap — promote its (already
                // cleaned) children in place, then drop the wrapper.
                while (null !== $child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                $node->removeChild($child);
                continue;
            }

            $this->filterAttributes($child, $tag);
        }
    }

    private function filterAttributes(DOMElement $el, string $tag): void
    {
        $allowed = self::ALLOWED[$tag];

        foreach (iterator_to_array($el->attributes) as $attr) {
            $name = strtolower($attr->nodeName);

            // Always reject id and any on* event handler.
            if ('id' === $name || 0 === strpos($name, 'on') || !in_array($name, $allowed, true)) {
                $el->removeAttribute($attr->nodeName);
                continue;
            }

            if (isset(self::URL_ATTRS[$name])) {
                if (!self::is_safe_url($attr->nodeValue)) {
                    $el->removeAttribute($attr->nodeName);
                }
                continue;
            }

            if ('style' === $name) {
                if (!$this->allow_style) {
                    $el->removeAttribute($attr->nodeName);
                    continue;
                }
                $clean = self::filter_style($attr->nodeValue);
                if ('' === $clean) {
                    $el->removeAttribute($attr->nodeName);
                } else {
                    $el->setAttribute('style', $clean);
                }
            }
        }
    }

    /**
     * Scheme allow-list (spec §9.4 rule 1): ONLY `http`, `https`, `mailto`, and
     * root-relative (`/…`) URLs survive. Everything else is stripped — including
     * protocol-relative `//host` (resolves to an external origin), bare-relative
     * paths, and `#anchor`. The allow-list is intentionally strict; a store that
     * needs more can extend the shim (§9.4 rule 5).
     */
    private static function is_safe_url(string $url): bool
    {
        $u = trim($url);
        if ('' === $u) {
            return false;
        }
        // Protocol-relative (`//host/…`) — an external origin in disguise. Reject
        // BEFORE the root-relative check, since it also starts with '/'.
        if (0 === strncmp($u, '//', 2)) {
            return false;
        }
        // Root-relative — allowed.
        if ('/' === $u[0]) {
            return true;
        }
        // Explicit scheme — only http / https / mailto.
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $u, $m)) {
            return in_array(strtolower($m[1]), array('http', 'https', 'mailto'), true);
        }
        // Schemeless and not root-relative (bare-relative, `#anchor`) — rejected
        // per the spec's enumerated survivor list.
        return false;
    }

    /**
     * Keep only the whitelisted CSS properties with safe values.
     */
    private static function filter_style(string $style): string
    {
        $out = array();
        foreach (explode(';', $style) as $decl) {
            if ('' === trim($decl)) {
                continue;
            }
            $parts = explode(':', $decl, 2);
            if (2 !== count($parts)) {
                continue;
            }
            $prop = strtolower(trim($parts[0]));
            $val = trim($parts[1]);
            if ('' === $val) {
                continue;
            }
            if (!in_array($prop, self::STYLE_PROPS, true)) {
                continue;
            }
            if (preg_match('/(expression|url\s*\(|javascript:)/i', $val)) {
                continue;
            }
            $out[] = $prop . ': ' . $val;
        }
        return implode('; ', $out);
    }
}

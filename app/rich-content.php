<?php
declare(strict_types=1);

/**
 * Sanitize rich article HTML before storage and again before public rendering.
 * Only a small formatting/content allowlist is retained; scripts, embeds,
 * event handlers and untrusted image URLs are removed.
 */
function sanitize_rich_content(string $html): string {
    if (!class_exists(DOMDocument::class)) {
        return nl2br(htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    }
    $allowed = ['p','br','h2','h3','h4','h5','blockquote','ul','ol','li','strong','b','em','i','u','s','del','a','img','figure','figcaption','hr','table','thead','tbody','tr','th','td','div','span','sub','sup'];
    $drop = ['script','style','iframe','object','embed','form','input','button','textarea','select','option','svg','math','video','audio','source','link','meta'];
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8" ?><div id="rich-article-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($dom);
    $root = $xpath->query('//*[@id="rich-article-root"]')->item(0);
    if (!$root) return '';
    $clean = static function(DOMNode $parent) use (&$clean, $allowed, $drop): void {
        $children = [];
        foreach ($parent->childNodes as $child) $children[] = $child;
        foreach ($children as $node) {
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (in_array($tag, $drop, true)) {
                    $node->parentNode?->removeChild($node);
                    continue;
                }
                if (!in_array($tag, $allowed, true)) {
                    $clean($node);
                    $parentNode = $node->parentNode;
                    if ($parentNode) {
                        while ($node->firstChild) $parentNode->insertBefore($node->firstChild, $node);
                        $parentNode->removeChild($node);
                    }
                    continue;
                }
                $perTag = [
                    'a'=>['href','title','target','rel'],
                    'img'=>['src','alt','title','width','height','style'],
                    'td'=>['colspan','rowspan','style'],
                    'th'=>['colspan','rowspan','style'],
                ];
                $common = ['style','title'];
                $allowedAttrs = array_unique(array_merge($common, $perTag[$tag] ?? []));
                $attrs = [];
                foreach ($node->attributes as $attribute) $attrs[] = $attribute->name;
                foreach ($attrs as $attributeName) {
                    $value = trim($node->getAttribute($attributeName));
                    if (!in_array(strtolower($attributeName), $allowedAttrs, true)) {
                        $node->removeAttribute($attributeName);
                        continue;
                    }
                    $attributeName = strtolower($attributeName);
                    if ($attributeName === 'href') {
                        $scheme = strtolower((string)parse_url($value, PHP_URL_SCHEME));
                        if ($value === '' || str_starts_with($value, '//') || ($scheme !== '' && !in_array($scheme, ['http','https','mailto','tel'], true))) $node->removeAttribute('href');
                    } elseif ($attributeName === 'target') {
                        if ($value !== '_blank' && $value !== '_self') $node->removeAttribute('target');
                    } elseif ($attributeName === 'rel') {
                        $node->setAttribute('rel', 'noopener noreferrer');
                    } elseif ($attributeName === 'src') {
                        if (!preg_match('~^/(?:[A-Za-z0-9_-]+/)*public/uploads/articles/[A-Za-z0-9._-]+$~', $value)) $node->removeAttribute('src');
                    } elseif (in_array($attributeName, ['width','height','colspan','rowspan'], true)) {
                        if (!preg_match('/^[0-9]{1,4}$/', $value)) $node->removeAttribute($attributeName);
                    } elseif ($attributeName === 'style') {
                        $safe = [];
                        foreach (explode(';', $value) as $declaration) {
                            if (!str_contains($declaration, ':')) continue;
                            [$property,$setting] = array_map('trim', explode(':', $declaration, 2));
                            $property = strtolower($property); $setting = trim($setting);
                            if ($property === 'text-align' && in_array(strtolower($setting), ['left','center','right','justify'], true)) $safe[] = $property.':'.strtolower($setting);
                            elseif (in_array($property, ['color','background-color'], true) && preg_match('/^(#[0-9a-f]{3,8}|[a-z]{3,20}|rgba?\([0-9.,% ]+\))$/i', $setting)) $safe[] = $property.':'.$setting;
                            elseif ($property === 'font-size' && preg_match('/^[0-9.]+(px|pt|em|rem|%)$/i', $setting)) $safe[] = $property.':'.$setting;
                        }
                        if ($safe) $node->setAttribute('style', implode(';', $safe)); else $node->removeAttribute('style');
                    }
                }
            }
            if ($node->parentNode) $clean($node);
            if ($node instanceof DOMElement && strtolower($node->tagName) === 'a' && $node->getAttribute('target') === '_blank') $node->setAttribute('rel', 'noopener noreferrer');
        }
    };
    $clean($root);
    $result = '';
    foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);
    return $result;
}

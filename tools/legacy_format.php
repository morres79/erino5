<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = dirname(__DIR__);
$files = [];
$walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/Doc', FilesystemIterator::SKIP_DOTS));
foreach ($walk as $file) if ($file->isFile()) {
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    $files[strtolower($relative)] = $relative;
}
$missing = [];
function import_url(string $url): string {
    global $files, $missing;
    $url = trim($url);
    if (preg_match('~^doc/~i', $url)) {
        $decoded = rawurldecode($url);
        $url = $files[strtolower($decoded)] ?? preg_replace('~^doc/~i', 'Doc/', $url);
        if (!isset($files[strtolower($decoded)])) $missing[$url] = true;
    }
    return str_replace(['[', ']', "\r", "\n"], ['%5B', '%5D', '', ''], $url);
}
function import_children(DOMNode $node): string {
    $text = '';
    foreach ($node->childNodes as $child) $text .= import_node($child);
    return $text;
}
function import_node(DOMNode $node): string {
    if ($node instanceof DOMText) return preg_replace('/[\s\x{00a0}]+/u', ' ', $node->textContent);
    if (!$node instanceof DOMElement) return '';
    $tag = strtolower($node->tagName);
    $classes = preg_split('/\s+/', trim($node->getAttribute('class')));
    if (in_array('inf_sign', $classes, true) || in_array($tag, ['input','script','style','button'], true)) return '';
    if ($tag === 'br') return "\n";
    $content = import_children($node);
    if ($tag === 'form') {
        $options = [];
        foreach ($node->getElementsByTagName('label') as $label) $options[] = '[li]'.trim(import_children($label)).'[/li]';
        return $options ? "\n[ul]\n".implode("\n", $options)."\n[/ul]\n" : '';
    }
    if (in_array('inf_alert', $classes, true)) return "\n[alert]".trim($content)."[/alert]\n";
    if (in_array('inf_comment', $classes, true)) return "\n[comment]".trim($content)."[/comment]\n";
    if (in_array('inf_impotent', $classes, true)) return '[important]'.trim($content).'[/important]';
    if (in_array($tag, ['b','strong'], true)) return '[b]'.$content.'[/b]';
    if (in_array($tag, ['i','em','cite'], true)) return '[i]'.$content.'[/i]';
    if ($tag === 'a') return '[url='.import_url($node->getAttribute('href')).']'.$content.'[/url]';
    if ($tag === 'sup') return '[sup]'.$content.'[/sup]';
    if (in_array($tag, ['h5','h6'], true)) return "\n[".$tag.']'.trim($content).'[/'.$tag."]\n";
    if (in_array($tag, ['ul','ol','li','table','tr','td','th'], true)) return "\n[".$tag.']'.trim($content).'[/'.$tag."]\n";
    if (in_array($tag, ['p','div'], true)) return "\n".$content."\n";
    return $content;
}

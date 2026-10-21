<?php
require dirname(__DIR__).'/app/bootstrap.php';
$root = dirname(__DIR__);
$manifest = json_decode(file_get_contents($root.'/database/legacy/news.json'), true, 512, JSON_THROW_ON_ERROR);
$important = json_decode(file_get_contents($root.'/database/legacy/important.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest['records'] = array_merge($manifest['records'], $important['records']);
libxml_use_internal_errors(true);
function legacy_dom(string $html): DOMDocument {
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    return $dom;
}
function legacy_normalize(string $text): string { return preg_replace('/[\s\x{00a0}]+/u', '', $text); }
$count = 0; $links = 0; $tables = 0;
foreach ($manifest['records'] as $record) {
    $html = preg_replace('~<\?php.*?\?>~s', '', file_get_contents($root.'/database/legacy/'.basename($record['source'], '.php').'.html'));
    $source = legacy_dom($html); $xp = new DOMXPath($source);
    $entry = $xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," inf_wraper ")]')->item($record['source_position'] - 1);
    $rendered = legacy_dom('<h1>'.h($record['title']).'</h1>'.render_news_body($record['body']).render_news_signature($record['signature']));
    $renderedText = legacy_normalize($rendered->textContent);
    foreach ($xp->query('.//text()', $entry) as $text) {
        $normalized = legacy_normalize($text->textContent);
        if ($normalized !== '' && !str_contains($renderedText, $normalized)) throw new RuntimeException('Missing text in '.$record['source'].' #'.$record['source_position'].': '.$normalized);
    }
    $oldLinks = $entry->getElementsByTagName('a');
    $newLinks = $rendered->getElementsByTagName('a');
    if ($oldLinks->length !== $newLinks->length) throw new RuntimeException('Link count mismatch: '.$record['title']);
    foreach ($oldLinks as $i=>$a) {
        $before = strtolower(rawurldecode(trim($a->getAttribute('href'))));
        $after = strtolower(rawurldecode(trim($newLinks->item($i)->getAttribute('href'))));
        if ($before !== $after) throw new RuntimeException('Link changed: '.$before);
        $links++;
    }
    if ($entry->getElementsByTagName('table')->length !== $rendered->getElementsByTagName('table')->length) throw new RuntimeException('Table count mismatch');
    $tables += $rendered->getElementsByTagName('table')->length;
    if ($rendered->getElementsByTagName('form')->length !== 0 || $rendered->getElementsByTagName('input')->length !== 0) throw new RuntimeException('Historical poll must remain inactive');
    $count++;
}
echo 'PASS '.$count.' legacy entries: every text fragment, '.$links.' links and '.$tables.' tables preserved; historical poll inactive'.PHP_EOL;
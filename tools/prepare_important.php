<?php
declare(strict_types=1);
require __DIR__.'/legacy_format.php';
$records = [];
foreach (['index.php'] as $source) {
    $raw = file_get_contents($root.'/database/legacy/'.basename($source, '.php').'.html');

    $html = preg_replace('~<\?php.*?\?>~s', '', $raw);
    $dom = new DOMDocument(); libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
    $xp = new DOMXPath($dom);
    foreach ($xp->query('//div[contains(concat(" ",normalize-space(@class)," ")," inf_wraper ")]') as $i => $node) {
        if ($i < 4) continue;
        $head = $xp->query('./div[contains(concat(" ",normalize-space(@class)," ")," inf_header ")]', $node)->item(0);
        $body = $xp->query('./div[contains(concat(" ",normalize-space(@class)," ")," inf_body ")]', $node)->item(0);
        if (!$head || !$body) throw new RuntimeException('Invalid legacy news structure');
        $title = trim(preg_replace('/\s+/u', ' ', $head->textContent));
        $signatures = [];
        foreach ($xp->query('.//*[contains(concat(" ",normalize-space(@class)," ")," inf_sign ")]', $body) as $sign) $signatures[] = trim(preg_replace('/\s+/u',' ', $sign->textContent));
        $content = '';
        foreach ($head->getElementsByTagName('a') as $link) $content .= import_node($link)."\n";
        $content .= import_children($body);
        $content = preg_replace('/ *\n */u', "\n", $content);
        $content = preg_replace('/\n{3,}/', "\n\n", $content);
        $records[] = [
            'legacy_key' => hash('sha256', $source.':'.($i+1).':'.$title),
            'source' => $source, 'source_position' => $i+1,
            'title' => $title, 'body' => trim($content),
            'signature' => implode(' / ', array_unique($signatures)),
            'status' => $source === 'index.php' ? 'published' : 'archived',
            'legacy_order' => null, 'is_important' => 1,
        ];
    }
}
if (count($records) !== 4) throw new RuntimeException('Expected 4 important notices');

$records = array_reverse($records); // Descending IDs retain the original display order.
$manifest = ['version'=>1, 'order'=>'Original important notices. Publication dates unknown; existing news IDs are preserved.', 'records'=>$records, 'missing_document_paths'=>array_keys($missing)];
file_put_contents($root.'/database/legacy/important.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo 'Prepared '.count($records).' records: '.count(array_filter($records, fn($r)=>$r['status']==='published')).' published, '.count(array_filter($records, fn($r)=>$r['status']==='archived')).' archived.'.PHP_EOL;
echo 'Pre-existing missing document paths: '.count($missing).PHP_EOL;
foreach (array_keys($missing) as $path) echo $path.PHP_EOL;

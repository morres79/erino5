<?php
declare(strict_types=1);
function news_link_url(string $url): ?string {
    $url = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '' || preg_match('/[\x00-\x1f\\\\]/', $url)) return null;
    $url = str_replace(' ', '%20', $url);
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if ($scheme === false || ($scheme !== null && !in_array(strtolower($scheme), ['http','https','mailto','tel'], true))) return null;
    return $url;
}
function render_news_inline(string $text): string {
    $html = h($text);
    $html = preg_replace_callback('~\[url=([^\]\r\n]+)\](.*?)\[/url\]~s', function($m) {
        $url = news_link_url($m[1]);
        return $url === null ? $m[2] : '<a href="'.h($url).'">'.$m[2].'</a>';
    }, $html);
    for ($depth = 0; $depth < 16; $depth++) {
        $before = $html;
        foreach (['b'=>'strong', 'i'=>'em', 'sup'=>'sup', 'h5'=>'h5', 'h6'=>'h6'] as $code=>$tag) {
            $html = preg_replace('~\['.$code.'\](.*?)\[/'.$code.'\]~s', '<'.$tag.'>$1</'.$tag.'>', $html);
        }
        $html = preg_replace('~\[important\](.*?)\[/important\]~s', '<span class="inf_impotent">$1</span>', $html);
        if ($html === $before) break;
    }
    return nl2br($html);
}
function render_news_table(string $inside): ?string {
    preg_match_all('~\[tr\](.*?)\[/tr\]~s', $inside, $rows);
    if (!$rows[1] || trim(preg_replace('~\[tr\].*?\[/tr\]~s', '', $inside)) !== '') return null;
    $html = '<table class="inf_table"><tbody>';
    foreach ($rows[1] as $row) {
        preg_match_all('~\[(td|th)\](.*?)\[/\1\]~s', $row, $cells, PREG_SET_ORDER);
        if (!$cells || trim(preg_replace('~\[(td|th)\].*?\[/\1\]~s', '', $row)) !== '') return null;
        $html .= '<tr>';
        foreach ($cells as $cell) $html .= '<'.$cell[1].'>'.render_news_inline($cell[2]).'</'.$cell[1].'>';
        $html .= '</tr>';
    }
    return $html.'</tbody></table>';
}
function render_news_text(string $text): string {
    $html = ''; $cursor = 0;
    preg_match_all('~\[(ul|ol|table)\](.*?)\[/\1\]~s', $text, $lists, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($lists as $list) {
        $plain = substr($text, $cursor, $list[0][1] - $cursor);
        if (trim($plain) !== '') $html .= '<div class="news-text">'.render_news_inline(trim($plain, "\r\n")).'</div>';
        $inside = $list[2][0]; $rendered = null;
        if ($list[1][0] === 'table') {
            $rendered = render_news_table($inside);
        } else {
            preg_match_all('~\[li\](.*?)\[/li\]~s', $inside, $items);
            $remainder = preg_replace('~\[li\](.*?)\[/li\]~s', '', $inside);
            if (count($items[1]) > 0 && trim($remainder) === '') {
                $tag = $list[1][0]; $rendered = '<'.$tag.' class="news-list">';
                foreach ($items[1] as $item) $rendered .= '<li>'.render_news_inline($item).'</li>';
                $rendered .= '</'.$tag.'>';
            }
        }
        $html .= $rendered ?? '<div class="news-text">'.render_news_inline($list[0][0]).'</div>';
        $cursor = $list[0][1] + strlen($list[0][0]);
    }
    $plain = substr($text, $cursor);
    if (trim($plain) !== '') $html .= '<div class="news-text">'.render_news_inline(trim($plain, "\r\n")).'</div>';
    return $html;
}
function render_news_body(string $body): string {
    $html = ''; $cursor = 0;
    preg_match_all('~\[(alert|comment)\](.*?)\[/\1\]~s', $body, $blocks, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    foreach ($blocks as $block) {
        $html .= render_news_text(substr($body, $cursor, $block[0][1] - $cursor));
        $html .= '<div class="inf_'.$block[1][0].'">'.render_news_text($block[2][0]).'</div>';
        $cursor = $block[0][1] + strlen($block[0][0]);
    }
    return $html.render_news_text(substr($body, $cursor));
}
function render_news_signature(string $signature): string {
    return trim($signature) === '' ? '' : '<div class="inf_sign">'.h($signature).'</div>';
}
function news_order_sql(): string {
    return 'id DESC';
}
function render_news_entry(array $entry): string {
    $url = 'news.php?id='.(int)$entry['id'];
    $html = '<article class="inf_wraper"><div class="inf_header"><a href="'.$url.'">'.h($entry['title']).'</a></div><div class="inf_body">';
    if (!empty($entry['published_at'])) $html .= '<p><time>'.h($entry['published_at']).'</time></p>';
    $html .= render_news_body($entry['body']);
    $html .= '<p class="news-more"><a href="'.$url.'">Перейти -&gt;</a></p>';
    return $html.render_news_signature($entry['signature'] ?? '').'</div></article>';
}
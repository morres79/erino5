<?php
require dirname(__DIR__).'/app/bootstrap.php';
function expect_format(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
    echo 'PASS '.$message.PHP_EOL;
}
$body = "Начало\n[alert]Важное\n<script>alert(1)</script>[/alert]\nСередина\n[comment]Примечание[/comment]\n[alert]Второе[/alert]\nКонец";
$html = render_news_body($body);
expect_format(substr_count($html, 'class="inf_alert"') === 2, 'multiple alert blocks');
expect_format(strpos($html, 'Начало') < strpos($html, 'Важное') && strpos($html, 'Середина') < strpos($html, 'Примечание') && strpos($html, 'Второе') < strpos($html, 'Конец'), 'original block order');
expect_format(str_contains($html, 'class="inf_comment"'), 'comment styling');
expect_format(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'block contents escape HTML');
expect_format(str_contains(render_news_body('<b>Обычный текст</b>'), '&lt;b&gt;'), 'plain content escapes HTML');
expect_format(str_contains(render_news_body('[alert]Незакрытый блок'), '[alert]Незакрытый блок'), 'unclosed markup remains text');
expect_format(render_news_signature('') === '', 'empty signature omitted');
expect_format(render_news_signature('<img src=x>') === '<div class="inf_sign">&lt;img src=x&gt;</div>', 'signature escapes HTML');
expect_format(str_contains(render_news_body('[b]Жирный [i]курсив[/i][/b]'), '<strong>Жирный <em>курсив</em></strong>'), 'nested bold and italic');
expect_format(str_contains(render_news_body('[i]Курсив [b]жирный[/b][/i]'), '<em>Курсив <strong>жирный</strong></em>'), 'italic around bold');
$lists = render_news_body('[ul][li]Первый[/li][li][b]Второй[/b][/li][/ul][ol][li][i]Шаг[/i][/li][/ol]');
expect_format(str_contains($lists, '<ul class="news-list"><li>Первый</li><li><strong>Второй</strong></li></ul>'), 'bullet list with inline formatting');
expect_format(str_contains($lists, '<ol class="news-list"><li><em>Шаг</em></li></ol>'), 'numbered list with italic');
$inside = render_news_body('[alert][b]Важно[/b][ol][li]Действие[/li][/ol][/alert][comment][i]Примечание[/i][/comment]');
expect_format(str_contains($inside, '<strong>Важно</strong>') && str_contains($inside, '<ol class="news-list">') && str_contains($inside, '<em>Примечание</em>'), 'formatting and lists inside editorial blocks');
$attack = render_news_body('[ul][li][b]<img src=x onerror=alert(1)>[/b][/li][/ul]');
expect_format(!str_contains($attack, '<img') && str_contains($attack, '&lt;img'), 'list and inline text cannot inject HTML');
expect_format(str_contains(render_news_body('[ul]Текст без пунктов[/ul]'), 'Текст без пунктов'), 'malformed list does not discard content');
expect_format(str_contains(render_news_body('[b]Незакрыто'), '[b]Незакрыто'), 'unclosed inline markup remains visible');
expect_format(str_contains(render_news_body('[url=Doc/2026/file.PDF][b]Документ[/b][/url]'), '<a href="Doc/2026/file.PDF"><strong>Документ</strong></a>'), 'legacy document links preserve labels and formatting');
expect_format(!str_contains(render_news_body('[url=javascript:alert(1)]Click[/url]'), '<a '), 'unsafe link schemes rejected');
expect_format(str_contains(render_news_body('м[sup]2[/sup]'), 'м<sup>2</sup>'), 'legacy units preserve superscripts');
$table = render_news_body('[table][tr][td]Тариф[/td][td][b]10[/b][/td][/tr][/table]');
expect_format(str_contains($table, '<table class="inf_table">') && str_contains($table, '<td><strong>10</strong></td>'), 'legacy tables preserve cells and formatting');
expect_format(str_contains(render_news_body('[url=Doc/2020/Решение члена ТСЖ 2020.xlsx]Документ[/url]'), 'href="Doc/2020/Решение%20члена%20ТСЖ%202020.xlsx"'), 'legacy filenames with spaces remain clickable');
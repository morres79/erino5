(() => {
    const body = document.getElementById('news-body');
    if (!body) return;
    const labels = {
        alert: 'Текст важного сообщения', comment: 'Текст примечания',
        b: 'Жирный текст', i: 'Курсивный текст',
        ul: 'Первый пункт\nВторой пункт', ol: 'Первый пункт\nВторой пункт'
    };
    function applyFormat(type) {
        if (!Object.hasOwn(labels, type)) return;
        const start = body.selectionStart;
        const end = body.selectionEnd;
        const selected = body.value.slice(start, end) || labels[type];
        const inline = type === 'b' || type === 'i';
        const list = type === 'ul' || type === 'ol';
        const before = !inline && start > 0 && body.value[start - 1] !== '\n' ? '\n' : '';
        const after = !inline && end < body.value.length && body.value[end] !== '\n' ? '\n' : '';
        const opening = `[${type}]`;
        let content = selected;
        if (list) {
            const lines = selected.split(/\r?\n/).map(line => line.trim()).filter(Boolean);
            content = '\n' + (lines.length ? lines : ['Пункт списка']).map(line => `[li]${line}[/li]`).join('\n') + '\n';
        }
        body.setRangeText(`${before}${opening}${content}[/${type}]${after}`, start, end, 'end');
        body.focus();
        if (!list) {
            const textStart = start + before.length + opening.length;
            body.setSelectionRange(textStart, textStart + content.length);
        }
        body.dispatchEvent(new Event('input', { bubbles: true }));
    }
    document.querySelectorAll('[data-news-block], [data-news-format]').forEach(button => {
        button.addEventListener('click', () => applyFormat(button.dataset.newsFormat || button.dataset.newsBlock));
    });
    body.addEventListener('keydown', event => {
        if ((event.ctrlKey || event.metaKey) && !event.altKey && ['b', 'i'].includes(event.key.toLowerCase())) {
            event.preventDefault();
            applyFormat(event.key.toLowerCase());
        }
    });
})();
// Syntax highlighting of the review page's diff. highlight.js is loaded only once a file needs it.
//
// Each side of a hunk is highlighted as one block of code (new: context and added lines, old: context and deleted
// lines), so strings and comments spanning lines come out right within a hunk. Once the whole new file is loaded
// (Full file / New version) its new side is highlighted again from the whole file.

let loading = null;

const loadHighlighter = () => (loading ??= Promise.all([
    import('highlight.js/lib/common'),
    import('highlight.js/lib/languages/dockerfile'),
    import('highlight.js/lib/languages/twig'),
]).then(([{ default: hljs }, { default: dockerfile }, { default: twig }]) => {
    hljs.registerLanguage('dockerfile', dockerfile);
    hljs.registerLanguage('twig', twig);
    return hljs;
}));

// Extensions highlight.js does not know by that name.
const EXTENSIONS = {
    cjs: 'javascript',
    mjs: 'javascript',
    vue: 'xml',
    svelte: 'xml',
    phtml: 'php-template',
    neon: 'yaml',
    conf: 'ini',
    env: 'ini',
    lock: 'json',
};

// Larger diffs and files are left plain, to keep the page responsive.
const MAX_ROWS = 10000;
const MAX_FILE_LINES = 20000;

const languageFor = (hljs, path) => {
    const name = path.split('/').pop();
    const lower = name.toLowerCase();
    if (lower.endsWith('.blade.php')) {
        return 'xml';
    }
    if (lower === 'dockerfile' || lower.endsWith('.dockerfile')) {
        return 'dockerfile';
    }
    if (lower === 'makefile') {
        return 'makefile';
    }
    if (lower.startsWith('.env')) {
        return 'ini';
    }
    if (!name.includes('.')) {
        return null;
    }
    const extension = lower.split('.').pop();
    const language = EXTENSIONS[extension] ?? extension;
    return hljs.getLanguage(language) ? language : null;
};

/**
 * Split highlighted HTML into lines, closing the spans open at each line end and opening them again on the next.
 */
export const splitHighlighted = (html) => {
    const lines = [];
    const open = [];
    let current = '';
    for (const [, tag, close, newline, text] of html.matchAll(/(<span[^>]*>)|(<\/span>)|(\n)|([^<\n]+)/g)) {
        if (tag) {
            open.push(tag);
            current += tag;
        } else if (close) {
            open.pop();
            current += close;
        } else if (newline) {
            lines.push(current + '</span>'.repeat(open.length));
            current = open.join('');
        } else {
            current += text;
        }
    }
    lines.push(current);
    return lines;
};

const highlightLines = (hljs, language, texts) => {
    const lines = splitHighlighted(hljs.highlight(texts.join('\n'), { language, ignoreIllegals: true }).value);
    return lines.length === texts.length ? lines : null;
};

const codeText = (row) => row.querySelector('.code-text');

// Highlight rows as one block of code; with only, set the result on those rows alone.
const highlightRows = (hljs, language, rows, only = null) => {
    if (!rows.length) {
        return;
    }
    const lines = highlightLines(hljs, language, rows.map((row) => codeText(row).textContent));
    lines?.forEach((html, index) => {
        if (!only || only(rows[index])) {
            codeText(rows[index]).innerHTML = html;
        }
    });
};

/**
 * Highlight a file's diff, hunk by hunk.
 */
export const highlightFile = async (file) => {
    if (file.dataset.highlighted) {
        return;
    }
    file.dataset.highlighted = '1';
    const hunks = [...file.querySelectorAll('tbody.diff-hunk')];
    if (!hunks.length || file.querySelectorAll('tbody.diff-hunk tr.line').length > MAX_ROWS) {
        return;
    }
    const hljs = await loadHighlighter();
    const language = languageFor(hljs, file.dataset.path);
    if (!language) {
        return;
    }
    hunks.forEach((tbody) => {
        // The whole file may be highlighted already, which is better than this hunk alone.
        if (!file.dataset.fileHighlighted) {
            highlightRows(hljs, language, [...tbody.querySelectorAll(':scope > tr.line-context, :scope > tr.line-add')]);
        }
        highlightRows(
            hljs,
            language,
            [...tbody.querySelectorAll(':scope > tr.line-context, :scope > tr.line-del')],
            (row) => row.classList.contains('line-del'),
        );
    });
};

/**
 * Highlight the new side of a file from its whole new version: unchanged and added lines, in and between the hunks.
 */
export const highlightWholeFile = async (file, lines) => {
    if (lines.length > MAX_FILE_LINES) {
        return;
    }
    const hljs = await loadHighlighter();
    const language = languageFor(hljs, file.dataset.path);
    if (!language) {
        return;
    }
    const highlighted = highlightLines(hljs, language, lines);
    if (!highlighted) {
        return;
    }
    file.querySelectorAll('tr.line-context[data-line], tr.line-add[data-line]').forEach((row) => {
        const html = highlighted[Number(row.dataset.line) - 1];
        if (html !== undefined) {
            codeText(row).innerHTML = html;
        }
    });
    file.dataset.fileHighlighted = '1';
};

/**
 * Highlight each file as it comes near the screen.
 */
export const highlightOnScroll = (files) => {
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                observer.unobserve(entry.target);
                highlightFile(entry.target).catch(() => {
                    // Left plain.
                });
            }
        });
    }, { rootMargin: '1500px 0px' });
    files.forEach((file) => observer.observe(file));
};

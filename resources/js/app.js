import './bootstrap';

// Pull request panes on the Reviews page: one open at a time, close on outside click or Escape.
const closePanes = (except = null) => {
    document.querySelectorAll('details.pr-pane[open]').forEach((pane) => {
        if (pane !== except) {
            pane.open = false;
        }
    });
};

document.addEventListener('toggle', (event) => {
    if (event.target.matches?.('details.pr-pane') && event.target.open) {
        closePanes(event.target);
    }
}, true);

document.addEventListener('click', (event) => {
    if (!event.target.closest('details.pr-pane')) {
        closePanes();
    }
});

document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
        closePanes();
    }
});

import './review';

// Copy buttons (e.g. the AI review prompt next to a ticket on the Reviews page).
const copyText = async (text) => {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch {
        // Clipboard API needs HTTPS or localhost; fall back to a hidden textarea.
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        const copied = document.execCommand('copy');
        textarea.remove();
        return copied;
    }
};

document.addEventListener('click', async (event) => {
    const button = event.target.closest('.js-copy');
    if (!button) {
        return;
    }
    const copied = await copyText(button.dataset.copy);
    button.classList.toggle('is-copied', copied);
    button.classList.toggle('is-copy-failed', !copied);
    clearTimeout(button.copyTimer);
    button.copyTimer = setTimeout(() => button.classList.remove('is-copied', 'is-copy-failed'), 1500);
});

// Files for agents: "Load from a file" fills the name (when empty) and the content.
document.querySelectorAll('.js-load-file').forEach((input) => {
    input.addEventListener('change', async () => {
        const file = input.files[0];
        if (!file) {
            return;
        }
        const form = input.closest('form');
        const name = form.querySelector('[name=name]');
        if (!name.value.trim()) {
            name.value = file.name;
        }
        form.querySelector('[name=content]').value = await file.text();
        input.value = '';
    });
});

// Open the file named in the address (#file-12) after saving it.
if (location.hash.startsWith('#file-')) {
    const file = document.querySelector(location.hash);
    if (file instanceof HTMLDetailsElement) {
        file.open = true;
    }
}

// Reviews: the workspace and repository filters apply when changed. A new workspace starts with all its repositories.
document.querySelectorAll('.review-filters select').forEach((select) => {
    select.addEventListener('change', () => {
        const form = select.form;
        if (select.classList.contains('js-filter-workspace')) {
            form.querySelector('.js-filter-repository').value = '';
        }
        form.requestSubmit();
    });
});

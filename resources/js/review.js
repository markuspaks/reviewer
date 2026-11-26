// Pull request review page: comments are saved here unsent (add, reply, edit, delete), then sent all at once to the
// user's draft review in Bitbucket, where they stay pending until the review is finished there. Also navigation between unsent comments and the file tree.

import { highlightOnScroll, highlightWholeFile } from './highlight';

const review = document.querySelector('.review');

if (review) {
    const draftsUrl = review.dataset.draftsUrl;
    const publishUrl = review.dataset.publishUrl;
    const viewedUrl = review.dataset.viewedUrl;
    const reviewStateUrl = review.dataset.reviewStateUrl;
    const commentsUrl = review.dataset.commentsUrl;
    const fileUrl = review.dataset.fileUrl;

    // Where Edit and Delete go: unsent comments are kept here, pending Bitbucket comments are changed in Bitbucket.
    const commentUrl = (comment) => comment.dataset.editable === 'draft'
        ? `${draftsUrl}/${comment.dataset.draftId}`
        : `${commentsUrl}/${comment.dataset.commentId}`;
    let currentDraft = -1;

    const htmlToElement = (html) => {
        const template = document.createElement('template');
        template.innerHTML = html.trim();
        return template.content.firstElementChild;
    };

    const errorMessage = (error) =>
        error.response?.data?.message
        ?? (error.response?.data?.errors ? Object.values(error.response.data.errors).flat().join(' ') : null)
        ?? error.message;

    /**
     * A comment form. onSubmit(text) must return a promise; the form shows errors and stays open on failure.
     */
    const commentForm = ({ initial = '', submitLabel = 'Save', onSubmit, onCancel }) => {
        const form = htmlToElement(`
            <form class="comment-form">
                <textarea class="textarea is-small" rows="3" placeholder="Write a comment (Markdown). Ctrl+Enter saves. Nothing is sent until you click Send."></textarea>
                <p class="help is-danger" hidden></p>
                <div class="buttons are-small">
                    <button type="submit" class="button is-primary"></button>
                    <button type="button" class="button is-light js-cancel">Cancel</button>
                </div>
            </form>`);
        const textarea = form.querySelector('textarea');
        const submit = form.querySelector('[type=submit]');
        const error = form.querySelector('.help');
        textarea.value = initial;
        submit.textContent = submitLabel;

        const cancel = () => {
            form.remove();
            onCancel?.();
        };

        form.addEventListener('submit', async (event) => {
            event.preventDefault();
            const text = textarea.value.trim();
            if (!text) {
                textarea.focus();
                return;
            }
            submit.classList.add('is-loading');
            error.hidden = true;
            try {
                await onSubmit(text);
                form.remove();
            } catch (e) {
                error.textContent = errorMessage(e);
                error.hidden = false;
            } finally {
                submit.classList.remove('is-loading');
            }
        });
        form.querySelector('.js-cancel').addEventListener('click', cancel);
        textarea.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) {
                form.requestSubmit();
            } else if (event.key === 'Escape') {
                cancel();
            }
        });
        setTimeout(() => textarea.focus());

        return form;
    };

    // --- Unsent comments panel -------------------------------------------------------------------------

    const draftLabel = (comment) => {
        const file = comment.closest('.diff-file');
        if (!file) {
            return 'Pull request';
        }
        const name = file.dataset.path.split('/').pop();
        const row = comment.closest('.comments-row')?.previousElementSibling;
        if (row?.dataset.line) {
            return `${name}:${row.dataset.line}`;
        }
        const label = comment.closest('.thread')?.dataset.label;
        return label ? `${name} · ${label}` : name;
    };

    const drafts = () => [...review.querySelectorAll('.comment.is-unsent')];

    const refreshDrafts = () => {
        const list = review.querySelector('.js-drafts-list');
        const items = drafts();
        list.replaceChildren(...items.map((comment, index) => {
            const li = document.createElement('li');
            const link = document.createElement('a');
            link.href = `#${comment.id}`;
            link.dataset.draftIndex = index;
            const where = document.createElement('span');
            where.className = 'draft-where';
            where.textContent = draftLabel(comment);
            const text = document.createElement('span');
            text.className = 'draft-text';
            text.textContent = comment.querySelector('.comment-raw')?.value.slice(0, 80) ?? '';
            link.append(where, text);
            li.append(link);
            return li;
        }));
        review.querySelectorAll('.js-drafts-count').forEach((count) => { count.textContent = items.length; });
        review.querySelector('.js-drafts-empty').hidden = items.length > 0;
        review.querySelector('.drafts-nav').hidden = items.length === 0;
        const publish = review.querySelector('.js-publish');
        publish.disabled = items.length === 0;
        publish.textContent = items.length
            ? `Send ${items.length} ${items.length === 1 ? 'comment' : 'comments'} to Bitbucket`
            : 'Send to Bitbucket';
        review.querySelector('.js-publish-help').hidden = items.length === 0;
        refreshThreads();
    };

    // The threads already in Bitbucket, unresolved and resolved, listed like the unsent comments.
    const threadsIn = (section) => [...review.querySelectorAll(
        section === 'resolved' ? '.thread.is-resolved' : '.thread:not(.is-unsent):not(.is-resolved)',
    )];

    const refreshThreads = () => {
        ['unresolved', 'resolved'].forEach((section) => {
            const items = threadsIn(section);
            review.querySelector(`.js-threads-list[data-section="${section}"]`).replaceChildren(...items.map((thread) => {
                const root = thread.querySelector(':scope > .comment');
                // The first comment still there: the root may be deleted.
                const shown = [root, ...thread.querySelectorAll(':scope > .replies > .comment')]
                    .find((comment) => !comment.querySelector('.comment-deleted')) ?? root;
                const li = document.createElement('li');
                const link = document.createElement('a');
                link.href = `#${root.id}`;
                link.dataset.threadId = thread.dataset.threadId;
                const where = document.createElement('span');
                where.className = 'draft-where';
                where.textContent = draftLabel(root);
                const text = document.createElement('span');
                text.className = 'draft-text';
                const author = shown.querySelector('.comment-header strong')?.textContent.trim();
                const raw = shown.querySelector('.comment-raw')?.value.slice(0, 80) ?? '';
                text.textContent = author ? `${author}: ${raw}` : raw;
                link.append(where, text);
                li.append(link);
                return li;
            }));
            review.querySelector(`.js-threads-count[data-section="${section}"]`).textContent = items.length;
            review.querySelector(`.js-threads-empty[data-section="${section}"]`).hidden = items.length > 0;
        });
    };

    const showThread = (thread) => {
        if (hideSent.checked && !thread.querySelector('.comment.is-unsent')) {
            // Hidden by the filter: show the sent comments again to get to it.
            hideSent.checked = false;
            hideSent.dispatchEvent(new Event('change'));
        }
        if (thread.classList.contains('is-resolved')) {
            expandThread(thread, true);
        }
        showComment(thread.querySelector(':scope > .comment'));
    };

    const showComment = (comment) => {
        const file = comment.closest('.diff-file');
        file?.classList.remove('is-collapsed');
        if (file && comment.closest('.diff-expanded') && fileView(file) === 'diff') {
            // On a line outside the diff, shown only with the whole file.
            setFileView(file, 'full');
        } else if (file && fileView(file) === 'new' && comment.closest('.comments-row')?.previousElementSibling?.classList.contains('line-del')) {
            // On a deleted line, which the new version leaves out.
            setFileView(file, 'full');
        }
        comment.scrollIntoView({ behavior: 'smooth', block: 'center' });
        comment.classList.remove('is-flashing');
        void comment.offsetWidth; // restart the animation
        comment.classList.add('is-flashing');
    };

    const goToDraft = (step) => {
        const items = drafts();
        if (!items.length) {
            return;
        }
        currentDraft = (currentDraft + step + items.length) % items.length;
        showComment(items[currentDraft]);
    };

    // --- Adding comments ----------------------------------------------------------------------

    const commentsRowFor = (row) => {
        let next = row.nextElementSibling;
        if (!next?.classList.contains('comments-row')) {
            next = htmlToElement('<table><tbody><tr class="comments-row"><td colspan="3"><div class="threads"></div></td></tr></tbody></table>')
                .querySelector('tr');
            row.after(next);
        }
        return next;
    };

    const removeEmptyCommentsRow = (row) => {
        if (row?.classList.contains('comments-row') && !row.querySelector('.thread, .comment-form')) {
            row.remove();
        }
    };

    const addLineComment = (row) => {
        const commentsRow = commentsRowFor(row);
        const cell = commentsRow.querySelector('td');
        if (cell.querySelector(':scope > .comment-form')) {
            cell.querySelector(':scope > .comment-form textarea').focus();
            return;
        }
        const file = row.closest('.diff-file');
        cell.append(commentForm({
            onSubmit: async (content) => {
                const { data } = await axios.post(draftsUrl, {
                    content,
                    path: file.dataset.path,
                    line: Number(row.dataset.line),
                    side: row.dataset.side,
                });
                cell.querySelector('.threads').append(htmlToElement(data.html));
                refreshDrafts();
            },
            onCancel: () => removeEmptyCommentsRow(commentsRow),
        }));
    };

    const addGeneralComment = (button) => {
        if (button.previousElementSibling?.classList.contains('comment-form')) {
            return;
        }
        button.before(commentForm({
            onSubmit: async (content) => {
                const { data } = await axios.post(draftsUrl, { content });
                review.querySelector('.js-general-threads').append(htmlToElement(data.html));
                refreshDrafts();
            },
        }));
    };

    const addFileComment = (file) => {
        file.classList.remove('is-collapsed');
        const box = file.querySelector('.file-comments');
        if (box.querySelector(':scope > .comment-form')) {
            box.querySelector(':scope > .comment-form textarea').focus();
            return;
        }
        box.append(commentForm({
            onSubmit: async (content) => {
                const { data } = await axios.post(draftsUrl, { content, path: file.dataset.path });
                box.querySelector('.js-file-threads').append(htmlToElement(data.html));
                refreshDrafts();
            },
        }));
    };

    // Resolve a thread in Bitbucket (it folds up), or reopen it.
    const setResolved = async (button) => {
        const thread = button.closest('.thread');
        const resolved = button.dataset.resolved === '1';
        button.classList.add('is-loading');
        try {
            const { data } = await axios.put(`${commentsUrl}/${thread.dataset.threadId}/resolution`, { resolved });
            thread.querySelector(':scope > .thread-footer .js-thread-resolution').replaceWith(htmlToElement(data.html));
            thread.classList.toggle('is-resolved', data.resolved);
            expandThread(thread, false);
            refreshThreads();
        } catch (e) {
            button.classList.remove('is-loading');
            alert(errorMessage(e));
        }
    };

    const expandThread = (thread, expanded) => {
        thread.classList.toggle('is-expanded', expanded);
        thread.querySelector(':scope > .thread-footer .js-expand-thread').textContent = expanded ? 'Hide thread' : 'Show thread';
    };

    const reply = (thread) => {
        const footer = thread.querySelector(':scope > .thread-footer');
        if (footer.querySelector('.comment-form')) {
            return;
        }
        const button = footer.querySelector('.js-reply');
        button.hidden = true;
        footer.prepend(commentForm({
            submitLabel: 'Save reply',
            onSubmit: async (content) => {
                const { data } = await axios.post(draftsUrl, { content, parent_id: Number(thread.dataset.threadId) });
                thread.querySelector(':scope > .replies').append(htmlToElement(data.html));
                button.hidden = false;
                refreshDrafts();
            },
            onCancel: () => { button.hidden = false; },
        }));
    };

    // --- Editing and deleting (unsent comments, and your pending comments in Bitbucket) ------------------------------------------------------------

    const editComment = (comment) => {
        const body = comment.querySelector('.comment-body');
        if (comment.querySelector('.comment-form')) {
            return;
        }
        body.hidden = true;
        body.after(commentForm({
            initial: comment.querySelector('.comment-raw').value,
            submitLabel: 'Save',
            onSubmit: async (content) => {
                const { data } = await axios.put(commentUrl(comment), { content });
                comment.replaceWith(htmlToElement(data.html));
                refreshDrafts();
            },
            onCancel: () => { body.hidden = false; },
        }));
    };

    const deleteComment = async (comment) => {
        const question = comment.classList.contains('is-unsent')
            ? 'Delete this unsent comment?'
            : 'Delete this draft comment from your review in Bitbucket?';
        if (!confirm(question)) {
            return;
        }
        try {
            await axios.delete(commentUrl(comment));
        } catch (e) {
            alert(errorMessage(e));
            return;
        }
        const thread = comment.closest('.thread');
        const isRoot = thread && thread.dataset.threadId === comment.dataset.commentId;
        if (isRoot && !thread.querySelector('.replies .comment')) {
            const row = thread.closest('.comments-row');
            thread.remove();
            removeEmptyCommentsRow(row);
        } else if (isRoot) {
            comment.replaceChildren(Object.assign(document.createElement('p'), {
                className: 'comment-deleted',
                textContent: 'This comment was deleted.',
            }));
            comment.classList.remove('is-bitbucket-draft');
        } else {
            comment.remove();
        }
        refreshDrafts();
    };

    // --- Sending ----------------------------------------------------------------------------------

    const publish = async (button) => {
        const count = drafts().length;
        if (review.querySelector('.comment-form textarea')?.value.trim()
            && !confirm('A comment you are writing is not saved yet and will not be sent. Send the others anyway?')) {
            return;
        }
        if (!confirm(`Add ${count} ${count === 1 ? 'comment' : 'comments'} to your draft review in Bitbucket? Others see them only after you finish the review there.`)) {
            return;
        }
        button.classList.add('is-loading');
        try {
            await axios.post(publishUrl);
        } catch (e) {
            alert(errorMessage(e));
            if (!e.response?.data?.sent) {
                button.classList.remove('is-loading');
                return;
            }
        }
        // Reload to show the sent comments as Bitbucket has them (pending drafts).
        location.reload();
    };

    const hideSent = review.querySelector('.js-hide-sent');
    const applyHideSent = () => review.classList.toggle('hide-sent', hideSent.checked);
    try {
        hideSent.checked = localStorage.getItem('review.hideSent') === '1';
    } catch {
        // Storage blocked: start unchecked.
    }
    applyHideSent();
    hideSent.addEventListener('change', () => {
        applyHideSent();
        try {
            localStorage.setItem('review.hideSent', hideSent.checked ? '1' : '0');
        } catch {
            // Not remembered.
        }
    });

    // The sections of the comments column fold up; which are open is remembered.
    review.querySelectorAll('.js-drafts-section').forEach((section) => {
        const key = `review.section.${section.dataset.section}`;
        try {
            const saved = localStorage.getItem(key);
            if (saved !== null) {
                section.open = saved === '1';
            }
        } catch {
            // Storage blocked: the default.
        }
        section.addEventListener('toggle', () => {
            try {
                localStorage.setItem(key, section.open ? '1' : '0');
            } catch {
                // Not remembered.
            }
        });
    });

    // --- Viewed files ---------------------------------------------------------------------------------

    const diffFiles = [...review.querySelectorAll('.diff-file')];
    highlightOnScroll(diffFiles);

    const refreshViewedCount = () => {
        const viewed = diffFiles.filter((file) => file.classList.contains('is-viewed')).length;
        const allViewed = diffFiles.length > 0 && viewed === diffFiles.length;
        review.querySelectorAll('.js-viewed-count').forEach((count) => {
            count.textContent = `${viewed} / ${diffFiles.length} viewed`;
            count.classList.toggle('has-text-success', allViewed);
        });
        refreshFileNav();
    };

    // --- Jumping between files not viewed yet ---------------------------------------------------------

    /**
     * Open a file and scroll its header to just below the heading. Scrolling down from the top turns the heading
     * compact, which moves the page up under the scroll, so the position is corrected once scrolling stops.
     */
    const scrollToFile = (file) => {
        file.classList.remove('is-collapsed');
        file.scrollIntoView({ behavior: 'smooth', block: 'start' });

        let done = false;
        const settle = () => {
            if (done) {
                return;
            }
            done = true;
            window.removeEventListener('scrollend', settle);
            const header = review.querySelector('.review-header');
            // The heading may have just turned compact: its new height sets the files' scroll margin.
            review.style.setProperty('--review-header-height', `${header.offsetHeight}px`);
            const offset = file.getBoundingClientRect().top - header.getBoundingClientRect().bottom;
            if (offset < 0 || offset > 24) {
                file.scrollIntoView({ block: 'start' });
            }
        };
        window.addEventListener('scrollend', settle);
        // No scrollend when nothing scrolled, or in browsers without it.
        setTimeout(settle, 1000);
    };

    // While a jump is still scrolling, the next jump continues from its target rather than from where the page is.
    let jumpTarget = null;
    let jumpTimer = null;

    // The file at the top of the screen, just below the heading; -1 above the first file.
    const currentFileIndex = () => {
        if (jumpTarget !== null) {
            return jumpTarget;
        }
        const headerBottom = review.querySelector('.review-header').getBoundingClientRect().bottom;
        let current = -1;
        diffFiles.forEach((file, index) => {
            // A file scrolled to sits a little below the heading (its scroll margin).
            if (file.getBoundingClientRect().top <= headerBottom + 24) {
                current = index;
            }
        });
        return current;
    };

    // Next (step 1) or previous (step -1) file not viewed yet, without wrapping around; -1 when there is none.
    const unviewedFileIndex = (step) => {
        for (let index = currentFileIndex() + step; index >= 0 && index < diffFiles.length; index += step) {
            if (!diffFiles[index].classList.contains('is-viewed')) {
                return index;
            }
        }
        return -1;
    };

    // Previous / Next file are disabled at the first and last file not viewed yet.
    const refreshFileNav = () => {
        review.querySelectorAll('.js-file-prev').forEach((button) => { button.disabled = unviewedFileIndex(-1) === -1; });
        review.querySelectorAll('.js-file-next').forEach((button) => { button.disabled = unviewedFileIndex(1) === -1; });
    };

    let fileNavTimer = null;
    window.addEventListener('scroll', () => {
        if (fileNavTimer === null) {
            fileNavTimer = setTimeout(() => {
                fileNavTimer = null;
                refreshFileNav();
            }, 100);
        }
    }, { passive: true });

    const goToUnviewedFile = (step) => {
        const index = unviewedFileIndex(step);
        if (index === -1) {
            return;
        }
        const file = diffFiles[index];
        jumpTarget = index;
        clearTimeout(jumpTimer);
        jumpTimer = setTimeout(() => {
            jumpTarget = null;
            refreshFileNav();
        }, 1100);
        refreshFileNav();
        scrollToFile(file);
        history.replaceState(null, '', `#${file.id}`);
    };

    const setViewed = (file, viewed) => {
        file.classList.toggle('is-viewed', viewed);
        file.classList.toggle('is-collapsed', viewed);
        file.querySelector('.js-viewed').checked = viewed;
        review.querySelector(`.js-tree-link[data-file-index="${file.id.slice(5)}"]`)?.classList.toggle('is-viewed', viewed);
        if (viewed) {
            file.querySelector('.js-changed-since-viewed')?.remove();
        }
        refreshViewedCount();
    };

    review.addEventListener('change', async (event) => {
        const checkbox = event.target.closest('.js-viewed');
        if (!checkbox) {
            return;
        }
        const file = checkbox.closest('.diff-file');
        const viewed = checkbox.checked;
        setViewed(file, viewed);
        if (viewed && file.getBoundingClientRect().top < 0) {
            // The file was scrolled past: keep its header in view after it closes.
            file.scrollIntoView({ block: 'start' });
        }
        try {
            await axios.put(viewedUrl, { path: file.dataset.path, version: file.dataset.version, viewed });
        } catch (e) {
            setViewed(file, !viewed);
            alert(errorMessage(e));
        }
    });

    // --- Full file ------------------------------------------------------------------------------------

    // The text of a diff row, without the comment button and the +/- sign.
    const rowText = (row) => row.querySelector('.code-text')?.textContent ?? '';

    const fullFileRow = (oldNumber, newNumber, text) => {
        const row = document.createElement('tr');
        row.className = 'line line-context';
        row.dataset.side = 'new';
        row.dataset.line = newNumber;
        const cell = (className, content) => Object.assign(document.createElement('td'), { className, textContent: content });
        const code = cell('code', '');
        const add = Object.assign(document.createElement('button'), {
            type: 'button',
            className: 'add-comment js-add-line-comment',
            title: 'Comment on this line',
            textContent: '+',
        });
        code.append(
            add,
            Object.assign(document.createElement('span'), { className: 'sign', textContent: ' ' }),
            Object.assign(document.createElement('span'), { className: 'code-text', textContent: text }),
        );
        row.append(cell('ln', oldNumber), cell('ln', newNumber), code);
        return row;
    };

    // Unchanged lines first..last of the new file (old line = new line + offset), or null when there are none.
    const fullFileGap = (lines, first, last, offset) => {
        if (last < first) {
            return null;
        }
        const tbody = document.createElement('tbody');
        tbody.className = 'diff-expanded';
        for (let number = first; number <= last; number++) {
            tbody.append(fullFileRow(number + offset, number, lines[number - 1] ?? ''));
        }
        return tbody;
    };

    /**
     * Fill in the unchanged lines between the hunks from the new version of the file. They stay in the table, hidden
     * while only the changes are shown, so comments added on them stay put.
     */
    const loadFullFile = async (file) => {
        const { data } = await axios.get(fileUrl, {
            params: { path: file.dataset.path, commit: review.dataset.sourceCommit, repository: review.dataset.sourceRepository },
        });
        const lines = data.lines;

        // The diff must be of this version: its new-side lines are the file's.
        const mismatch = [...file.querySelectorAll('tbody.diff-hunk tr.line-context, tbody.diff-hunk tr.line-add')]
            .some((row) => lines[Number(row.dataset.line) - 1] !== rowText(row));
        if (mismatch) {
            throw new Error('The file in Bitbucket does not match this diff: the pull request may have changed. Reload the page.');
        }

        // A hunk with no lines on a side ("+4,0") starts after that line.
        const start = (tbody, side) => Number(tbody.dataset[`${side}Start`]) + (tbody.dataset[`${side}Count`] === '0' ? 1 : 0);
        const hunks = [...file.querySelectorAll('tbody.diff-hunk')];
        let nextNew = 1;
        let offset = 0;
        hunks.forEach((tbody) => {
            const newStart = start(tbody, 'new');
            const oldStart = start(tbody, 'old');
            const gap = fullFileGap(lines, nextNew, newStart - 1, oldStart - newStart);
            if (gap) {
                tbody.before(gap);
            }
            nextNew = newStart + Number(tbody.dataset.newCount);
            offset = oldStart + Number(tbody.dataset.oldCount) - nextNew;
        });
        const tail = fullFileGap(lines, nextNew, lines.length, offset);
        if (tail) {
            hunks.at(-1).after(tail);
        }
        markChanges(file);
        file.dataset.fullFileLoaded = '1';
        highlightWholeFile(file, lines).catch(() => {
            // Stays highlighted hunk by hunk.
        });
    };

    /**
     * For the new version view: each added line is marked added, or changed when its run of changes also deletes
     * lines (a replacement). Where lines were only deleted, the line after them is marked (or the line before, at the end).
     */
    // Marked row → its change: kind (added, changed, deleted) and the deleted rows (the old version), for the tooltip.
    const changes = new WeakMap();

    const markChanges = (file) => {
        file.querySelectorAll('tbody.diff-hunk').forEach((tbody) => {
            const rows = [...tbody.querySelectorAll(':scope > tr.line:not(.line-meta)')];
            let block = [];
            const finish = (next) => {
                const added = block.filter((row) => row.classList.contains('line-add'));
                const deletedRows = block.filter((row) => row.classList.contains('line-del'));
                const deleted = deletedRows.length > 0;
                const change = { kind: deleted ? 'changed' : 'added', deletedRows };
                added.forEach((row) => {
                    row.classList.add(deleted ? 'mark-changed' : 'mark-added');
                    changes.set(row, change);
                });
                if (deleted && !added.length) {
                    const marked = next ?? rows.findLast((row) => !row.classList.contains('line-del'));
                    marked?.classList.add(next ? 'mark-deleted-before' : 'mark-deleted-after');
                    if (marked) {
                        changes.set(marked, { kind: next ? 'deleted-before' : 'deleted-after', deletedRows });
                    }
                }
                block = [];
            };
            rows.forEach((row) => {
                if (row.classList.contains('line-add') || row.classList.contains('line-del')) {
                    block.push(row);
                } else {
                    finish(row);
                }
            });
            finish(null);
        });
    };

    // Hovering a change marker's line number in the new version view shows the old version of those lines.
    const changeTooltip = Object.assign(document.createElement('div'), { className: 'change-tooltip', hidden: true });
    document.body.append(changeTooltip);
    const TOOLTIP_LINES = 30;

    const showChangeTooltip = (cell, change) => {
        const title = {
            added: 'Added: no old version',
            changed: 'Old version',
            'deleted-before': 'Deleted above this line',
            'deleted-after': 'Deleted below this line',
        }[change.kind];
        const heading = Object.assign(document.createElement('div'), { className: 'change-tooltip-title', textContent: title });
        const lines = change.deletedRows.slice(0, TOOLTIP_LINES).map((row) => {
            const line = document.createElement('div');
            line.className = 'change-tooltip-line';
            line.append(
                Object.assign(document.createElement('span'), { className: 'ln', textContent: row.dataset.line }),
                // The deleted line as shown in the diff, highlighted if it is by now.
                row.querySelector('.code-text').cloneNode(true),
            );
            return line;
        });
        const more = change.deletedRows.length - TOOLTIP_LINES;
        if (more > 0) {
            lines.push(Object.assign(document.createElement('div'), { className: 'change-tooltip-more', textContent: `… ${more} more` }));
        }
        changeTooltip.replaceChildren(heading, ...lines);
        changeTooltip.classList.toggle('is-added', change.kind === 'added');
        changeTooltip.hidden = false;

        // Right of the line number, aligned with the line, kept on screen.
        const rect = cell.getBoundingClientRect();
        const width = Math.min(changeTooltip.offsetWidth, window.innerWidth - 16);
        const height = changeTooltip.offsetHeight;
        const left = Math.min(rect.right + 6, window.innerWidth - width - 8);
        let top = change.kind === 'deleted-before' ? rect.top - height : rect.top;
        top = Math.max(8, Math.min(top, window.innerHeight - height - 8));
        changeTooltip.style.left = `${Math.max(8, left)}px`;
        changeTooltip.style.top = `${top}px`;
    };

    const markerCell = (target) => {
        const cell = target.closest?.('.diff-file.is-new-file tr.line > td.ln:nth-child(2)');
        const change = cell && changes.get(cell.parentElement);
        return change ? { cell, change } : null;
    };

    review.addEventListener('mouseover', (event) => {
        const marker = markerCell(event.target);
        if (marker) {
            showChangeTooltip(marker.cell, marker.change);
        }
    });
    review.addEventListener('mouseout', (event) => {
        if (markerCell(event.target) && !markerCell(event.relatedTarget ?? document.body)) {
            changeTooltip.hidden = true;
        }
    });
    window.addEventListener('scroll', () => { changeTooltip.hidden = true; }, { passive: true });

    // 'diff' (only the changes), 'full' (the whole file with the changes in it) or 'new' (only the new version).
    const fileView = (file) => file.classList.contains('is-new-file') ? 'new' : (file.classList.contains('is-full-file') ? 'full' : 'diff');

    const setFileView = (file, view) => {
        file.classList.toggle('is-full-file', view === 'full');
        file.classList.toggle('is-new-file', view === 'new');
        file.querySelectorAll('.js-file-view').forEach((button) => {
            const active = button.dataset.view === view;
            button.classList.toggle('is-active-view', active);
            button.setAttribute('aria-pressed', String(active));
        });
    };

    // Clicking the view that is on goes back to only the changes.
    const toggleFileView = async (button) => {
        const file = button.closest('.diff-file');
        const error = file.querySelector('.js-full-file-error');
        const view = button.dataset.view;
        file.classList.remove('is-collapsed');
        if (fileView(file) === view) {
            setFileView(file, 'diff');
            return;
        }
        if (!file.dataset.fullFileLoaded) {
            button.classList.add('is-loading');
            error.hidden = true;
            try {
                await loadFullFile(file);
            } catch (e) {
                error.textContent = errorMessage(e);
                error.hidden = false;
                return;
            } finally {
                button.classList.remove('is-loading');
            }
        }
        setFileView(file, view);
    };

    // --- Files ------------------------------------------------------------------------------------

    review.addEventListener('click', (event) => {
        const target = event.target;
        const action = (selector) => target.closest(selector);

        if (action('.js-add-line-comment')) {
            addLineComment(target.closest('tr.line'));
        } else if (action('.js-file-view')) {
            toggleFileView(action('.js-file-view'));
        } else if (action('.js-add-file-comment')) {
            addFileComment(target.closest('.diff-file'));
        } else if (action('.js-add-general-comment')) {
            addGeneralComment(action('.js-add-general-comment'));
        } else if (action('.js-resolve')) {
            setResolved(action('.js-resolve'));
        } else if (action('.js-expand-thread')) {
            const thread = target.closest('.thread');
            expandThread(thread, !thread.classList.contains('is-expanded'));
        } else if (action('.js-reply')) {
            reply(target.closest('.thread'));
        } else if (action('.js-edit-comment')) {
            editComment(target.closest('.comment'));
        } else if (action('.js-delete-comment')) {
            deleteComment(target.closest('.comment'));
        } else if (action('.js-publish')) {
            publish(action('.js-publish'));
        } else if (action('.js-file-next')) {
            goToUnviewedFile(1);
        } else if (action('.js-file-prev')) {
            goToUnviewedFile(-1);
        } else if (action('.js-draft-next')) {
            goToDraft(1);
        } else if (action('.js-draft-prev')) {
            goToDraft(-1);
        } else if (action('.js-drafts-list a')) {
            event.preventDefault();
            const link = action('.js-drafts-list a');
            currentDraft = Number(link.dataset.draftIndex);
            showComment(document.getElementById(link.getAttribute('href').slice(1)));
        } else if (action('.js-threads-list a')) {
            event.preventDefault();
            const link = action('.js-threads-list a');
            showThread(review.querySelector(`.thread[data-thread-id="${link.dataset.threadId}"]`));
        } else if (action('.js-toggle-file')) {
            target.closest('.diff-file').classList.toggle('is-collapsed');
        } else if (action('.js-expand-all')) {
            review.querySelectorAll('.diff-file').forEach((file) => file.classList.remove('is-collapsed'));
        } else if (action('.js-collapse-all')) {
            review.querySelectorAll('.diff-file').forEach((file) => file.classList.add('is-collapsed'));
        } else if (action('.js-tree-link')) {
            event.preventDefault();
            const file = document.getElementById(`file-${action('.js-tree-link').dataset.fileIndex}`);
            scrollToFile(file);
            history.replaceState(null, '', `#${file.id}`);
        }
    });

    // Ignoring whitespace reloads the page: stay at the same file.
    const whitespaceToggle = review.querySelector('.js-toggle-whitespace');
    whitespaceToggle?.addEventListener('click', (event) => {
        event.preventDefault();
        location.href = whitespaceToggle.href + location.hash;
    });

    // j / k jump between drafts, n / p between files not viewed yet, [ / ] hide or show the side columns,
    // w ignores whitespace or shows it again (when not typing).
    document.addEventListener('keydown', (event) => {
        if (event.target.closest('input, textarea, select, [contenteditable]') || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        if (event.key === 'j') {
            goToDraft(1);
        } else if (event.key === 'k') {
            goToDraft(-1);
        } else if (event.key === 'n') {
            goToUnviewedFile(1);
        } else if (event.key === 'p') {
            goToUnviewedFile(-1);
        } else if (event.key === '[') {
            togglePane('left');
        } else if (event.key === ']') {
            togglePane('right');
        } else if (event.key === 'w') {
            whitespaceToggle?.click();
        }
    });

    // Highlight the file in the tree that is currently on screen.
    const treeLinks = new Map([...review.querySelectorAll('.js-tree-link')].map((link) => [`file-${link.dataset.fileIndex}`, link]));
    const observer = new IntersectionObserver((entries) => {
        entries.forEach((entry) => {
            if (entry.isIntersecting) {
                treeLinks.forEach((link) => link.classList.remove('is-current'));
                treeLinks.get(entry.target.id)?.classList.add('is-current');
            }
        });
    }, { rootMargin: '-10% 0px -80% 0px' });
    review.querySelectorAll('.diff-file').forEach((file) => observer.observe(file));

    // --- Approve / Request changes -------------------------------------------------------------------

    const setReviewState = async (button) => {
        const state = button.dataset.state;
        if (state !== 'none') {
            const unsent = drafts().length;
            const pending = review.querySelectorAll('.comment.is-bitbucket-draft').length;
            const warnings = [];
            if (unsent) {
                warnings.push(`${unsent} ${unsent === 1 ? 'comment is' : 'comments are'} not sent yet and stay here.`);
            }
            if (pending) {
                warnings.push(`${pending} ${pending === 1 ? 'comment is' : 'comments are'} in your draft review in Bitbucket, which others see only after you finish the review there.`);
            }
            const action = state === 'approved' ? 'Approve' : 'Request changes';
            if (warnings.length && !confirm(`${warnings.join(' ')}\n\n${action} anyway?`)) {
                return;
            }
        }

        const actions = review.querySelector('.js-review-actions');
        actions.querySelectorAll('button').forEach((other) => { other.disabled = true; });
        button.classList.add('is-loading');
        try {
            const { data } = await axios.put(reviewStateUrl, { state });
            actions.replaceWith(htmlToElement(data.actions));
            review.querySelector('.js-participants').replaceWith(htmlToElement(data.participants));
        } catch (e) {
            alert(errorMessage(e));
            button.classList.remove('is-loading');
            actions.querySelectorAll('button').forEach((other) => { other.disabled = false; });
        }
    };

    review.querySelector('.review-header').addEventListener('click', (event) => {
        const button = event.target.closest('.js-review-state');
        if (button) {
            setReviewState(button);
        }
    });

    // --- Resizable side columns -----------------------------------------------------------------------

    const layout = review.querySelector('.review-layout');
    const MIN_SIDE = 180;
    const MIN_MAIN = 400;
    const GAP = 16;
    const sideWidth = (side) => parseFloat(getComputedStyle(layout).getPropertyValue(`--review-${side}`));
    const storageKey = (side) => `review.width.${side}`;

    const setSideWidth = (side, width) => {
        const other = side === 'left' ? 'right' : 'left';
        // The other column takes no room when hidden, or (the right one) below the diff on narrower screens.
        const otherWidth = getComputedStyle(review.querySelector(`.review-resizer-${other}`)).display === 'none'
            ? 0
            : sideWidth(other);
        const max = Math.max(MIN_SIDE, layout.clientWidth - otherWidth - MIN_MAIN - 2 * GAP);
        const clamped = Math.round(Math.min(Math.max(width, MIN_SIDE), max));
        layout.style.setProperty(`--review-${side}`, `${clamped}px`);
        return clamped;
    };

    const saveSideWidth = (side) => {
        try {
            localStorage.setItem(storageKey(side), String(sideWidth(side)));
        } catch {
            // Storage blocked: the width lasts until the page is reloaded.
        }
    };

    ['left', 'right'].forEach((side) => {
        try {
            const saved = Number(localStorage.getItem(storageKey(side)));
            if (saved > 0) {
                setSideWidth(side, saved);
            }
        } catch {
            // Storage blocked: default widths.
        }
    });

    review.querySelectorAll('.js-resizer').forEach((handle) => {
        const side = handle.dataset.side;

        handle.addEventListener('pointerdown', (event) => {
            if (event.button !== 0) {
                return;
            }
            event.preventDefault();
            handle.setPointerCapture(event.pointerId);
            handle.classList.add('is-dragging');
            document.body.classList.add('is-resizing-review');
        });

        handle.addEventListener('pointermove', (event) => {
            if (!handle.hasPointerCapture(event.pointerId)) {
                return;
            }
            const rect = layout.getBoundingClientRect();
            // The pointer sits in the middle of the gap next to the column.
            setSideWidth(side, side === 'left' ? event.clientX - rect.left - GAP / 2 : rect.right - event.clientX - GAP / 2);
        });

        const stop = (event) => {
            if (!handle.hasPointerCapture(event.pointerId)) {
                return;
            }
            handle.releasePointerCapture(event.pointerId);
            handle.classList.remove('is-dragging');
            document.body.classList.remove('is-resizing-review');
            saveSideWidth(side);
        };
        handle.addEventListener('pointerup', stop);
        handle.addEventListener('pointercancel', stop);

        handle.addEventListener('dblclick', () => {
            layout.style.removeProperty(`--review-${side}`);
            try {
                localStorage.removeItem(storageKey(side));
            } catch {
                // Nothing saved.
            }
        });

        handle.addEventListener('keydown', (event) => {
            const step = event.shiftKey ? 64 : 16;
            // Arrows move the handle: right widens the left column and narrows the right one.
            const direction = { ArrowLeft: -1, ArrowRight: 1 }[event.key];
            if (!direction) {
                return;
            }
            event.preventDefault();
            setSideWidth(side, sideWidth(side) + (side === 'left' ? direction : -direction) * step);
            saveSideWidth(side);
        });
    });

    // --- Hiding the side columns ---------------------------------------------------------------------

    const paneKey = (side) => `review.hidden.${side}`;

    const setPaneHidden = (side, hidden) => {
        layout.classList.toggle(`hide-${side}`, hidden);
        const button = review.querySelector(`.js-toggle-pane[data-side="${side}"]`);
        button.classList.toggle('is-selected', !hidden);
        button.setAttribute('aria-pressed', String(!hidden));
    };

    const togglePane = (side) => {
        const hidden = !layout.classList.contains(`hide-${side}`);
        setPaneHidden(side, hidden);
        try {
            localStorage.setItem(paneKey(side), hidden ? '1' : '0');
        } catch {
            // Not remembered.
        }
    };

    ['left', 'right'].forEach((side) => {
        let hidden = false;
        try {
            hidden = localStorage.getItem(paneKey(side)) === '1';
        } catch {
            // Storage blocked: shown.
        }
        setPaneHidden(side, hidden);
    });

    review.querySelectorAll('.js-toggle-pane').forEach((button) => {
        button.addEventListener('click', () => togglePane(button.dataset.side));
    });

    // --- Heading on top ------------------------------------------------------------------------------

    // Its height moves the file headers and side columns below it; compact once the page is scrolled.
    const header = review.querySelector('.review-header');
    new ResizeObserver(() => {
        review.style.setProperty('--review-header-height', `${header.offsetHeight}px`);
    }).observe(header);
    new IntersectionObserver(([entry]) => {
        header.classList.toggle('is-stuck', !entry.isIntersecting);
    }).observe(review.querySelector('.review-header-sentinel'));

    refreshDrafts();
    refreshViewedCount();
}

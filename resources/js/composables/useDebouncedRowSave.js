import { reactive } from 'vue';

/**
 * Batches cell edits per row and flushes them once the row has been quiet for `delay` ms.
 *
 * The unit of work is the row, not the keystroke and not the cell: typing in three cells of
 * the same line sends one PATCH carrying all three, while editing two different lines keeps
 * two independent timers. That matches how the grid is actually used - fill a line across,
 * move on - and keeps the request count proportional to lines touched.
 *
 * Optimism is the caller's job (it mutates its own row objects); this composable owns the
 * network side and hands back the previous values when a save fails so the caller can roll
 * the row back.
 */
export function useDebouncedRowSave({ endpoint, delay = 500, onError }) {
    /** rowId -> { timer, pending: {field: newValue}, rollback: {field: valueBeforeFirstEdit} } */
    const batches = new Map();

    /** rowId -> 'saving' | 'saved' | 'error', for the row status indicator. */
    const status = reactive({});

    function queue(rowId, field, newValue, previousValue) {
        let batch = batches.get(rowId);

        if (!batch) {
            batch = { timer: null, pending: {}, rollback: {} };
            batches.set(rowId, batch);
        }

        // Keep the value from *before* the first un-flushed edit: that is what a rollback
        // has to restore, not the value from the keystroke before last.
        if (!(field in batch.rollback)) {
            batch.rollback[field] = previousValue;
        }

        batch.pending[field] = newValue;

        clearTimeout(batch.timer);
        batch.timer = setTimeout(() => flush(rowId), delay);
    }

    async function flush(rowId) {
        const batch = batches.get(rowId);

        if (!batch || Object.keys(batch.pending).length === 0) {
            return;
        }

        // Detach the batch before awaiting: edits made while the request is in flight must
        // accumulate into a fresh batch rather than be dropped or sent twice.
        const payload = batch.pending;
        const rollback = batch.rollback;
        batches.delete(rowId);
        clearTimeout(batch.timer);

        status[rowId] = 'saving';

        try {
            const body = await patch(`${endpoint}/${rowId}`, payload);

            status[rowId] = 'saved';

            return body.data;
        } catch (error) {
            status[rowId] = 'error';

            onError?.({ rowId, rollback, message: error.message });

            return null;
        }
    }

    /** Flush everything still pending - on unmount, or before navigating away. */
    function flushAll() {
        return Promise.all([...batches.keys()].map((rowId) => flush(rowId)));
    }

    function hasPending() {
        return batches.size > 0;
    }

    return { queue, flush, flushAll, hasPending, status };
}

/**
 * Session-authenticated JSON PATCH. Inertia v3 no longer bundles axios, so rather than add
 * a dependency for one call this uses fetch and supplies CSRF itself: Laravel refreshes the
 * XSRF-TOKEN cookie on every web response, which makes it a better source than a meta tag
 * baked into the initial document (the grid is long-lived and the token can rotate).
 */
async function patch(url, payload) {
    const response = await fetch(url, {
        method: 'PATCH',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify(payload),
    });

    if (response.ok) {
        return response.json();
    }

    throw new Error(await failureMessage(response));
}

function csrfToken() {
    const cookie = document.cookie.split('; ').find((entry) => entry.startsWith('XSRF-TOKEN='));

    if (cookie) {
        return decodeURIComponent(cookie.slice('XSRF-TOKEN='.length));
    }

    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

async function failureMessage(response) {
    if (response.status === 419) {
        return 'Session expirée — rechargez la page';
    }

    let body = null;

    try {
        body = await response.json();
    } catch {
        return `Sauvegarde impossible (HTTP ${response.status})`;
    }

    if (body?.errors) {
        // Validation: the first field message is the actionable one.
        return Object.values(body.errors).flat()[0];
    }

    return body?.message ?? `Sauvegarde impossible (HTTP ${response.status})`;
}

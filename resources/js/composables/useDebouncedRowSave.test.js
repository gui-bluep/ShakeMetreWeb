import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useDebouncedRowSave } from './useDebouncedRowSave';

/**
 * The grid's non-negotiables live here: one grouped request per row rather than one per
 * keystroke, and a rollback that restores the value from before the failed batch.
 */
describe('useDebouncedRowSave', () => {
    let requests;

    beforeEach(() => {
        vi.useFakeTimers();
        requests = [];

        global.document = { cookie: 'XSRF-TOKEN=test-token', querySelector: () => null };

        global.fetch = vi.fn((url, options) => {
            requests.push({ url, method: options.method, body: JSON.parse(options.body), headers: options.headers });

            return Promise.resolve({
                ok: true,
                status: 200,
                json: () => Promise.resolve({ data: { id: 'row-1', echoed: true } }),
            });
        });
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    const save = (overrides = {}) =>
        useDebouncedRowSave({ endpoint: '/api/metre-lines', delay: 500, ...overrides });

    it('sends nothing before the delay has elapsed', async () => {
        const { queue } = save();

        queue('row-1', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(499);

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('coalesces several edits of one row into a single request', async () => {
        const { queue } = save();

        // Typing "150" one character at a time is three edits, not three requests.
        queue('row-1', 'price_sales', 1, null);
        await vi.advanceTimersByTimeAsync(100);
        queue('row-1', 'price_sales', 15, 1);
        await vi.advanceTimersByTimeAsync(100);
        queue('row-1', 'price_sales', 150, 15);
        await vi.advanceTimersByTimeAsync(500);

        expect(requests).toHaveLength(1);
        expect(requests[0].body).toEqual({ price_sales: 150 });
    });

    it('groups different fields of the same row into one payload', async () => {
        const { queue } = save();

        queue('row-1', 'quantity', 3, 1);
        queue('row-1', 'price_sales', 150, 100);
        queue('row-1', 'is_option_b', true, false);
        await vi.advanceTimersByTimeAsync(500);

        expect(requests).toHaveLength(1);
        expect(requests[0].body).toEqual({ quantity: 3, price_sales: 150, is_option_b: true });
        expect(requests[0].method).toBe('PATCH');
        expect(requests[0].url).toBe('/api/metre-lines/row-1');
    });

    it('keeps a separate timer per row', async () => {
        const { queue } = save();

        queue('row-1', 'quantity', 2, 1);
        queue('row-2', 'quantity', 5, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(requests).toHaveLength(2);
        expect(requests.map((r) => r.url).sort()).toEqual([
            '/api/metre-lines/row-1',
            '/api/metre-lines/row-2',
        ]);
    });

    it('resets the timer on each new edit rather than firing mid-typing', async () => {
        const { queue } = save();

        for (let i = 0; i < 5; i++) {
            queue('row-1', 'description', `text ${i}`, `text ${i - 1}`);
            await vi.advanceTimersByTimeAsync(400);
        }

        expect(global.fetch).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);
        expect(requests).toHaveLength(1);
        expect(requests[0].body).toEqual({ description: 'text 4' });
    });

    it('sends the CSRF token from the cookie', async () => {
        const { queue } = save();

        queue('row-1', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(requests[0].headers['X-XSRF-TOKEN']).toBe('test-token');
    });

    it('reports the value from before the first un-flushed edit, not the previous keystroke', async () => {
        const rollbacks = [];
        global.fetch = vi.fn(() =>
            Promise.resolve({ ok: false, status: 422, json: () => Promise.resolve({ errors: { quantity: ['Nope'] } }) })
        );

        const { queue } = save({ onError: ({ rollback }) => rollbacks.push(rollback) });

        // Original value is 1; the user types 2 then 3. A rollback must restore 1.
        queue('row-1', 'quantity', 2, 1);
        queue('row-1', 'quantity', 3, 2);
        await vi.advanceTimersByTimeAsync(500);

        expect(rollbacks).toEqual([{ quantity: 1 }]);
    });

    it('surfaces the first validation message', async () => {
        const messages = [];
        global.fetch = vi.fn(() =>
            Promise.resolve({
                ok: false,
                status: 422,
                json: () => Promise.resolve({ errors: { quantity: ['La quantité est invalide'] } }),
            })
        );

        const { queue } = save({ onError: ({ message }) => messages.push(message) });

        queue('row-1', 'quantity', -1, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(messages).toEqual(['La quantité est invalide']);
    });

    it('explains an expired session rather than showing a bare status code', async () => {
        const messages = [];
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 419, json: () => Promise.resolve({}) }));

        const { queue } = save({ onError: ({ message }) => messages.push(message) });

        queue('row-1', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(messages[0]).toContain('Session expirée');
    });

    it('tracks per-row status through a successful save', async () => {
        const { queue, status } = save();

        queue('row-1', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(status['row-1']).toBe('saved');
    });

    it('marks only the failing row as errored', async () => {
        global.fetch = vi.fn((url) =>
            Promise.resolve(
                url.endsWith('row-2')
                    ? { ok: false, status: 500, json: () => Promise.resolve({ message: 'boom' }) }
                    : { ok: true, status: 200, json: () => Promise.resolve({ data: {} }) }
            )
        );

        const { queue, status } = save({ onError: () => {} });

        queue('row-1', 'quantity', 2, 1);
        queue('row-2', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(500);

        expect(status['row-1']).toBe('saved');
        expect(status['row-2']).toBe('error');
    });

    it('does not lose an edit made while a request is in flight', async () => {
        let release;
        global.fetch = vi.fn(
            () =>
                new Promise((resolve) => {
                    release = () =>
                        resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: {} }) });
                })
        );

        const { queue } = save();

        queue('row-1', 'quantity', 2, 1);
        await vi.advanceTimersByTimeAsync(500);

        // Still awaiting the first response; this edit must start a fresh batch.
        queue('row-1', 'price_sales', 99, 50);
        release();
        await vi.advanceTimersByTimeAsync(500);

        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('flushes everything still pending on demand', async () => {
        const { queue, flushAll, hasPending } = save();

        queue('row-1', 'quantity', 2, 1);
        queue('row-2', 'quantity', 3, 1);
        expect(hasPending()).toBe(true);

        await flushAll();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(hasPending()).toBe(false);
    });
});

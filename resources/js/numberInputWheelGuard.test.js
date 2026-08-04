import { describe, expect, it, vi } from 'vitest';
import { installNumberInputWheelGuard, swallowsWheel } from './numberInputWheelGuard';

const input = (type) => ({ tagName: 'INPUT', type, blur: vi.fn() });

/** A stand-in document: records the listener it is given and lets a test fire a wheel event at it. */
function fakeRoot() {
    const root = {
        activeElement: null,
        listener: null,
        options: null,
        addEventListener(name, listener, options) {
            expect(name).toBe('wheel');
            root.listener = listener;
            root.options = options;
        },
        wheelOver(target) {
            root.listener({ target });
        },
    };

    return root;
}

describe('swallowsWheel', () => {
    it('is true only for the number input that currently has focus', () => {
        const number = input('number');

        expect(swallowsWheel(number, number)).toBe(true);
    });

    it('leaves an unfocused number input alone — it scrolls the page by itself', () => {
        expect(swallowsWheel(input('number'), input('number'))).toBe(false);
        expect(swallowsWheel(input('number'), null)).toBe(false);
    });

    it('leaves the other field types alone: only number reacts to the wheel', () => {
        for (const type of ['text', 'search', 'checkbox', 'date', 'email']) {
            const field = input(type);

            expect(swallowsWheel(field, field)).toBe(false);
        }
    });

    it('ignores anything that is not an input', () => {
        const div = { tagName: 'DIV', type: 'number' };

        expect(swallowsWheel(div, div)).toBe(false);
        expect(swallowsWheel(null, null)).toBe(false);
        expect(swallowsWheel(undefined, null)).toBe(false);
    });
});

describe('installNumberInputWheelGuard', () => {
    it('blurs the focused number input under the cursor, so the wheel scrolls instead of editing', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        const qty = input('number');
        root.activeElement = qty;
        root.wheelOver(qty);

        expect(qty.blur).toHaveBeenCalledTimes(1);
    });

    it('does not touch a field the wheel would not have edited', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        const title = input('text');
        root.activeElement = title;
        root.wheelOver(title);

        const unfocused = input('number');
        root.activeElement = null;
        root.wheelOver(unfocused);

        expect(title.blur).not.toHaveBeenCalled();
        expect(unfocused.blur).not.toHaveBeenCalled();
    });

    /**
     * Both flags are load-bearing: capture so a component's stopPropagation cannot disarm the
     * guard, passive because it must never cancel the scroll it exists to allow.
     */
    it('listens in the capture phase, passively', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        expect(root.options).toEqual({ capture: true, passive: true });
    });
});

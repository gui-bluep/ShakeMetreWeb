import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    installNumberInputWheelGuard,
    isNumberInput,
    scrollInsteadOfEditing,
    scrollTargetFor,
    wheelPixels,
} from './numberInputWheelGuard';

const input = (type) => ({ tagName: 'INPUT', type, addEventListener: vi.fn(), removeEventListener: vi.fn() });

/** A stand-in document: records its listeners and lets a test fire focusin/focusout at them. */
function fakeRoot() {
    const listeners = {};

    return {
        listeners,
        addEventListener(name, listener) {
            listeners[name] = listener;
        },
        fire(name, target) {
            listeners[name]({ target });
        },
    };
}

/**
 * A scrollable box, as the guard reads one. `overflow` and the room left are the only two things
 * that decide whether the wheel stops here or carries on up.
 */
function fakeBox({ overflow = 'auto', top = 0, maxTop = 800, left = 0, maxLeft = 0 } = {}) {
    return {
        overflow,
        parentElement: null,
        scrollTop: top,
        clientHeight: 200,
        scrollHeight: 200 + maxTop,
        scrollLeft: left,
        clientWidth: 400,
        scrollWidth: 400 + maxLeft,
        scrollBy: vi.fn(),
    };
}

/** Chains elements child → parent and hands out each one's declared overflow. */
function chain(...nodes) {
    nodes.forEach((node, i) => { node.parentElement = nodes[i + 1] ?? null; });

    return (node) => ({ overflowY: node.overflow ?? 'visible', overflowX: node.overflow ?? 'visible' });
}

const wheel = (currentTarget, { deltaX = 0, deltaY = 0, deltaMode = 0 } = {}) => ({
    currentTarget,
    deltaX,
    deltaY,
    deltaMode,
    preventDefault: vi.fn(),
});

afterEach(() => { delete globalThis.window; delete globalThis.getComputedStyle; });

describe('isNumberInput', () => {
    it('is true for a number input and for nothing else', () => {
        expect(isNumberInput(input('number'))).toBe(true);

        for (const type of ['text', 'search', 'checkbox', 'date', 'email']) {
            expect(isNumberInput(input(type))).toBe(false);
        }

        expect(isNumberInput({ tagName: 'DIV', type: 'number' })).toBe(false);
        expect(isNumberInput(null)).toBe(false);
        expect(isNumberInput(undefined)).toBe(false);
    });
});

describe('installNumberInputWheelGuard', () => {
    /**
     * Armed on the hovered field and on nothing else: a wheel event goes to the element under the
     * cursor, so that is the one that can spend it on the value — focused or not. And a non-passive
     * wheel listener takes scrolling off the compositor's fast path for everything it covers, the
     * heaviest screen here being a several-hundred-line grid.
     */
    it('arms a cancelling wheel listener on the number input under the pointer', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        const qty = input('number');
        root.fire('pointerover', qty);

        expect(qty.addEventListener).toHaveBeenCalledWith('wheel', scrollInsteadOfEditing, { passive: false });
    });

    it('disarms it when the pointer leaves', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        const qty = input('number');
        root.fire('pointerover', qty);
        root.fire('pointerout', qty);

        expect(qty.removeEventListener).toHaveBeenCalledWith('wheel', scrollInsteadOfEditing);
    });

    it('leaves the other field types alone: only a number input edits itself on a wheel tick', () => {
        const root = fakeRoot();
        installNumberInputWheelGuard(root);

        const title = input('text');
        root.fire('pointerover', title);
        root.fire('pointerout', title);

        expect(title.addEventListener).not.toHaveBeenCalled();
        expect(title.removeEventListener).not.toHaveBeenCalled();
    });
});

describe('scrollTargetFor', () => {
    it('picks the nearest ancestor that scrolls the way the wheel is going', () => {
        const box = fakeBox({ top: 100 });
        const row = { overflow: 'visible' };
        const field = {};
        const style = chain(field, row, box);

        expect(scrollTargetFor(field, 0, 120, style)).toBe(box);
    });

    /**
     * The room check is what reproduces the browser's chaining: a list scrolled to its last line
     * hands the wheel to whatever is behind it instead of swallowing it. Verified in a real
     * Chromium as well - the window scrolled while the container stayed at its end.
     */
    it('carries on past a box with nothing left in that direction', () => {
        const full = fakeBox({ top: 800, maxTop: 800 });
        const outer = fakeBox({ top: 0, maxTop: 500 });
        const field = {};
        const style = chain(field, full, outer);

        expect(scrollTargetFor(field, 0, 120, style)).toBe(outer);
        // Upwards the inner box has all its room, so the wheel stops there.
        expect(scrollTargetFor(field, 0, -120, style)).toBe(full);
    });

    it('ignores a box that merely overflows without being scrollable', () => {
        const hidden = fakeBox({ overflow: 'hidden' });
        const field = {};
        const style = chain(field, hidden);

        expect(scrollTargetFor(field, 0, 120, style)).toBeNull();
    });

    it('answers null - the window - when nothing between the field and it scrolls', () => {
        const field = {};
        const style = chain(field, { overflow: 'visible' }, { overflow: 'visible' });

        expect(scrollTargetFor(field, 0, 120, style)).toBeNull();
    });

    it('follows the axis asked for', () => {
        const sideways = fakeBox({ maxTop: 0, maxLeft: 900 });
        const field = {};
        const style = chain(field, sideways);

        expect(scrollTargetFor(field, 120, 0, style)).toBe(sideways);
        expect(scrollTargetFor(field, 0, 120, style)).toBeNull();
    });
});

describe('wheelPixels', () => {
    it('takes pixel deltas as they come', () => {
        expect(wheelPixels(wheel(null, { deltaX: 3, deltaY: -120, deltaMode: 0 }))).toEqual({ x: 3, y: -120 });
    });

    /** Firefox reports a mouse tick in lines; read as pixels it would scroll a list by three. */
    it('turns lines and pages into pixels', () => {
        expect(wheelPixels(wheel(null, { deltaY: 3, deltaMode: 1 }))).toEqual({ x: 0, y: 48 });
        expect(wheelPixels(wheel(null, { deltaY: 1, deltaMode: 2 }), { clientHeight: 640 })).toEqual({ x: 0, y: 640 });
    });
});

describe('scrollInsteadOfEditing', () => {
    it('cancels the increment and scrolls in its place', () => {
        const box = fakeBox();
        const field = {};
        chain(field, box);
        globalThis.getComputedStyle = () => ({ overflowY: 'auto', overflowX: 'auto' });

        const event = wheel(field, { deltaY: 120 });
        scrollInsteadOfEditing(event);

        expect(event.preventDefault).toHaveBeenCalled();
        expect(box.scrollBy).toHaveBeenCalledWith(0, 120);
    });

    it('scrolls the window when the field sits in a page that scrolls as a whole', () => {
        const field = {};
        chain(field, { overflow: 'visible' });
        globalThis.getComputedStyle = () => ({ overflowY: 'visible', overflowX: 'visible' });
        globalThis.window = { scrollBy: vi.fn(), innerHeight: 800 };

        const event = wheel(field, { deltaY: 90 });
        scrollInsteadOfEditing(event);

        expect(event.preventDefault).toHaveBeenCalled();
        expect(globalThis.window.scrollBy).toHaveBeenCalledWith(0, 90);
    });
});

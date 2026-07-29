import { describe, expect, it, vi } from 'vitest';
import { useGridKeyboardNav } from './useGridKeyboardNav';

const COLUMNS = [{ key: 'a' }, { key: 'b' }, { key: 'c' }];

function nav(rows = 10) {
    const scrolled = [];
    const api = useGridKeyboardNav({
        rowCount: () => rows,
        columns: COLUMNS,
        onRequestScroll: (row) => scrolled.push(row),
    });

    return { ...api, scrolled };
}

/** A select or checkbox: no caret, so arrows always navigate. */
const selectAt = () => ({ tagName: 'SELECT', blur: vi.fn() });

/** A text input with the caret at a given offset in `value`. */
const textAt = (value, caret) => ({
    tagName: 'INPUT',
    type: 'text',
    value,
    selectionStart: caret,
    selectionEnd: caret,
    blur: vi.fn(),
});

function press(api, key, position, target, shiftKey = false) {
    return api.handleKeydown({ key, shiftKey, target }, position);
}

describe('useGridKeyboardNav', () => {
    it('starts at the first cell when nothing is focused', () => {
        const api = nav();

        press(api, 'ArrowDown', { row: 0, col: 0 }, selectAt());

        expect(api.cursor.value).toEqual({ row: 0, col: 0 });
    });

    it('moves down and up between rows', () => {
        const api = nav();

        api.focusCell(3, 1);
        press(api, 'ArrowDown', { row: 3, col: 1 }, selectAt());
        expect(api.cursor.value).toEqual({ row: 4, col: 1 });

        press(api, 'ArrowUp', { row: 4, col: 1 }, selectAt());
        expect(api.cursor.value).toEqual({ row: 3, col: 1 });
    });

    it('refuses to move outside the grid', () => {
        const api = nav(5);

        api.focusCell(0, 0);
        press(api, 'ArrowUp', { row: 0, col: 0 }, selectAt());
        expect(api.cursor.value).toEqual({ row: 0, col: 0 });

        api.focusCell(4, 2);
        press(api, 'ArrowDown', { row: 4, col: 2 }, selectAt());
        press(api, 'ArrowRight', { row: 4, col: 2 }, selectAt());
        expect(api.cursor.value).toEqual({ row: 4, col: 2 });
    });

    it('lets the caret move inside a text cell instead of hijacking the arrow', () => {
        const api = nav();
        api.focusCell(2, 1);

        // Caret in the middle of "hello": the arrow belongs to the input.
        const consumed = press(api, 'ArrowLeft', { row: 2, col: 1 }, textAt('hello', 3));

        expect(consumed).toBe(false);
        expect(api.cursor.value).toEqual({ row: 2, col: 1 });
    });

    it('leaves a text cell once the caret reaches its edge', () => {
        const api = nav();
        api.focusCell(2, 1);

        expect(press(api, 'ArrowLeft', { row: 2, col: 1 }, textAt('hello', 0))).toBe(true);
        expect(api.cursor.value).toEqual({ row: 2, col: 0 });

        api.focusCell(2, 1);
        expect(press(api, 'ArrowRight', { row: 2, col: 1 }, textAt('hello', 5))).toBe(true);
        expect(api.cursor.value).toEqual({ row: 2, col: 2 });
    });

    it('wraps Tab to the next row at the end of a row', () => {
        const api = nav();
        api.focusCell(1, COLUMNS.length - 1);

        press(api, 'Tab', { row: 1, col: COLUMNS.length - 1 }, selectAt());

        expect(api.cursor.value).toEqual({ row: 2, col: 0 });
    });

    it('wraps Shift+Tab back to the previous row', () => {
        const api = nav();
        api.focusCell(2, 0);

        press(api, 'Tab', { row: 2, col: 0 }, selectAt(), true);

        expect(api.cursor.value).toEqual({ row: 1, col: COLUMNS.length - 1 });
    });

    it('moves Enter down the column, Shift+Enter up', () => {
        const api = nav();
        api.focusCell(4, 2);

        press(api, 'Enter', { row: 4, col: 2 }, selectAt());
        expect(api.cursor.value).toEqual({ row: 5, col: 2 });

        press(api, 'Enter', { row: 5, col: 2 }, selectAt(), true);
        expect(api.cursor.value).toEqual({ row: 4, col: 2 });
    });

    it('releases focus on Escape', () => {
        const api = nav();
        api.focusCell(1, 1);
        const target = selectAt();

        press(api, 'Escape', { row: 1, col: 1 }, target);

        expect(target.blur).toHaveBeenCalled();
        expect(api.cursor.value).toBeNull();
    });

    it('ignores keys it does not own, so typing still reaches the cell', () => {
        const api = nav();
        api.focusCell(1, 1);

        expect(press(api, 'a', { row: 1, col: 1 }, textAt('', 0))).toBe(false);
        expect(api.cursor.value).toEqual({ row: 1, col: 1 });
    });

    it('asks the virtualizer to reveal the target row, which may not be rendered yet', () => {
        const api = nav(300);

        api.focusCell(250, 0);

        // Without this the cell could never take focus: it is outside the rendered window.
        expect(api.scrolled).toContain(250);
    });

    it('reports which cell is focused', () => {
        const api = nav();

        api.focusCell(2, 1);

        expect(api.isFocused(2, 1)).toBe(true);
        expect(api.isFocused(2, 0)).toBe(false);
    });
});

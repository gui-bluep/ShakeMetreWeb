import { ref } from 'vue';

/**
 * Spreadsheet-style cell navigation, for users arriving from FileMaker.
 *
 * The grid is virtualized, so the cell you want to move to may not be in the DOM yet. Focus
 * therefore works off a logical {row, col} cursor: the cursor moves, the caller scrolls the
 * target row into range, and the cell claims focus when it mounts. Anything that walked the
 * DOM directly would break the moment the target row was outside the rendered window.
 */
export function useGridKeyboardNav({ rowCount, columns, onRequestScroll }) {
    /** Logical position, not a DOM reference: { row: index, col: index } or null. */
    const cursor = ref(null);

    function focusCell(row, col) {
        const rows = rowCount();

        if (row < 0 || row >= rows || col < 0 || col >= columns.length) {
            return;
        }

        cursor.value = { row, col };

        // Ask the virtualizer to render the row before the cell tries to take focus.
        onRequestScroll?.(row);
    }

    function isFocused(row, col) {
        return cursor.value?.row === row && cursor.value?.col === col;
    }

    function move(dRow, dCol) {
        if (!cursor.value) {
            return focusCell(0, 0);
        }

        focusCell(cursor.value.row + dRow, cursor.value.col + dCol);
    }

    /**
     * Returns true when the event was consumed, so the caller can preventDefault.
     *
     * Arrow keys inside a text input would normally move the caret, so they only navigate
     * when the caret sits at the edge of the value - otherwise typing in a cell becomes
     * unusable. Tab and Enter always navigate.
     */
    function handleKeydown(event, { row, col }) {
        const target = event.target;
        const isText = target?.tagName === 'INPUT' && target.type === 'text';
        const isNumber = target?.tagName === 'INPUT' && target.type === 'number';
        const atStart = isText ? target.selectionStart === 0 && target.selectionEnd === 0 : true;
        const atEnd = isText
            ? target.selectionStart === target.value.length && target.selectionEnd === target.value.length
            : true;

        switch (event.key) {
            case 'ArrowUp':
                move(-1, 0);

                return true;

            case 'ArrowDown':
                move(1, 0);

                return true;

            case 'ArrowLeft':
                if (!atStart && !isNumber) {
                    return false;
                }
                move(0, -1);

                return true;

            case 'ArrowRight':
                if (!atEnd && !isNumber) {
                    return false;
                }
                move(0, 1);

                return true;

            case 'Tab':
                // Wrap to the next/previous row at the ends, like a spreadsheet.
                if (event.shiftKey) {
                    col === 0 ? focusCell(row - 1, columns.length - 1) : move(0, -1);
                } else {
                    col === columns.length - 1 ? focusCell(row + 1, 0) : move(0, 1);
                }

                return true;

            case 'Enter':
                move(event.shiftKey ? -1 : 1, 0);

                return true;

            case 'Escape':
                target?.blur?.();
                cursor.value = null;

                return true;

            default:
                return false;
        }
    }

    return { cursor, focusCell, isFocused, handleKeydown };
}

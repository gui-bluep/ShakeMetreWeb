/**
 * Keeps the mouse wheel scrolling the list, over a focused `<input type="number">` as anywhere else.
 *
 * Chrome and Firefox treat a wheel tick over a *focused* number input as an increment: the event is
 * spent changing the value and never reaches the scroll container. On the métré grids that is two
 * bugs at once, both silent. The page appears frozen — put the cursor on a Qté or a P.U. cell you
 * have just clicked into and the list will not scroll, while everywhere else it does — and the
 * figure under the cursor moves. Measured in a browser on the Achats/Ventes/Commandes view: one
 * wheel tick over a focused Qté cell took 45 to 44 and the grid's debounced save posted
 * `{"quantity":44}`. Ten small trackpad deltas took a quantity from 1 to -10. Nobody watching the
 * scroll would see it, and it lands on money.
 *
 * **The first cure only fixed half of it, and the half it fixed was the invisible one.** Blurring the
 * field on the wheel event does stop the increment — the browser only increments the *focused*
 * field — but the scroll never came back: the list stayed frozen under the cursor, silently, exactly
 * as reported before the guard existed. The reason is that a wheel gesture *latches*: the browser
 * picks what will consume the gesture on its first event and keeps it there until the gesture ends,
 * so once the focused input has taken that first tick, the rest of the gesture goes on being handed
 * to a field that no longer does anything with it. On a trackpad the gesture never ends while you
 * keep scrolling, which is why nothing moved at all. Blurring cannot fix that, whatever moment it
 * fires at — the choice is already made.
 *
 * So the guard stops relying on the browser to do the scrolling afterwards and does it itself:
 * cancel the event (which is what removes the increment, focus or no focus) and move the scroll
 * container by the event's own deltas. Those deltas already carry the trackpad's momentum — they are
 * what the browser would have applied — so nothing has to be re-implemented but the addition.
 *
 * Two consequences of that choice, both deliberate:
 *
 * - **The cell keeps its focus.** The earlier guard blurred, which flushed the row's pending edit on
 *   every scroll and dropped the keyboard out of the grid. Cancelling the increment makes all of
 *   that unnecessary: you scroll past a cell and come back to it still in edit.
 * - **The listener follows the pointer, because the wheel does.** It is armed on `pointerover` and
 *   removed on `pointerout`, on the field itself rather than on the document: a wheel event is
 *   delivered to the element *under the cursor*, so the hovered field is exactly the one that can
 *   misbehave — whether it holds focus or not, which is what makes this independent of each
 *   browser's rule about when a number field takes the wheel. And a non-passive `wheel` listener
 *   takes scrolling off the compositor's fast path for everything it covers, so it covers one cell
 *   at a time; everywhere else, scrolling stays the browser's business as before.
 *
 * Installed once on the document rather than bound per input: the same field type carries money on
 * five screens (the two grids, the tender comparison, the reference catalogue, the métré page), and
 * a guard that has to be remembered on each new one is a guard that will be forgotten.
 */
export function installNumberInputWheelGuard(root = document) {
    root.addEventListener('pointerover', (event) => {
        if (isNumberInput(event.target)) {
            // `passive: false` is the whole point: this listener cancels, where the previous one
            // deliberately could not. Adding it twice is free — same function, so the second call
            // is a no-op, which is why the handler is a named export and not a closure.
            event.target.addEventListener('wheel', scrollInsteadOfEditing, { passive: false });
        }
    });

    root.addEventListener('pointerout', (event) => {
        if (isNumberInput(event.target)) {
            event.target.removeEventListener('wheel', scrollInsteadOfEditing);
        }
    });
}

/** Duck-typed on `tagName`/`type` rather than `instanceof HTMLInputElement`, so it needs no DOM. */
export function isNumberInput(element) {
    return element != null && element.tagName === 'INPUT' && element.type === 'number';
}

/** Cancels the increment the browser was about to apply, and scrolls in its place. */
export function scrollInsteadOfEditing(event) {
    event.preventDefault();

    const target = scrollTargetFor(event.currentTarget ?? event.target, event.deltaX, event.deltaY);
    const { x, y } = wheelPixels(event, target);

    (target ?? window).scrollBy(x, y);
}

/**
 * The box this wheel event would have scrolled: the nearest ancestor that scrolls on the axis asked
 * for *and still has room that way*, `null` meaning the window.
 *
 * The room check is what reproduces the browser's chaining at the ends of a list — a grid scrolled
 * to its last line lets the wheel through to whatever is behind it instead of swallowing it. Both
 * axes are looked up together and applied to the same box: the two grids scroll horizontally as
 * well, and a trackpad sends the two deltas at once.
 */
export function scrollTargetFor(element, deltaX, deltaY, computedStyle = getComputedStyle) {
    for (let node = element?.parentElement; node != null; node = node.parentElement) {
        const style = computedStyle(node);

        if (hasRoom(node.scrollTop, node.scrollHeight - node.clientHeight, deltaY, style.overflowY)
            || hasRoom(node.scrollLeft, node.scrollWidth - node.clientWidth, deltaX, style.overflowX)) {
            return node;
        }
    }

    return null;
}

function hasRoom(offset, max, delta, overflow) {
    if (!delta || max <= 0 || !/^(auto|scroll|overlay)$/.test(overflow)) {
        return false;
    }

    // A pixel of tolerance: a fractional scrollTop against integer client/scroll sizes would
    // otherwise read as "at the end" one pixel early, and the wheel would fall through too soon.
    return delta > 0 ? offset < max - 1 : offset > 1;
}

/**
 * The event's deltas in pixels. A wheel does not always speak in pixels: Firefox reports mouse
 * ticks in lines and a few setups in pages, and taking those numbers for pixels would scroll a
 * list by three pixels a tick.
 */
export function wheelPixels(event, target = null) {
    const line = 16;
    const page = target?.clientHeight ?? (typeof window === 'undefined' ? 800 : window.innerHeight);
    const factor = event.deltaMode === 1 ? line : event.deltaMode === 2 ? page : 1;

    return { x: event.deltaX * factor, y: event.deltaY * factor };
}

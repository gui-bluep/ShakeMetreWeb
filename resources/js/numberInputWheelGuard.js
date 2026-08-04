/**
 * Stops a focused `<input type="number">` from eating the mouse wheel.
 *
 * Chrome and Firefox treat a wheel tick over a *focused* number input as an increment: the event
 * is spent changing the value and never reaches the scroll container. On the métré grids that is
 * two bugs at once, both silent. The page appears frozen — put the cursor on a Qté or a P.U. cell
 * you have just clicked into and the list will not scroll, while everywhere else it does — and the
 * figure under the cursor moves. Measured in a browser on the Achats/Ventes/Commandes view: one
 * wheel tick over a focused Qté cell took 45 to 44 and the grid's debounced save posted
 * `{"quantity":44}`. Ten small trackpad deltas took a quantity from 1 to -10. Nobody watching the
 * scroll would see it, and it lands on money.
 *
 * The cure is to take focus away before the browser gets to act on the event: the increment only
 * applies to the focused field, so a blurred one falls back to plain scrolling. Hence a listener
 * that blurs rather than one that calls `preventDefault()` — preventing the default would kill the
 * scroll too, and re-implementing it by hand would mean re-implementing trackpad momentum.
 *
 * Consequences, both accepted: the cell loses focus when you scroll over it — which is what you
 * wanted, since you are scrolling it off screen — and its pending edit is flushed by the `blur`
 * handler the grids already bind. Keyboard cell navigation has to be re-entered with a click.
 *
 * Installed once on the document rather than bound per input: the same field type carries money on
 * five screens (the two grids, the tender comparison, the reference catalogue, the métré page), and
 * a guard that has to be remembered on each new one is a guard that will be forgotten.
 *
 * `capture: true` so a component that stops the event's propagation cannot disarm it, and
 * `passive: true` because it never cancels anything — the scroll it exists to allow stays smooth.
 */
export function installNumberInputWheelGuard(root = document) {
    root.addEventListener(
        'wheel',
        (event) => {
            if (swallowsWheel(event.target, root.activeElement)) {
                event.target.blur();
            }
        },
        { capture: true, passive: true }
    );
}

/**
 * Whether this element is the one that would eat the wheel. Duck-typed on `tagName`/`type` rather
 * than `instanceof HTMLInputElement` so it can be exercised without a DOM.
 */
export function swallowsWheel(target, activeElement) {
    return target != null
        && target.tagName === 'INPUT'
        && target.type === 'number'
        && target === activeElement;
}

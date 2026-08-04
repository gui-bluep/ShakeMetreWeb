import { describe, expect, it } from 'vitest';
import { localToday } from './localDate';

describe('localToday', () => {
    it('formats the civil date as YYYY-MM-DD', () => {
        expect(localToday(new Date(2026, 7, 4, 14, 30))).toBe('2026-08-04');
    });

    it('pads the month and the day', () => {
        expect(localToday(new Date(2026, 0, 9, 9, 5))).toBe('2026-01-09');
    });

    /**
     * The reason this helper exists rather than a toISOString().slice(0, 10): just after
     * midnight, UTC is still the day before anywhere east of Greenwich, and the stamped
     * agreement date would read as yesterday.
     */
    it('returns the local day just after midnight, not the UTC one', () => {
        const justAfterMidnight = new Date(2026, 7, 4, 0, 30);

        expect(localToday(justAfterMidnight)).toBe('2026-08-04');

        // What the shortcut would have produced, whenever the local zone is ahead of UTC.
        const viaUtc = justAfterMidnight.toISOString().slice(0, 10);
        expect(localToday(justAfterMidnight) >= viaUtc).toBe(true);
    });

    it('handles the last day of a year', () => {
        expect(localToday(new Date(2026, 11, 31, 23, 59))).toBe('2026-12-31');
    });
});

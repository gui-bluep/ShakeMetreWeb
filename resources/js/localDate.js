/**
 * Aujourd'hui, dans le fuseau de la personne devant l'écran, au format `YYYY-MM-DD` —
 * celui qu'attendent un `<input type="date">` et une colonne DATE.
 *
 * Écrit à la main et surtout PAS avec `toISOString()`, qui convertit en UTC : à Bruxelles,
 * un 4 août à 01:00 y devient « 2025-08-03 », soit la veille. `getFullYear` / `getMonth` /
 * `getDate` lisent la date civile locale, qui est la seule que la personne reconnaît comme
 * la date du jour.
 *
 * @param {Date} [now] Injectable pour les tests ; par défaut l'instant présent.
 * @returns {string}
 */
export function localToday(now = new Date()) {
    const pad = (value) => String(value).padStart(2, '0');

    return `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
}

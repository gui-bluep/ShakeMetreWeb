/**
 * Le préfixe sous lequel l'application est montée, et la seule chose qui sache l'appliquer.
 *
 * À la racine d'un domaine il vaut la chaîne vide et tout ce qui suit ne fait rien. Monté sous
 * un sous-chemin - `https://hôte/metre`, parce que la racine du domaine est déjà prise par autre
 * chose - le serveur, lui, s'en sort seul : `url()`, `route()` et `asset()` déduisent le préfixe
 * de la requête (Symfony le calcule depuis `SCRIPT_NAME`), donc rien ne change en PHP. Le
 * navigateur, non : un `fetch('/api/metre-lines/…')` écrit en dur part à la racine du domaine,
 * chez l'application voisine, qui répond 404. Et c'est un 404 qui ne se voit pas - la ligne
 * cesse simplement d'être enregistrée.
 *
 * D'où ce module, et d'où le fait qu'il soit unique : deux endroits qui préfixent, c'est un
 * endroit qui préfixe deux fois. `joinBase` est donc idempotente et refuse de toucher ce qui
 * n'est pas un chemin interne (une URL absolue, un `fmp://`, un chemin relatif).
 *
 * La valeur vient du document, écrite par `resources/views/app.blade.php` depuis
 * `request()->getBaseUrl()` - la source que Laravel utilise lui-même, plutôt qu'une variable
 * de plus à tenir à jour dans le `.env`. Elle ne change pas au cours d'une session, donc elle
 * est lue une fois et mémorisée (à l'inverse du jeton CSRF, qui tourne et se relit à chaque
 * appel).
 */

/** @type {?string} */
let cached = null;

/**
 * Applique un préfixe à un chemin interne, une fois et une seule.
 *
 * Pure et exportée pour être testée : la lecture du document est l'affaire de `basePath()`.
 *
 * @param {string} base  le préfixe, sans barre finale (`/metre`), ou `''` à la racine
 * @param {string} path
 * @returns {string}
 */
export function joinBase(base, path) {
    if (base === '' || typeof path !== 'string' || path === '') {
        return path;
    }

    // Une URL absolue ou protocolaire (`https://…`, `fmp://…`, `//hôte/…`) ne nous appartient pas.
    if (/^[a-z][a-z0-9+.-]*:/i.test(path) || path.startsWith('//')) {
        return path;
    }

    // Un chemin relatif se résout déjà contre la page courante, qui est sous le préfixe.
    if (! path.startsWith('/')) {
        return path;
    }

    // Déjà préfixé : le cas d'une URL venue du serveur (Ziggy, `page.url` d'Inertia), qui porte
    // le préfixe de naissance. Préfixer par-dessus donnerait `/metre/metre/…`.
    if (path === base || path.startsWith(`${base}/`) || path.startsWith(`${base}?`)) {
        return path;
    }

    return base + path;
}

/**
 * Le préfixe de cette installation, `''` à la racine.
 *
 * @returns {string}
 */
export function basePath() {
    if (cached === null) {
        const declared = typeof document === 'undefined'
            ? ''
            : (document.querySelector('meta[name="base-path"]')?.content ?? '');

        cached = declared.replace(/\/+$/, '');
    }

    return cached;
}

/** Oublie le préfixe mémorisé. Pour les tests. */
export function forgetBasePath() {
    cached = null;
}

/**
 * Un chemin interne, préfixé pour cette installation.
 *
 * @param {string} path
 * @returns {string}
 */
export function u(path) {
    return joinBase(basePath(), path);
}

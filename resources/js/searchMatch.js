/**
 * La recherche des écrans de lignes : insensible à la casse et aux accents, et capable de dire
 * *où* elle a trouvé pour que l'écran puisse le marquer.
 *
 * Replier les accents oblige à tenir une correspondance d'indices. `"é".normalize('NFD')` fait
 * deux caractères — un « e » et un accent combinant — donc chercher dans la chaîne repliée rend
 * des positions qui ne désignent plus rien dans la chaîne d'origine : marquer à ces positions
 * décalerait le surlignage d'un cran par accent rencontré avant lui. D'où `foldWithMap()`, qui
 * replie caractère par caractère en notant, pour chaque caractère replié, d'où il vient.
 *
 * Le repli est fait à la volée plutôt que gardé en cache sur la ligne : une frappe dans la grille
 * change le libellé, et un repli mémorisé serait la version d'avant.
 */

/** La forme comparable d'une valeur : sans accents, en minuscules. */
export function fold(value) {
    return String(value ?? '')
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase();
}

/**
 * La chaîne repliée et, pour chaque caractère replié, l'indice d'où il vient dans l'original.
 *
 * Parcouru par point de code (`for…of`) et non par unité UTF-16 : découper une paire de
 * substitution donnerait deux moitiés de caractère, qu'aucune normalisation ne recolle.
 */
export function foldWithMap(value) {
    const source = String(value ?? '');
    let folded = '';
    const origin = [];
    let at = 0;

    for (const char of source) {
        const piece = fold(char);
        folded += piece;

        // Une entrée par unité UTF-16 du repli, pas par point de code : c'est `indexOf` qui lira
        // ce tableau, et il compte en unités. Un caractère hors du plan de base en occupe deux, et
        // n'en noter qu'une décalerait tout ce qui le suit.
        for (let unit = 0; unit < piece.length; unit++) {
            origin.push(at);
        }

        at += char.length;
    }

    return { folded, origin, length: source.length };
}

/** Le terme est déjà replié par l'appelant — une fois pour toute la liste, pas une fois par champ. */
export function matches(value, foldedTerm) {
    return foldedTerm === '' || fold(value).includes(foldedTerm);
}

/**
 * Découpe un libellé en `{ text, hit }` sur toutes les occurrences du terme. Les morceaux marqués
 * portent le texte **d'origine**, accents et casse compris : on souligne ce qui est écrit, on ne
 * le réécrit pas replié.
 *
 * Rendu par une boucle et jamais par `v-html` : ces libellés viennent de la saisie.
 */
export function highlightParts(text, foldedTerm) {
    const value = String(text ?? '');

    if (foldedTerm === '' || value === '') {
        return [{ text: value, hit: false }];
    }

    const { folded, origin, length } = foldWithMap(value);
    const parts = [];
    let cut = 0;
    let from = 0;

    while (from <= folded.length - foldedTerm.length) {
        const found = folded.indexOf(foldedTerm, from);

        if (found === -1) {
            break;
        }

        const start = origin[found];
        const stop = found + foldedTerm.length < origin.length
            ? origin[found + foldedTerm.length]
            : length;

        if (start > cut) {
            parts.push({ text: value.slice(cut, start), hit: false });
        }

        parts.push({ text: value.slice(start, stop), hit: true });
        cut = stop;
        from = found + foldedTerm.length;
    }

    if (cut < length) {
        parts.push({ text: value.slice(cut), hit: false });
    }

    return parts.length > 0 ? parts : [{ text: value, hit: false }];
}

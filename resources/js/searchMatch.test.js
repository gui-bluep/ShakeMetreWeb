import { describe, expect, it } from 'vitest';
import { fold, foldWithMap, highlightParts, matches } from './searchMatch';

/** Le texte marqué, remis bout à bout, doit toujours redonner l'original — accents compris. */
const rebuild = (parts) => parts.map((part) => part.text).join('');
const hits = (parts) => parts.filter((part) => part.hit).map((part) => part.text);

describe('fold', () => {
    it('enlève les accents et la casse', () => {
        expect(fold('Électricité')).toBe('electricite');
        expect(fold('ÉLECTRICITÉ')).toBe('electricite');
        expect(fold('Démolition çà et là')).toBe('demolition ca et la');
    });

    it('laisse une chaîne sans accent telle quelle, en minuscules', () => {
        expect(fold('DEMOLITION')).toBe('demolition');
        expect(fold('Gros-oeuvre')).toBe('gros-oeuvre');
    });

    it('tolère le vide', () => {
        expect(fold(null)).toBe('');
        expect(fold(undefined)).toBe('');
        expect(fold('')).toBe('');
    });
});

describe('matches', () => {
    it('trouve sans accent un texte accentué, et l\'inverse', () => {
        expect(matches('Électricité', 'electricite')).toBe(true);
        expect(matches('ELECTRICITE', fold('électricité'))).toBe(true);
    });

    it('ignore la casse', () => {
        expect(matches('Baraque de chantier', 'baraque')).toBe(true);
        expect(matches('baraque de chantier', 'BARAQUE'.toLowerCase())).toBe(true);
    });

    it('ne trouve pas ce qui n\'y est pas', () => {
        expect(matches('Baraque de chantier', 'plafond')).toBe(false);
        expect(matches(null, 'plafond')).toBe(false);
    });

    it('un terme vide accepte tout : la liste n\'est pas filtrée', () => {
        expect(matches('n\'importe quoi', '')).toBe(true);
        expect(matches(null, '')).toBe(true);
    });
});

describe('foldWithMap', () => {
    it('fait pointer chaque caractère replié vers son origine', () => {
        // « é » se décompose en deux, et ne doit compter que pour le caractère 0.
        const { folded, origin } = foldWithMap('été');

        expect(folded).toBe('ete');
        expect(origin).toEqual([0, 1, 2]);
    });

    it('survit à une paire de substitution', () => {
        const { folded, origin } = foldWithMap('🙂é');

        expect(folded).toBe('🙂e');
        // L'emoji occupe deux unités UTF-16, donc deux entrées pointant toutes deux sur l'indice 0,
        // et le « é » qui le suit commence à l'indice 2. C'est ce décalage qui décalait le
        // surlignage de tout ce qui suivait un tel caractère.
        expect(origin).toEqual([0, 0, 2]);
        expect(origin).toHaveLength(folded.length);
    });
});

describe('highlightParts', () => {
    it('marque le texte accentué trouvé par un terme sans accent, sans le décaler', () => {
        const parts = highlightParts('Démolition lourde', 'demolition');

        expect(hits(parts)).toEqual(['Démolition']);
        expect(rebuild(parts)).toBe('Démolition lourde');
    });

    /** Le cas que la correspondance d'indices existe pour : des accents AVANT le mot trouvé. */
    it('ne décale pas quand des accents précèdent la trouvaille', () => {
        const parts = highlightParts('Éé sanitaire', 'sanitaire');

        expect(hits(parts)).toEqual(['sanitaire']);
        expect(rebuild(parts)).toBe('Éé sanitaire');
    });

    it('marque toutes les occurrences', () => {
        const parts = highlightParts('Démolition et évacuation, démolition finale', 'demolition');

        expect(hits(parts)).toEqual(['Démolition', 'démolition']);
        expect(rebuild(parts)).toBe('Démolition et évacuation, démolition finale');
    });

    it('marque une occurrence en fin de chaîne', () => {
        const parts = highlightParts('Dépose et évacuation', 'evacuation');

        expect(hits(parts)).toEqual(['évacuation']);
        expect(rebuild(parts)).toBe('Dépose et évacuation');
    });

    it('marque toute la chaîne quand elle est le terme', () => {
        const parts = highlightParts('Électricité', 'electricite');

        expect(parts).toEqual([{ text: 'Électricité', hit: true }]);
    });

    it('ne marque rien quand rien ne correspond', () => {
        const parts = highlightParts('Baraque de chantier', 'plafond');

        expect(hits(parts)).toEqual([]);
        expect(rebuild(parts)).toBe('Baraque de chantier');
    });

    it('rend le libellé intact sans terme, et supporte le vide', () => {
        expect(highlightParts('Sanitaire', '')).toEqual([{ text: 'Sanitaire', hit: false }]);
        expect(highlightParts(null, 'x')).toEqual([{ text: '', hit: false }]);
        expect(highlightParts('', 'x')).toEqual([{ text: '', hit: false }]);
    });

    it('marque après un emoji sans se décaler', () => {
        const parts = highlightParts('🙂 Démolition', 'demolition');

        expect(hits(parts)).toEqual(['Démolition']);
        expect(rebuild(parts)).toBe('🙂 Démolition');
    });
});

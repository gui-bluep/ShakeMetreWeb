import { afterEach, describe, expect, it } from 'vitest';
import { basePath, forgetBasePath, joinBase, u } from './basePath';

afterEach(() => {
    forgetBasePath();
    delete globalThis.document;
});

/** Un document réduit au strict nécessaire : Vitest tourne ici sans DOM. */
function documentDeclaring(content) {
    globalThis.document = {
        querySelector: (selector) =>
            selector === 'meta[name="base-path"]' && content !== null ? { content } : null,
    };
}

describe('joinBase', () => {
    it('ne touche à rien à la racine', () => {
        expect(joinBase('', '/api/metre-lines/7')).toBe('/api/metre-lines/7');
    });

    it('préfixe un chemin interne', () => {
        expect(joinBase('/metre', '/api/metre-lines/7')).toBe('/metre/api/metre-lines/7');
        expect(joinBase('/metre', '/dashboard')).toBe('/metre/dashboard');
    });

    it('ne préfixe pas deux fois — une URL venue du serveur porte déjà le préfixe', () => {
        expect(joinBase('/metre', '/metre/dashboard')).toBe('/metre/dashboard');
        expect(joinBase('/metre', '/metre')).toBe('/metre');
        expect(joinBase('/metre', '/metre?lot=3')).toBe('/metre?lot=3');
    });

    it('ne confond pas un préfixe avec le début d’un autre segment', () => {
        // `/metres/…` commence par `/metre` sans être sous le préfixe : c'est une route de
        // l'application, et sans la barre le test d'idempotence l'aurait laissée à la racine.
        expect(joinBase('/metre', '/metres/abc')).toBe('/metre/metres/abc');
    });

    it('laisse les URLs qui ne nous appartiennent pas', () => {
        expect(joinBase('/metre', 'https://fms23.mycloud.fm/x')).toBe('https://fms23.mycloud.fm/x');
        expect(joinBase('/metre', 'fmp://hôte/ShakeDesign?script=SOR_GoTo')).toBe('fmp://hôte/ShakeDesign?script=SOR_GoTo');
        expect(joinBase('/metre', '//cdn.example/x.js')).toBe('//cdn.example/x.js');
        expect(joinBase('/metre', 'build/app.css')).toBe('build/app.css');
        expect(joinBase('/metre', '')).toBe('');
    });
});

describe('basePath', () => {
    it('lit le méta du document', () => {
        documentDeclaring('/metre');
        expect(basePath()).toBe('/metre');
    });

    it('vaut la chaîne vide à la racine, méta vide ou absent', () => {
        documentDeclaring('');
        expect(basePath()).toBe('');

        forgetBasePath();
        documentDeclaring(null);
        expect(basePath()).toBe('');
    });

    it('tolère une barre finale', () => {
        documentDeclaring('/metre/');
        expect(basePath()).toBe('/metre');
    });

    it('vaut la chaîne vide hors navigateur', () => {
        expect(basePath()).toBe('');
    });

    it('u() applique le préfixe du document', () => {
        documentDeclaring('/metre');
        expect(u('/api/lots/3')).toBe('/metre/api/lots/3');
    });
});

import { describe, expect, it } from 'vitest';
import { offerUrl, scriptUrl, supplierOrderUrl, xmlSet } from './fileMakerLink';

const LINK = { host: 'fms.shakedesign.be', database: 'ShakeDesign' };
const ZKP = '3F2A1B4C-1111-4222-8333-444455556666';

describe('xmlSet', () => {
    it('produces the format the two FileMaker files exchange', () => {
        expect(xmlSet('SOR', 'abc')).toBe('<SOR>abc</SOR>');
    });
});

describe('supplierOrderUrl', () => {
    /**
     * La cible entière, telle que l'export la dicte : le fichier ShakeDesign, son script
     * SOR_GoTo, et le zkp de la commande dans un paramètre `<SOR>…</SOR>`.
     */
    it('targets ShakeDesign :: SOR_GoTo with the zkp in an XML parameter', () => {
        expect(supplierOrderUrl(LINK, ZKP)).toBe(
            'fmp://fms.shakedesign.be/ShakeDesign?script=SOR_GoTo' +
                `&param=%3CSOR%3E${ZKP}%3C%2FSOR%3E`
        );
    });

    it('has no link without a supplier order — the source hid its button the same way', () => {
        expect(supplierOrderUrl(LINK, null)).toBeNull();
        expect(supplierOrderUrl(LINK, '')).toBeNull();
        expect(supplierOrderUrl(LINK, '   ')).toBeNull();
    });

    /**
     * Le zkp part tel quel dans le paramètre XML : tout ce qui n'est pas un zkp est refusé au
     * lieu d'être échappé, parce que `xml2var` de l'autre côté ne saurait pas défaire un
     * échappement que la source ne fait pas non plus.
     */
    it('refuses anything that is not a zkp rather than escaping it', () => {
        expect(supplierOrderUrl(LINK, '</SOR><OFF>x')).toBeNull();
        expect(supplierOrderUrl(LINK, 'abc def')).toBeNull();
    });

    it('has no link when the FileMaker client target is not configured', () => {
        expect(supplierOrderUrl(null, ZKP)).toBeNull();
        expect(supplierOrderUrl({ host: '', database: 'ShakeDesign' }, ZKP)).toBeNull();
        expect(supplierOrderUrl({ host: 'fms.example.test', database: '' }, ZKP)).toBeNull();
    });
});

describe('offerUrl', () => {
    /** ShakeDesign :: OFF_GoTo (id 89) est le même script, à la balise près. */
    it('targets ShakeDesign :: OFF_GoTo with the zkp in an OFF parameter', () => {
        expect(offerUrl(LINK, ZKP)).toBe(
            'fmp://fms.shakedesign.be/ShakeDesign?script=OFF_GoTo' +
                `&param=%3COFF%3E${ZKP}%3C%2FOFF%3E`
        );
    });

    /**
     * `listOffersForMetre()` construit `zkp` avec `(string) ($row['zkp'] ?? '')` : une offre dont
     * le champ manquerait arrive avec une chaîne vide, pas avec null. Les deux doivent donner un
     * libellé sans lien plutôt qu'une URL vers `<OFF></OFF>`.
     */
    it('has no link when the offer carries no zkp', () => {
        expect(offerUrl(LINK, '')).toBeNull();
        expect(offerUrl(LINK, null)).toBeNull();
    });

    it('has no link when the FileMaker client target is not configured', () => {
        expect(offerUrl(null, ZKP)).toBeNull();
    });
});

describe('scriptUrl', () => {
    /**
     * Un port doit survivre : `encodeURIComponent` sur l'hôte tournerait le « : » en « %3A » et
     * FileMaker ne saurait plus où se connecter. L'hôte est donc validé, pas encodé.
     */
    it('keeps a port on the host', () => {
        expect(scriptUrl({ host: 'fms.example.test:5003', database: 'X' }, 'S')).toBe(
            'fmp://fms.example.test:5003/X?script=S'
        );
    });

    it('rejects a host that is not one', () => {
        expect(scriptUrl({ host: 'evil.test/x?script=Delete_All', database: 'X' }, 'S')).toBeNull();
    });

    // Le nom du fichier, lui, est bien encodé : il peut porter une espace.
    it('encodes the database name', () => {
        expect(scriptUrl({ host: 'h', database: 'Shake Design' }, 'S')).toBe(
            'fmp://h/Shake%20Design?script=S'
        );
    });
});

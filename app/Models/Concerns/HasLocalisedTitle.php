<?php

namespace App\Models\Concerns;

/**
 * REF / REFS / REFSL each carry Title_FR, Title_EN and Title_NL plus the same unstored
 * calculation to pick one:
 *
 *     LocalisedString_cU
 *       Case ( ZVAR::CurrentLanguage_g = "FR" ; Title_FR ;
 *              ZVAR::CurrentLanguage_g = "EN" ; Title_EN ;
 *              ZVAR::CurrentLanguage_g = "NL" ; Title_NL )
 *
 * Two deliberate differences from the source, both visible in the live data:
 *
 *  - The language asked for is the MÉTRÉ's, not the interface's. The source reads a global set
 *    by the UI, so the same catalogue item entered the same day landed in French or in English
 *    depending on who was looking. A métré is a document with a language of its own, and that
 *    is what its lines should be written in.
 *  - It falls back. Title_NL is empty on all 19 references, all 118 sub-references and all 507
 *    items of the live file, so the source formula yields an EMPTY section title for a Dutch
 *    métré. An empty heading on a client document is worse than a French one.
 */
trait HasLocalisedTitle
{
    /** @var list<string> */
    private const TITLE_FALLBACK = ['fr', 'en', 'nl'];

    public function localisedTitle(?string $language): ?string
    {
        $wanted = mb_strtolower(trim((string) $language));

        foreach ([$wanted, ...self::TITLE_FALLBACK] as $code) {
            $value = $this->{"title_{$code}"} ?? null;

            if (filled($value)) {
                return $value;
            }
        }

        return null;
    }
}

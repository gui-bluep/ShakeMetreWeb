<?php

namespace App\Documents;

/**
 * Les libellés d'un document imprimé, dans la langue du métré.
 *
 * `GEN__PRINT_LoadPrintStrings` côté source, sans sa table : les chaînes vivent dans
 * `config/print_labels.php`, qui porte aussi le pourquoi de chaque écart. Le repli sur le
 * français est la règle de la source elle-même, qui pose `PrintLanguage_g = "FR"` quand la langue
 * demandée est vide - ici il joue aussi chaîne par chaîne, le néerlandais étant très incomplet.
 */
class PrintLabels
{
    /** @var array<string, string> */
    private readonly array $labels;

    public function __construct(?string $language)
    {
        $fallback = (string) config('print_labels.fallback', 'FR');
        $wanted = strtoupper((string) $language);

        $this->labels = array_merge(
            (array) config("print_labels.{$fallback}", []),
            (array) config('print_labels.'.$wanted, []),
        );
    }

    public function get(string $key): string
    {
        return $this->labels[$key] ?? $key;
    }
}

<?php

/*
|--------------------------------------------------------------------------
| Libellés des documents imprimés
|--------------------------------------------------------------------------
|
| Ce sont les enregistrements `ZSTRI_Strings` portant `isPrintLabel = 1`, lus sur l'ancien
| ShakeMetre hébergé le 05/08/2026 - pas retapés de mémoire. `GEN__PRINT_LoadPrintStrings` les
| charge dans `ZSTRI::PrintLabels_g[Serial]` en prenant la colonne de la langue demandée, et les
| mises en page les référencent par ce numéro de répétition. Le numéro est donc l'identité d'un
| libellé : il est conservé en commentaire pour que le lien avec la source reste vérifiable.
|
| `ZSTRI` n'est pas portée comme table (décision du projet : config Laravel plutôt que les tables
| de réglages FileMaker). Vingt-trois chaînes, dont neuf servent ici.
|
| Deux écarts avec la source, tous deux assumés :
|
|  - Le néerlandais est vide sur la plupart des enregistrements. FileMaker imprimerait donc des
|    en-têtes de colonnes blancs sur un métré NL. Ici, une chaîne absente retombe sur le français,
|    ce que fait déjà `HasLocalisedTitle` ailleurs dans ce projet, et ce que fait aussi le script
|    lui-même quand la langue n'est pas donnée du tout (`Set Field [ PrintLanguage_g ; "FR" ]`).
|  - La langue est celle du métré. La source charge les libellés deux fois - d'abord la langue du
|    métré, puis celle de l'interface, qui écrase la première (l'appel a été ajouté après coup,
|    commenté « AR 09/06/2020 »). Un document destiné à un client doit parler la langue du
|    document, pas celle de la personne qui l'imprime.
|
*/

return [

    'fallback' => 'FR',

    'FR' => [
        'title' => 'Métré / Budget',                        // 1
        'works' => 'Travaux',                               // 2
        'unit' => 'Unité',                                  // 3
        'quantity' => 'Quantité',                           // 4
        'unit_price' => 'Prix unitaire HTVA',               // 5
        'comments' => 'Commentaires',                       // 7
        'options' => 'Options',                             // 8
        'index' => 'Indice',                                // 9
        'total' => 'Total HTVA',                            // 10
        'supplier_title' => 'Bon de Commande - Entreprise', // 11
    ],

    'NL' => [
        'options' => 'Options',                             // 8
    ],

    'EN' => [
        'title' => 'List of quantities / Budget',           // 1
        'works' => 'Works',                                 // 2
        'unit' => 'Unit',                                   // 3
        'quantity' => 'Quantity',                           // 4
        'unit_price' => 'Unit price excl. taxes',           // 5
        'comments' => 'Comments',                           // 7
        'options' => 'Options',                             // 8
        'index' => 'Indice',                                // 9
        'total' => 'Total excl. taxes',                     // 10
        'supplier_title' => 'Order form - Company',         // 11
    ],

];

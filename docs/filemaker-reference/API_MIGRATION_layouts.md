# Mises en page `API_MIGRATION_*` — les champs que l'import lit

Sept mises en page dans **ShakeMetre** (le fichier hébergé), une par table à migrer. Elles
n'existent que pour l'import et disparaissent avec lui.

Pourquoi elles sont nécessaires : le Data API ne voit que les champs **posés sur la mise en page
qu'il interroge**. Les `DEV/Raw/*` existantes ont été faites pour l'inspection, pas pour l'export,
et il leur manque l'essentiel — `MET_raw` n'expose ni `IndProject`, ni `Language`, ni
`isAccepted_b`, ni un seul total.

> ## Ces listes sont la référence, et elles sont générées depuis le code
>
> **221 champs en tout**, contre un peu plus de 600 posés aujourd'hui (les sept vues portent
> l'intégralité des champs de leur table). Ce sont exactement les champs que
> `App\Console\Commands\LegacyImport\LegacyFieldMap` lit, plus les quatre `zlg_*` d'horodatage et
> d'auteur que l'import lit sur chaque table.
>
> `php artisan shakemetre:import` **revérifie leur présence à chaque lancement et refuse de
> tourner s'il en manque un** : un champ absent ne fait pas échouer une lecture, la colonne arrive
> nulle, en silence, sur de l'argent. Poser plus que ces listes ne casse rien — poser moins est
> détecté avant la première écriture.
>
> ### Le dégraissage ne vaut que pour METL, et un champ y fait presque tout
>
> Mesuré sur un import à blanc complet : **28 min et 733 Mo, dont 26 min 51 et ~99 % des octets
> pour METL**. Les six autres vues pèsent une trentaine de secondes à elles toutes — les dégraisser
> ne rapporte rien de perceptible.
>
> Le coupable est **`zsm_zkf_VAT_List`**, un champ résumé « liste de » : un résumé s'évalue sur le
> **found set** et revient sur **chaque** enregistrement rendu, donc celui-ci pèse **2 138 821
> octets par ligne** là où tout le reste de la vue en fait 929. C'est aussi pourquoi les ~40 autres
> `zsm_*` de METL coûtent du temps de calcul au serveur même quand leur résultat est court.
>
> **Retirer les `zsm_*` de `API_MIGRATION_METL` fait passer la lecture de ~733 Mo à ~51 Mo.** Et
> l'import le détecte seul : tant qu'un `zsm_*` est posé, il lit métré par métré pour réduire le
> found set (~1 500 requêtes) ; s'il n'y en a plus, il lit la table d'un bout à l'autre en pages de
> 500 (~100 requêtes). Rien à configurer, la stratégie est annoncée au démarrage.
>
> Si vous ne touchez qu'une chose : **les `zsm_*` de METL**.

## À savoir avant de les construire

- **Le nom seul compte.** Le Data API adresse une mise en page par son nom, pas par son dossier :
  les ranger dans un dossier ne change rien à l'appel. Les noms ci-dessous sont à respecter à la
  lettre.
- **Le compte API doit les voir** — le même jeu de privilèges que celui qui lit déjà `DEV/Raw/*`.
- **Tous les champs listés sont des champs stockés** (`Normal`) : les poser suffit, il n'y a aucun
  calcul à déclencher. Quatre exceptions, calculées mais **par enregistrement** donc légitimes : les
  `PROG_*_Valid_Stored_c` de MET, dont la formule est `PROG_..._Stored * isAccepted_b` (ou
  `* IsStatus_Site_b`).
- **La mise en forme n'a aucune importance.** Le Data API rend la valeur, pas son rendu : des
  champs empilés, minuscules et sans libellé conviennent parfaitement.
- **Deux noms contiennent une espace** — `SumTotal WorkFee` et `SumTotal WorkFeeOrdered`, sur METL.
  Ce n'est pas une coquille de ce document.
- **`Image_500x500` est un conteneur et n'est PAS dans les listes.** Le Data API n'en rend qu'une
  URL temporaire, à télécharger fichier par fichier, et le web n'a pas encore de stockage. Les
  images se traiteront en seconde passe si elles sont voulues.
- **`zkf_REF` / `zkf_REFS` / `zkf_REFSL` de METL sont vides sur les 57 816 lignes** (revérifié sur
  le serveur). Ils restent dans la liste : ils arriveront nuls, et les poser documente le fait
  qu'une ligne ne pointe pas le catalogue mais en porte une copie.
- **`created_by` / `updated_by` n'aboutiront pas tels quels** : FileMaker donne un *nom de compte*,
  la colonne web attend l'UUID d'un utilisateur, donc elles restent nulles et l'import se contente
  de rapporter les noms rencontrés. Les *dates*, elles, passent directement.

## Ce qui a changé depuis la première version de ce document

Trois corrections, toutes établies en faisant tourner l'import pour de vrai :

- **Les trois `Tot_*_TotalFees_Stored` de MET sont RETIRÉS.** `Tot_Sum_TotalFees_Stored`,
  `Tot_Percentage_TotalFees_Stored` et `Tot_Ratio_TotalFees_Stored` sont des champs *Calculated*
  qui lisent des champs *Summary* (`zsm_SumTotalSales_Stored`, `zsm_SumTotalOrdered_Stored`) : ils
  totalisent le **found set**, pas le métré, malgré leur nom en `_Stored`. Constaté sur une lecture
  réelle — la même valeur à l'identique sur les 877 métrés, et 3,5e17 sur l'un d'eux, ce qui
  débordait `decimal(15,4)` et faisait échouer l'insertion. `RecalculateMetreTotals` les calcule par
  enregistrement ; c'est ce que `--recalculate` déclenche. Les poser ne gêne pas, ils ne seront
  simplement pas lus.
- **`z_Order` est AJOUTÉ à METC.** La première version le donnait comme sans source. L'export dit
  l'inverse : champ `Normal` de type Number, auto-enter `metc_METC__Ordering_s::z_Order + 1`, lu par
  `$$DRAG.ORDER` / `$$DROP.ORDER` et trié croissant par les portails. C'est le rang d'un composant
  dans sa ligne.
- **Les quatre `zlg_*` sont explicitement dans chaque liste.** L'import les lit sur les sept tables
  (horodatages de création/modification, et noms de compte pour le rapport), donc leur absence
  bloque le contrôle de démarrage.

## `API_MIGRATION_REF` → `metre_references`

**9 champs.**

```
Code
Title_EN
Title_FR
Title_NL
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_REFS` → `sub_references`

**10 champs.**

```
Code
Title_EN
Title_FR
Title_NL
zkf_REF
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_REFSL` → `sub_reference_lines`

**16 champs.**

```
Code
Description_EN
Description_FR
Description_NL
Price
Title_EN
Title_FR
Title_NL
Unit
zkf_REF
zkf_REFS
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_MET` → `metres`

**42 champs.**

```
Comment_Client
Comment_Internal
Comment_Supplier
DateTime_UpdateCalcsStored
Date_Agreement
Date_Creation
IndProject
isAccepted_b
isArchived_b
isImported_b
isLocked_b
IsStatus_Site_b
Language
Name
PROG_ProgressClientTotal_Amount_Stored
PROG_ProgressClientTotal_Amount_Valid_Stored_c
PROG_ProgressClientTotal_Percent_Stored
PROG_ProgressClientTotal_Percent_Valid_Stored_c
PROG_ProgressSuppTotal_Amount_Stored
PROG_ProgressSuppTotal_Amount_Valid_Stored_c
PROG_ProgressSuppTotal_Percent_Stored
PROG_ProgressSuppTotal_Percent_Valid_Stored_c
PROG_ProgressUpdateDate
Ratio_Markup
Sort_OrderTags
Total_Gain_METL_Stored
Total_Ordered_METL_Stored
Total_Purchase_METL_Stored
Total_Sales_METL_Stored
Tot_LotAssigned_GainOnPurchase_Stored
Tot_Sum_TotalBuy_Stored
Tot_Sum_TotalGain_Stored
Tot_Sum_TotalOrdered_Stored
Tot_Sum_TotalSalesOffer_Stored
Tot_Sum_TotalSales_Stored
zkf_OFF
zkf_PRJ
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_LOT` → `lots`

**62 champs.**

```
Code
CPY_ADR_ae
CPY_Name_ae
CTC_Name_ae
TENDER_Supp1_Comment
TENDER_Supp2_Comment
TENDER_Supp3_Comment
TENDER_Supp4_Comment
TENDER_Supp5_Comment
TENDER_Weighting_Crit1
TENDER_Weighting_Crit1_Description
TENDER_Weighting_Crit1_Supp1
TENDER_Weighting_Crit1_Supp2
TENDER_Weighting_Crit1_Supp3
TENDER_Weighting_Crit1_Supp4
TENDER_Weighting_Crit1_Supp5
TENDER_Weighting_Crit2
TENDER_Weighting_Crit2_Description
TENDER_Weighting_Crit2_Supp1
TENDER_Weighting_Crit2_Supp2
TENDER_Weighting_Crit2_Supp3
TENDER_Weighting_Crit2_Supp4
TENDER_Weighting_Crit2_Supp5
TENDER_Weighting_Crit3
TENDER_Weighting_Crit3_Description
TENDER_Weighting_Crit3_Supp1
TENDER_Weighting_Crit3_Supp2
TENDER_Weighting_Crit3_Supp3
TENDER_Weighting_Crit3_Supp4
TENDER_Weighting_Crit3_Supp5
TENDER_Weighting_Crit4
TENDER_Weighting_Crit4_Description
TENDER_Weighting_Crit4_Supp1
TENDER_Weighting_Crit4_Supp2
TENDER_Weighting_Crit4_Supp3
TENDER_Weighting_Crit4_Supp4
TENDER_Weighting_Crit4_Supp5
TENDER_Weighting_Crit5
TENDER_Weighting_Crit5_Description
TENDER_Weighting_Crit5_Supp1
TENDER_Weighting_Crit5_Supp2
TENDER_Weighting_Crit5_Supp3
TENDER_Weighting_Crit5_Supp4
TENDER_Weighting_Crit5_Supp5
TENDER_Weighting_Price
Title_Custom
Title_EN
Title_FR
Title_NL
zkf_CPY
zkf_CTC
zkf_PRJ
zkf_TENDER_Supp1
zkf_TENDER_Supp2
zkf_TENDER_Supp3
zkf_TENDER_Supp4
zkf_TENDER_Supp5
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_METL` → `metre_lines`

**69 champs.**

```
Comment_Client
Comment_Supplier
Description
Description_CCH
isDelivered_b
isEstimatedPrice_b
isImported_b
isLocked_bae
isOption_b
isTenderLine_b
Language
Localisation
LOT_Name_Stored
METC_isPresent_b
Order
PriceBuy
PriceOrdered
PriceSales
ProcurementMethodCode_ae
PROG_ProgressClient
PROG_ProgressSupp
Quantity
QuantityOrdered
Ratio
REFSL_Title
REFS_Code
REFS_Title
REF_Code
REF_Title
SOR_TitleRef
SumTotalCompany1Price
SumTotal WorkFee
SumTotal WorkFeeOrdered
TAG1
TAG2
TENDER_Id
TENDER_Supp1_Omit_b
TENDER_Supp1_Price
TENDER_Supp1_Quantity
TENDER_Supp2_Omit_b
TENDER_Supp2_Price
TENDER_Supp2_Quantity
TENDER_Supp3_Omit_b
TENDER_Supp3_Price
TENDER_Supp3_Quantity
TENDER_Supp4_Omit_b
TENDER_Supp4_Price
TENDER_Supp4_Quantity
TENDER_Supp5_Omit_b
TENDER_Supp5_Price
TENDER_Supp5_Quantity
Unit
VAT_ae
zkf_AccountingCode_ae
zkf_CPY
zkf_JCARTMAT
zkf_LOT
zkf_MAT
zkf_MET
zkf_REF
zkf_REFS
zkf_REFSL
zkf_SOR
zkf_VAT_ae
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
```

## `API_MIGRATION_METC` → `metre_line_components`

**13 champs.**

```
Description
Height
Length
QuantityOrdered
QuantitySales
Width
zkf_METL
zkp
zlg_creaTimeStamp
zlg_creaUserName
zlg_modifTimeStamp
zlg_modifUserName
z_Order
```

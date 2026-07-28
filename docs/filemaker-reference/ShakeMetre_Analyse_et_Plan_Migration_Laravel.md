# ShakeMetre → Laravel : analyse d'architecture et plan de migration

*Analyse basée sur l'export XML complet de ShakeMetre.fmp12 (17 tables, 91 relations, 274 scripts, 128 layouts, 92 fonctions personnalisées) et le sous-ensemble de ShakeDesign.fmp12 avec lequel ShakeMetre est couplé.*

---

## 1. Résumé exécutif

ShakeMetre est un module de métré/soumission (quantity survey) construit comme fichier FileMaker séparé, mais **relationnellement soudé** à ShakeDesign dans les deux sens :

- **ShakeMetre → ShakeDesign** : 23 occurrences de table externes vers 7 tables de ShakeDesign (Projets, Offres, Sociétés, Contacts, Commandes fournisseurs, Valeurs/TVA). ShakeMetre a besoin de ShakeDesign pour fonctionner — un métré n'existe jamais sans un Projet.
- **ShakeDesign → ShakeMetre** : plus discret en apparence (1 seule TO externe, un portail sur la fiche Projet), mais avec **deux clés étrangères stockées en dur** : `OFF_Offers.zkf_MET` et `SOR_SupplierOrders.zkf_MET` pointent directement sur `MET_Metre.zkp`. C'est le point le plus critique de toute la migration : ShakeDesign **stocke des références** vers des enregistrements ShakeMetre, pas seulement des relations calculées.

Bonne nouvelle pour la migration : les tables au cœur du système (`MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference`, `METC_MetreLineComponent`) utilisent déjà des clés primaires **texte (UUID-style)** plutôt que des numéros auto-incrémentés — exactement comme dans ShakeDesign. Cela veut dire qu'on peut migrer vers Laravel avec des UUID et **préserver les valeurs de zkp existantes**, ce qui évite d'avoir à ré-écrire les clés étrangères stockées côté ShakeDesign.

---

## 2. Schéma de ShakeMetre

### 2.1 Les 17 tables

| Table | Champs | Rôle | Style de clé |
|---|---|---|---|
| `MET_Metre` | 124 | Le métré lui-même (une soumission liée à un Projet) | Texte (UUID) |
| `METL_MetreLines` | 235 | Les lignes de métré — le cœur du moteur de calcul | Texte (UUID) |
| `METC_MetreLineComponent` | 35 | Composants d'une ligne (dimensions L×W×H, quantités) | Texte (UUID) |
| `LOT_Lot` | 107 | Lots de soumission fournisseur + critères de pondération d'appel d'offres | Texte (UUID) |
| `REF_Reference` | 40 | Référentiel de postes (niveau 1) | Texte (UUID) |
| `REFS_SubReference` | 35 | Sous-référentiel (niveau 2) | Numéro (legacy) |
| `REFSL_SubReferenceLines` | 43 | Lignes de sous-référentiel (niveau 3, avec prix) | Numéro (legacy) |
| `MAT_Materials` | 70 | Catalogue de matériaux (avec images) | Numéro (legacy) |
| `CAT_Category` / `CATS_SubCategory` | 32/32 | Catégories de matériaux | Numéro (legacy) |
| `CART_Carts` | 33 | "Paniers" de matériaux liés à un projet | Numéro (legacy) |
| `JCARTMAT_JoinCartsMaterials` | 59 | Ligne de panier (copie dénormalisée des attributs MAT) | Numéro (legacy) |
| `TAG_Tags` | 31 | Tags libres sur un métré | Numéro (legacy) |
| `ZSET_Settings` / `ZVAR_Variables` / `ZSTRI_Strings` | 30/38/53 | Config, variables globales, chaînes i18n (FR/EN/NL) | Numéro (legacy) |
| `ZZZ_Template` | 23 | Table gabarit de développeur (Blue Pineapple) — **ne pas migrer** | Texte |

**Observation utile pour le sprint de migration** : la frontière entre clés UUID et clés Number correspond exactement aux tables qui ont été retouchées récemment (le cœur métier) vs celles qui n'ont pas encore été modernisées (référentiel matériaux, paniers, tags). C'est un signal que ces dernières sont candidates à une refonte de clé pendant la migration — autant en profiter puisqu'on repart de zéro en Laravel.

### 2.2 Conventions de nommage (à documenter pour Claude Code)

- `zkp` = clé primaire · `zkf_XXX` = clé étrangère vers table XXX · `zkk_ID` = numéro séquentiel d'affichage (indépendant de la clé primaire)
- `zg_` = variable/cache global au niveau de la table (ex. `zg_LotNames_Cache`, `zg_LotZKP_Cache` sur `MET_Metre` — exactement le mécanisme de cache LOT sur lequel vous travaillez)
- `zlg_crea*` / `zlg_modif*` = métadonnées d'audit (créé par/le, modifié par/le/script) → en Laravel : `created_by`, `created_at`, `updated_by`, `updated_at` + un `Observer` ou trait `Auditable` pour tracer le nom du script/job responsable
- `_b` = booléen · `_c` / `_cU` = calcul (non stocké) · `_Stored` = calcul matérialisé en dur (dénormalisation pour la perf) · `_ae` = "auto-enter" (copie locale dénormalisée d'un champ distant, ex. `CPY_Name_ae`)
- `TEMP_`, `Oneshot_`, `__SAVE_AR_YYYYMMDD`, `__OLD` = dette technique volontaire : scripts/layouts de correctifs ponctuels ou versions archivées par copie manuelle. **Ne pas porter ces éléments en Laravel** — ce sont des artefacts de dev, pas des besoins fonctionnels.

### 2.3 Le pattern de calcul stocké (`_Stored`)

`MET_Metre` a onze champs `_Stored` (`Tot_Sum_TotalSales_Stored`, `Tot_Sum_TotalOrdered_Stored`, `PROG_ProgressClientTotal_Amount_Stored`, etc.) qui matérialisent des agrégats sur `METL_MetreLines`. C'est exactement l'équivalent FileMaker de ce qu'on ferait en Laravel avec :
- soit des **colonnes calculées à la sauvegarde** via un Observer (`MetreLine::saved()` → recalcule et sauvegarde les totaux sur le `Metre` parent),
- soit un **job différé** (queue) si le recalcul est coûteux, comme vous le faites déjà avec vos scripts `MET_UpdateStoredCalcs_PSOS`.

C'est un pattern à répliquer tel quel, pas à réinventer.

---

## 3. La frontière ShakeMetre ↔ ShakeDesign (le point critique)

### 3.1 Ce que ShakeMetre va chercher chez ShakeDesign

| TO externe dans ShakeMetre | Table ShakeDesign | Usage |
|---|---|---|
| `prj__PRJ__`, `met_PRJ__`, `lot_PRJ__`, `zvar_PRJ__*` | `PRJ_Projects` | Le projet parent — **rien n'existe sans lui** |
| `met_OFF__Offers` | `OFF_Offers` | Offre client générée depuis le métré |
| `lot_CPY__*`, `met_lot_CPY__TenderSupplier1..5`, `cpy__CPY__` | `CPY_Companies` | Fournisseurs (lots) et soumissionnaires d'appel d'offres |
| `lot_CTC__Contacts`, `lot_cpy_CTC__CpyContacts` | `CTC_Contacts` | Contacts liés aux fournisseurs |
| `JCPYCTC_JoinCompaniesContacts` | `JCPYCTC_JoinCompaniesContacts` | Table de jonction société/contact |
| `metl_SOR__` | `SOR_SupplierOrders` | Commande fournisseur (l'en-tête vit dans ShakeDesign) |
| `zval__ZVAL__`, `metl_ZVAL__VAT` | `ZVAL_Values` | Table de valeurs partagée : taux de TVA, codes comptables |

### 3.2 Ce que ShakeDesign va chercher chez ShakeMetre — et c'est plus profond qu'il n'y paraît

- **Le portail visible** : `métré_prj_MET__` (1 seule TO, relation `zkf_PRJ = zkp`) affiche sur la fiche Projet un portail avec les métrés liés (nom, ratio, montants totaux, statuts accepté/verrouillé/archivé). C'est purement en lecture, remplaçable par un appel API.
- **Les clés étrangères stockées (le vrai enjeu)** :
  - `OFF_Offers.zkf_MET` → pointe sur `MET_Metre.zkp`. Chaque Offre créée dans ShakeDesign depuis un métré (script `MET_OFF_CreateClientOffer`) garde une référence permanente vers ce métré.
  - `SOR_SupplierOrders.zkf_MET` → pointe sur `MET_Metre.zkp`. Chaque commande fournisseur créée depuis le métré (script `MET_SOR_CreateCSupplierOrder`) garde la même référence.
  - Et dans l'autre sens : `METL_MetreLines.zkf_SOR` pointe sur `SOR_SupplierOrders.zkp` — chaque ligne de métré assignée à une commande sait à quelle commande ShakeDesign elle appartient.

**Conséquence directe** : il ne s'agit pas seulement de faire "parler" deux applications. Il faut **préserver la valeur littérale des `zkp` de `MET_Metre` et `METL_MetreLines`** lors de la migration, sous peine de casser silencieusement toutes les offres et commandes déjà créées dans ShakeDesign. C'est heureusement facile puisque ces clés sont déjà des UUID texte — la migration peut les copier tel quel dans Laravel.

### 3.3 Ce que cela implique comme flux applicatifs à couvrir

1. Créer une Offre client dans ShakeDesign depuis Laravel (déclenché par un utilisateur qui valide un métré côté web)
2. Créer une Commande fournisseur dans ShakeDesign depuis Laravel (validation d'un lot)
3. Afficher, côté ShakeDesign, la liste des métrés d'un projet avec leurs totaux (le portail actuel)
4. Résoudre, côté Laravel, un Projet/Société/Contact/Taux de TVA à partir de son `zkp` ShakeDesign

---

## 4. Inventaire fonctionnel (scripts et layouts)

*Note technique : l'export XML fourni ne contient pas le détail des étapes de script (`Has_DDR_INFO="False"`) — seulement leurs noms et leur organisation en dossiers. Pour extraire la logique interne exacte de chaque script, il faudra soit régénérer un export DDR complet, soit laisser Claude Code lire les scripts directement depuis une copie du fichier ouverte dans FileMaker (via le presse-papier / Script Workspace), soit vous les décrire un par un au fur et à mesure du portage.*

### 4.1 Domaines fonctionnels identifiés (274 scripts, regroupés)

- **PRJ_Projects** (4) — recherche, navigation vers un projet et son lot
- **MET_Metre** (24) — CRUD, statuts (accepté/site/verrouillé/archivé), génération d'Offre/Commande fournisseur, changement de langue, recalcul des totaux stockés (versions client + `_PSOS`)
- **METL_MetreLines** (45 + 4 vues + 7 copie-de-lignes) — le plus gros domaine : CRUD, sélection multi-critères (par référence/sous-référence/sous-référence-ligne), tri/réordonnancement, export XLSX (7 versions archivées !), recherche rapide, assignation de tags/lots, drag-and-drop
- **METC_MetreLineComponent** (7) — mise à jour des quantités calculées depuis les dimensions
- **METT_MetTenderProcess** (8 + 2 impressions) — processus d'appel d'offres fournisseur : liaison fournisseur, pondération des critères, validation
- **LOT_Lots** (7) — gestion des lots, liaison société/contact fournisseur
- **REF_References** (3) — CRUD du référentiel de postes
- **ZUSR_Users** (5) — gestion de comptes (création, activation, reset mot de passe, édition)
- **GEN_General** (16) — démarrage, gestion des variables globales, navigation, fenêtres
- **BrowserNav** (module tiers, ~12 scripts) — module de navigation par historique (« BrowseNav ») — **à remplacer nativement par le routing Laravel/JS**, ne pas porter
- **EXTERNAL** (2) — navigation croisée vers Offres/Commandes ShakeDesign
- **OLD/** (dossier entier, ~60 scripts) — versions dépréciées, à ignorer sauf besoin d'archéologie
- **TEMP/** (14 scripts) — correctifs ponctuels/uniques déjà appliqués — à ignorer

### 4.2 Layouts (128, regroupés)

- **METL_MetreLines** (21 + 30 impressions) — le domaine le plus riche : la liste complète (`METL_MetreComplete_List_Full`, votre layout critique en performance), ses variantes filtrées (Achats/Ventes/Commandé/Progression), les écrans de validation client/fournisseur, et **30 layouts d'impression** (budgets client simplifié/détaillé/résumé, budget fournisseur, etc. — ce sont vos futurs exports PDF Laravel)
- **METT_Metre Tender Process** (4 + 2 impressions) — écrans d'appel d'offres fournisseur
- **PRJ_Projects** (4), **MET_Metres** (3), **LOT_Lots** (3) — fiches et listes standards
- **MAT_Materials / Interface** (12) — catalogue matériaux, paniers
- **DEV/Raw + DEV/Blank** (38) — layouts techniques de développeur (un par table, format brut) — **ne pas porter**, ce sont des outils de debug FileMaker

---

## 5. Fonctions personnalisées (92) — équivalence Laravel/PHP

La bibliothèque de fonctions personnalisées est un mélange de deux origines : une base d'utilitaires génériques FileMaker (BasePack-like) et un petit set spécifique au projet.

| Catégorie FileMaker | Exemples | Équivalent Laravel |
|---|---|---|
| Manipulation de listes | `list.add`, `list.remove`, `list.unique`, `list.filter`, `AddRemove*` | Collections Laravel natives (`collect()->unique()`, etc.) — **rien à porter** |
| Texte | `SuperTrim`, `Before`, `After`, `Between`, `ParseQuoted` | `Str::` natif ou petites regex — **rien à porter** |
| Wrappers SQL (`sql.GTN`, `sql.GFN`, `sql.Date`, `sql.col`) | Construction de requêtes `ExecuteSQL` | **Obsolètes** : Eloquent/Query Builder fait ça nativement, pas de portage |
| Environnement (`env.isMac`, `env.isServer`, `isHosted`) | Détection plateforme FileMaker | Sans objet en web — pas de portage |
| Privilèges (`priv.Admin`, `priv.Dev`, `isFullAccess`) | Contrôle d'accès | → Policies/Gates Laravel, à reconstruire selon vos rôles métier réels (pas un portage 1:1) |
| Métier spécifique (`PositionFiche`, `PortalSorter`, `RecordIndicator`, `FrontTabsPanelsList`) | Logique UI FileMaker (tri de portail, position sur fiche) | Sans objet — remplacé par le tri Eloquent + état de composant front |
| Dates FR (`DayNameFR`, `MonthNameFR`, `Age`, `AgeCalculation`) | Formatage localisé | `Carbon::setLocale('fr')` + helpers dédiés |
| `xmlGet`/`xmlSet`/`xml2var` | Manipulation XML en calcul | Sans objet — Laravel manipule du JSON nativement |

**Conclusion utile** : sur 92 fonctions personnalisées, la quasi-totalité n'a pas besoin d'être portée — elles compensaient des lacunes du langage de calcul FileMaker que PHP/Laravel n'a pas. Seule la logique *métier* (le petit noyau lié à FR/dates/privilèges) doit être ré-implémentée, pas traduite littéralement.

---

## 6. Architecture cible Laravel

### 6.1 Mapping tables → modèles Eloquent

```
MET_Metre              → Metre (metres)
METL_MetreLines        → MetreLine (metre_lines)
METC_MetreLineComponent→ MetreLineComponent (metre_line_components)
LOT_Lot                → Lot (lots)
REF_Reference          → Reference (references)
REFS_SubReference      → SubReference (sub_references)
REFSL_SubReferenceLines→ SubReferenceLine (sub_reference_lines)
MAT_Materials          → Material (materials)
CAT_Category           → Category (categories)
CATS_SubCategory       → SubCategory (sub_categories)
CART_Carts             → Cart (carts)
JCARTMAT_...           → CartMaterial (cart_materials)   [pivot enrichi, pas un simple pivot]
TAG_Tags               → Tag (tags)
ZSET/ZVAR/ZSTRI        → config Laravel + table `translations` (i18n FR/EN/NL) — ne PAS recréer
                          des tables "globales" FileMaker telles quelles, ce sont des béquilles
                          propres au modèle mono-utilisateur/session de FileMaker.
```

### 6.2 Clés primaires : rester en UUID, partout

Pour les tables déjà en `zkp` texte (`MET_Metre`, `METL_MetreLines`, `LOT_Lot`, `REF_Reference`, `METC_MetreLineComponent`) : **copier les UUID existants tels quels** lors de la migration de données. C'est ce qui permet à `OFF_Offers.zkf_MET` et `SOR_SupplierOrders.zkf_MET` côté ShakeDesign de continuer à fonctionner sans aucune modification.

Pour les tables encore en `zkp` numérique (`MAT`, `CAT`, `CATS`, `CART`, `JCARTMAT`, `TAG`, `REFS`, `REFSL`) : comme rien dans ShakeDesign ne référence directement ces `zkp`, vous êtes libres de générer de nouveaux UUID pendant la migration (recommandé, pour la cohérence du modèle) ou de garder des `bigIncrement` (plus simple, aucun bénéfice réel à changer si rien ne dépend de la valeur).

### 6.3 Les champs `_Stored` : Observers, pas de recalcul à la volée

```php
// app/Observers/MetreLineObserver.php
class MetreLineObserver
{
    public function saved(MetreLine $line): void
    {
        // équivalent de MET_UpdateStoredCalcs_PSOS : recalcul différé
        RecalculateMetreTotals::dispatch($line->metre_id)->afterCommit();
    }
}
```
Un Job en queue (`RecalculateMetreTotals`) recalcule et sauvegarde `total_sales_stored`, `total_ordered_stored`, etc. sur `Metre` — exactement le rôle de vos scripts PSoS actuels, mais sans les coûts d'ouverture de session FileMaker.

### 6.4 API bidirectionnelle avec ShakeDesign

**Sens ShakeDesign → Laravel (lecture du portail Métré sur la fiche Projet)**

Deux options, à choisir selon votre tolérance à la latence et à la dépendance réseau :

- **Option A — ODBC/ESS (recommandée si vous voulez garder un vrai portail natif FileMaker)** : FileMaker Server sait se connecter à une base SQL externe comme "External SQL Source". Vous exposez une **vue MySQL** (`metres_for_project_portal`) avec exactement les colonnes que le layout affiche aujourd'hui (`Name`, `IndProject`, `Ratio_c`, `isAccepted_b`, `isLocked_b`, `Tot_Sum_TotalSales_Stored`, `isArchived_b`...), et vous re-pointez la source de la TO `métré_prj_MET__` vers cette connexion ODBC au lieu du fichier ShakeMetre. Le portail continue à fonctionner nativement, triable/filtrable, sans une ligne de code API. Contrainte : nécessite le driver ODBC MySQL installé sur la machine FileMaker Server (OVH).
- **Option B — synchronisation via Data API** : Laravel écrit (webhook / job) dans une petite table de cache côté ShakeDesign chaque fois qu'un métré change. Plus découplé, tolère les coupures réseau, mais introduit un délai et une duplication de données.

*Recommandation* : commencez par l'Option B (plus rapide à mettre en place, ne dépend pas de la configuration serveur), et migrez vers l'Option A seulement si la latence de sync devient un problème réel en usage.

**Sens Laravel → ShakeDesign (lire un Projet/Société/Contact, créer une Offre/Commande)**

Utiliser la **FileMaker Data API** (REST/JSON native, exposée par FileMaker Server) depuis Laravel via un client HTTP dédié :

```php
// app/Services/ShakeDesign/ShakeDesignClient.php
class ShakeDesignClient
{
    public function findProject(string $zkp): array { /* GET .../layouts/API_PRJ/records/{zkp} */ }
    public function createOffer(array $data): array { /* POST .../layouts/API_OFF/records */ }
    public function createSupplierOrder(array $data): array { /* POST .../layouts/API_SOR/records */ }
}
```
Côté FileMaker, créez des **layouts dédiés à l'API** (`API_PRJ`, `API_OFF`, `API_SOR`, `API_CPY`, `API_CTC`, `API_ZVAL`) exposant uniquement les champs nécessaires — jamais les layouts d'interface existants. Gérez le token de session Data API dans un cache Laravel (Redis, TTL ~14 min, renouvelé automatiquement).

---

## 7. Plan de migration par phases (et comment le donner à Claude Code)

Le piège classique avec Claude Code sur un projet de cette taille : lui donner "recrée ShakeMetre en Laravel" en un seul prompt. Il vaut mieux découper en phases courtes, chacune vérifiable, avec ce document + les deux fichiers JSON joints comme contexte permanent (à mettre dans `CLAUDE.md` ou à référencer explicitement).

### Phase 0 — Contexte (à faire une fois)
Placez ce document et les deux JSON (`ShakeMetre_data_dictionary.json`, `ShakeDesign_boundary_tables.json`) dans le repo, par exemple sous `docs/filemaker-reference/`. Puis :
```
Lis docs/filemaker-reference/ShakeMetre_Analyse_et_Plan_Migration_Laravel.md
et les deux fichiers JSON du même dossier. C'est la référence complète du schéma
FileMaker source. Ne génère rien encore, confirme juste que tu as bien compris
la structure des tables MET_Metre, METL_MetreLines, LOT_Lot et REF_Reference,
et la nature de la frontière avec ShakeDesign (clés zkf_MET stockées dans
OFF_Offers et SOR_SupplierOrders).
```

### Phase 1 — Schéma et migrations
```
En te basant sur ShakeMetre_data_dictionary.json, génère les migrations Laravel
pour les tables suivantes dans cet ordre de dépendance : references, sub_references,
sub_reference_lines, categories, sub_categories, materials, carts, cart_materials,
tags, metres, metre_lines, metre_line_components, lots.
Règles :
- clé primaire uuid partout (Str::uuid() en création)
- les champs `zkf_*` deviennent des colonnes foreignId uuid avec ->constrained()
  SAUF zkf_PRJ, zkf_OFF, zkf_CPY, zkf_CTC, zkf_SOR, zkf_VAT_ae qui pointent vers
  ShakeDesign : ce sont des uuid simples, sans contrainte de clé étrangère locale
- les champs `_Stored` deviennent des colonnes normales (pas des calculs SQL)
- ignore tous les champs zlg_* individuels, remplace par created_at/updated_at
  standard + un champ created_by/updated_by (uuid vers un futur modèle User)
- ignore complètement ZZZ_Template, ZSET_Settings, ZVAR_Variables, ZSTRI_Strings
```

### Phase 2 — Modèles et relations
```
Génère les modèles Eloquent correspondants avec leurs relations, basées sur
le graphe de relations dans ShakeMetre_data_dictionary.json (section relationships).
Ajoute des méthodes d'accès explicites pour les FK cross-système
(ex. Metre::project() qui retourne un objet ProjectReference — pas un modèle
Eloquent local puisque le Projet vit dans ShakeDesign).
```

### Phase 3 — Logique de calcul (le vrai travail)
Ici, ne demandez pas à Claude Code de "deviner" les formules depuis les noms de champs. Donnez-lui les formules réelles extraites (elles sont dans le JSON, champ `calc` de chaque field) :
```
Regarde le champ calc de Tot_Sum_TotalSales_cU, Tot_Sum_TotalOrdered_cU,
Ratio_c et zsm_ProgressClientTotalAmount_Valid_percentage_cU dans
ShakeMetre_data_dictionary.json (table MET_Metre). Traduis cette logique en
un Job Laravel RecalculateMetreTotals qui s'exécute après chaque sauvegarde
de MetreLine, et écrit le résultat sur les colonnes _stored du Metre parent.
```

### Phase 4 — API vers/depuis ShakeDesign
```
Implémente ShakeDesignClient (app/Services/ShakeDesign) qui parle à la
FileMaker Data API. Méthodes : findProject, findCompany, findContact,
findVatValue, createOffer, createSupplierOrder. Utilise Http::  de Laravel,
gère le token de session dans Cache::, et retry automatique en cas de 401
(session expirée).
```

### Phase 5 — Endpoints exposés à ShakeDesign
```
Crée une route API protégée (Sanctum, token machine-à-machine) GET
/api/projects/{zkp}/metres qui retourne les métrés d'un projet avec les
champs name, ratio, total_sales_stored, total_ordered_stored, is_accepted,
is_locked, is_archived — exactement les champs affichés aujourd'hui dans le
portail métré_prj_MET__ sur la fiche Projet FileMaker.
```

### Bonnes pratiques générales pour la suite
- Une conversation Claude Code par domaine fonctionnel (MetreLines, puis Lots, puis Tender Process...), pas une seule conversation de bout en bout — ça évite la dilution de contexte sur un projet de cette taille.
- À chaque nouvelle table/domaine, ré-attachez le JSON ou demandez explicitement à Claude Code de relire les sections pertinentes plutôt que de compter sur sa mémoire de la conversation.
- Pour les 30 layouts d'impression PDF : ne les portez pas un par un dès le départ. Demandez d'abord un moteur générique (Blade + `laravel-dompdf` ou équivalent) capable de produire un des formats (ex. `METL_ClientBudget_Print`), validez-le, puis dupliquez le pattern pour les variantes.
- Les scripts `_PSOS` (Perform Script on Server) n'ont pas d'équivalent direct à porter : c'était une optimisation propre à la latence client-serveur FileMaker. En Laravel, c'est simplement "le code tourne côté serveur" par défaut — ne cherchez pas un pattern miroir, cherchez juste où mettre la logique en Job/queue.

---

## 8. Fichiers livrés

- `ShakeMetre_data_dictionary.json` — dictionnaire de données complet de ShakeMetre : 17 tables, tous les champs (avec formules de calcul complètes), 91 relations, 100 occurrences de table, 274 scripts (organisés par dossier), 128 layouts, 92 fonctions personnalisées, 41 listes de valeurs.
- `ShakeDesign_boundary_tables.json` — sous-ensemble de ShakeDesign : uniquement les 7 tables couplées à ShakeMetre (Projects, Offers, Companies, Contacts, JoinCompaniesContacts, SupplierOrders, Values), avec tous leurs champs.

Ces deux fichiers sont conçus pour être donnés directement à Claude Code comme référence de schéma — ils contiennent les formules de calcul réelles, pas des résumés.

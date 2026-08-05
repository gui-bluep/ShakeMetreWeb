/**
 * Ouvrir un enregistrement ShakeDesign dans le client FileMaker de la personne, par une URL
 * `fmp://`. C'est le pendant web des boutons *_GoTo de l'ancien ShakeMetre.
 *
 * Deux enregistrements sont concernés aujourd'hui : la commande fournisseur (SOR) d'une ligne de
 * métré et l'offre client (OFF) d'un métré. Les deux scripts sont écrits sur le même patron.
 *
 * Ce que faisait la source (ShakeMetre :: SOR_GoTo, lu dans l'export du 28/07) :
 *
 *     Set Variable [ $setter ; xml2var ( Get ( ScriptParameter ) ) ]
 *     If [ IsEmpty ( $SOR ) ]  Exit Script [ -1 ]
 *     Open File [ ShakeDesign ]
 *     Perform Script [ ShakeDesign :: SOR_GoTo ; xmlSet ( "SOR" ; $SOR ) ]
 *
 * et côté ShakeDesign (SOR_GoTo, id 144) : `xml2var` du paramètre, sortie si $SOR est vide,
 * `Go to Layout [SOR_blank]`, recherche sur `zkp = $SOR`, puis `Go to Layout [SOR_Form]`.
 *
 * Une page web ne peut pas enchaîner « ouvrir le fichier » puis « exécuter le script » en deux
 * temps : l'URL fait les deux d'un coup, ce qui est le seul écart avec la source. Le fichier, le
 * script et le format du paramètre sont les siens, à la lettre.
 *
 * Trois choses à savoir avant de débugger un lien qui « ne fait rien » :
 *
 *  - Le compte qui ouvre ShakeDesign doit porter le privilège étendu `fmurlscript` (« Autoriser
 *    les URL à exécuter des scripts FileMaker »). Sans lui FileMaker ouvre le fichier et ignore
 *    le script en silence. L'export ne dit pas quels jeux de privilèges le portent - il ne liste
 *    que l'existence du privilège - donc cela se vérifie dans FileMaker Pro, pas ici.
 *  - Le navigateur demande confirmation avant de passer la main à une application externe.
 *  - Si FileMaker Pro n'est pas installé, il ne se passe rien du tout : rien à intercepter.
 */

/**
 * `xmlSet ( _tag ; _data )` de Fabrice Nordmann, la fonction personnalisée que les deux fichiers
 * utilisent pour se passer des paramètres — l'autre bout est `xml2var`, qui en déclare les
 * variables locales. Le format est littéralement `<TAG>valeur</TAG>`.
 *
 * Aucun échappement : la source n'en fait pas, et les seules valeurs qui transitent ici sont des
 * UUID FileMaker. `isLinkable()` refuse tout le reste plutôt que d'inventer une règle
 * d'échappement que `xml2var` ne saurait pas défaire.
 */
export function xmlSet(tag, data) {
    return `<${tag}>${data}</${tag}>`;
}

/**
 * Un hôte est un nom de machine, éventuellement avec un port ou une adresse IPv6 entre crochets.
 * Il n'est délibérément pas encodé : `encodeURIComponent` transformerait le « : » d'un port en
 * « %3A » et FileMaker ne saurait plus où se connecter. On valide donc au lieu d'encoder.
 */
const HOST = /^[A-Za-z0-9.\-:[\]]+$/;

/** Un zkp FileMaker. Restreint exprès : cette valeur part telle quelle dans le paramètre XML. */
const ZKP = /^[A-Za-z0-9-]+$/;

/**
 * L'URL `fmp://` d'un script de ShakeDesign, ou `null` si quoi que ce soit manque ou déplaît.
 *
 * Renvoyer null plutôt que lever : un lien absent est un intitulé qui reste lisible, alors qu'une
 * URL douteuse envoie la personne dans une erreur FileMaker qui ne nomme pas sa cause.
 *
 * @param {?{host: ?string, database: ?string}} link  La config, telle que la page la reçoit.
 * @param {string} script  Le nom du script dans ShakeDesign.
 * @param {?string} param  Le paramètre, déjà au format xmlSet.
 */
export function scriptUrl(link, script, param = null) {
    const host = link?.host?.trim();
    const database = link?.database?.trim();

    if (!host || !database || !HOST.test(host)) {
        return null;
    }

    // Le nom du fichier, lui, est encodé : il peut porter une espace, que l'URL veut en %20.
    let url = `fmp://${host}/${encodeURIComponent(database)}?script=${encodeURIComponent(script)}`;

    if (param !== null) {
        url += `&param=${encodeURIComponent(param)}`;
    }

    return url;
}

/**
 * Le lien vers un enregistrement ShakeDesign : son script `*_GoTo` et son zkp dans la balise du
 * même nom. Les deux scripts sont écrits sur le même patron, à la balise près, et se comportent
 * pareil - sortie sur un zkp vide, recherche sur `zkp`, passage au formulaire.
 *
 * @param {?{host: ?string, database: ?string}} link
 * @param {string} tag  « SOR », « OFF » : la balise xmlSet, qui nomme aussi le script.
 * @param {?string} zkp
 */
function recordUrl(link, tag, zkp) {
    const id = zkp?.trim();

    if (!id || !ZKP.test(id)) {
        return null;
    }

    return scriptUrl(link, `${tag}_GoTo`, xmlSet(tag, id));
}

/**
 * Le lien vers une commande fournisseur (SOR), ou `null` s'il n'y a pas de commande — une ligne
 * de métré n'en porte pas forcément, et la source masquait déjà son bouton sur
 * `IsEmpty ( zkf_SOR )`.
 *
 * @param {?{host: ?string, database: ?string}} link
 * @param {?string} supplierOrderId  METL::zkf_SOR, le zkp de la commande.
 */
export function supplierOrderUrl(link, supplierOrderId) {
    return recordUrl(link, 'SOR', supplierOrderId);
}

/**
 * Le lien vers une offre client (OFF).
 *
 * `ShakeDesign :: OFF_GoTo` (id 89) est `SOR_GoTo` au mot près, à ceci près qu'il annonce
 * « Offer not found » par un snackbar quand la recherche ne trouve rien, là où SOR_GoTo sort en
 * silence. Rien à faire de ce côté-ci : dans les deux cas, ShakeDesign a la main.
 *
 * @param {?{host: ?string, database: ?string}} link
 * @param {?string} offerId  OFF_Offers::zkp.
 */
export function offerUrl(link, offerId) {
    return recordUrl(link, 'OFF', offerId);
}

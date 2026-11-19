# Contre-revue du plan — T-12 (JD-17), version 2

- **Date :** 19/11/2026.
- **Branche :** `feature/T-12-apprenants`, modifications non commitées.
- **Base :** `develop`, `d6cb56f66417bd882ef521f55c897347411768a7`.
- **Document examiné :** `docs/tasks/PLAN-T-12.md`, version 2, changements `[v2]`.
- **Verdict : Plan validé après corrections mineures.** Les six constats précédents sont corrigés dans le plan. Un détail de validation est à rectifier ; deux suggestions précisent les cas limites sans rouvrir les décisions métier.

## Périmètre et références

Contre-revue limitée aux corrections `[v2]` et à leurs effets de bord. Lecture de `AGENTS.md`, `CLAUDE.md`, du rapport initial `REVIEW-PLAN-T-12.md`, du plan v2 et des précisions inscrites dans `DECISIONS.md`. Vérification ciblée du contrat T-02, de `ClassUsage`, `ReferentialService`, `PasswordTokenService`, `AuditLogger`, du client React `lib/api.ts`, des middleware et conventions d’API existants.

Les décisions 9 à 11 sont appliquées : format CNIE fixé, compétences en masse pour les non-supprimés actifs ou désactivés, compteur de classe incluant toutes les fiches conservées. Les questions métier de la première revue sont donc closes. Aucun réexamen général d’ADM-03 ni des choix précédemment validés ; E-01 à E-04 ne sont pas rouverts.

Aucun code T-12 n’est présent dans le changement examiné. Les statuts ci-dessous portent sur le **plan**, pas sur une implémentation testée.

## Rejeu des six constats

| # | Constat initial | Statut dans le plan v2 | Preuve et section |
|---|---|---|---|
| 1 | Raccord du comptage des classes absent | **Corrigé** | §3, lignes 57–60 : `LearnerClassUsage`, remplacement du branchement provisoire, comptage de toutes les fiches, ordre classes croissantes → compte → fiche, tests avec de vraies fiches et concurrence MySQL. §4 et relais T-13 reprennent le protocole. |
| 2 | CNIE possible dans les filtres journalisés et les URL | **Corrigé** | §5, lignes 89–91 : synthèses sans recherche libre ni filtres bruts ; recherche dans le corps JSON de `POST /learners/search`. §6–7 et Tests couvrent erreurs, collisions de création/modification et synthèses sans CNIE en clair. |
| 3 | Anciens liens conservés après correction d’e-mail | **Corrigé** | §4, `update`, ligne 67 : révocation uniquement si l’adresse change effectivement, dans la transaction et sous le verrou du compte. Relais T-13 et deux tests ancien lien/changement réel ou adresse inchangée repris. |
| 4 | Comptage et confirmation des actions en masse ambigus | **Corrigé** | §5 distingue sélection, éligibilité par action, `{ selected, eligible }`, `expected_eligible`, limite serveur, 409 sans écriture ni audit et nouvelle confirmation explicite. §9 et Tests reprennent le bandeau et la remise à zéro de la sélection. |
| 5 | Référentiels inactifs incomplets à l’écran | **Corrigé** | §6 : identifiant, libellé et état actif des valeurs liées, même inactives. §9 : valeur courante conservée et filtres historiques `?inactives=1`. Tests d’édition et de filtrage repris. |
| 6 | Notification de réinitialisation utilisée à la création | **Corrigé** | §8 : motifs création/réinitialisation et textes distincts, chiffrement et validation finale conservés ; Tests des deux contenus. |

Les décisions métier et les protections demandées sont désormais explicites. Les précisions suivantes concernent leur mise en œuvre et les nouveaux contrats introduits par la v2.

## Effets de bord examinés

### Recherche en POST, CSRF, pagination et cache

Le choix `POST /learners/search` est cohérent avec l’objectif de confidentialité : la recherche n’est plus un paramètre de l’adresse. Il constitue une exception ciblée aux exemples de filtres en query string d’`ARCHITECTURE.md` §6, compatible avec une API JSON sous `/api/v1`. Il n’impose ni nouvelle dépendance ni changement des permissions.

Le client existant `frontend/src/lib/api.ts` prend déjà en charge, pour POST, la demande du cookie CSRF, l’en-tête `X-XSRF-TOKEN`, les cookies de même origine et un renouvellement après 419. Le middleware Sanctum stateful est déjà activé. La nouvelle route devra conserver ces middleware et les contrôles de rôle/permission ; aucune exemption CSRF n’est nécessaire pour une recherche en lecture seule.

La pagination reste faisable avec `page` et les filtres dans le corps JSON, puis les métadonnées de réponse. Le frontend doit envoyer un nouveau POST pour changer de page ; il ne doit pas suivre un lien de pagination par navigation GET ni remettre la recherche dans ces liens. Une lecture POST peut être exécutée par `useQuery` : sa clé doit distinguer page et filtres pour éviter de réafficher les résultats d’une autre recherche. Ces détails sont à vérifier lors de la revue du code ; ils ne rendent pas le choix POST incompatible avec TanStack Query.

Les droits du §2 restent applicables aux trois lectures : recherche, fiche et comptage. L’entreprise reste exclue des routes T-12 malgré `learners.view` ; la Direction n’obtient pas le comptage de sélection réservé aux acteurs des actions en masse. Le corps d’une requête POST n’est pas automatiquement confidentiel vis-à-vis d’un outil qui journaliserait les corps : l’interdiction générale de journal technique contenant une CNIE reste applicable.

### Ordre des verrous et concurrence

L’ordre classes par identifiant croissant → compte → fiche est cohérent pour la création et le changement de classe. `ReferentialService::update` verrouille la classe avant d’appeler le compteur ; un compteur qui lit les lignes `learners` sans verrouiller ensuite les comptes n’introduit pas de cycle inverse avec les actions en masse.

Ces actions verrouillent les comptes par identifiant croissant et ne changent pas l’affectation à une classe. Elles n’ont donc pas à acquérir des verrous de classe après leurs verrous de compte. Les opérations de mot de passe existantes commencent également par le compte ; la relecture prévue au §4 évite de décider à partir d’un état de compte périmé.

Ce protocole réduit les interblocages sans garantir leur absence. La reprise transactionnelle et les tests MySQL restent nécessaires. Deux points d’application à conserver pour la revue du code et le relais T-13 :

- si la classe courante a changé pendant l’attente du verrou, recommencer l’opération avec les bonnes classes à verrouiller ; ne pas prendre tardivement une nouvelle classe après le compte ;
- l’ordre doit être respecté sur toute transaction englobante. Dans T-13, appeler successivement le service pour une classe 20 puis une classe 10 dans la même transaction ne respecte pas l’ordre global croissant. Le futur import devra organiser les acquisitions de verrous en conséquence et prévoir la reprise de sa transaction englobante.

La propriété attendue du test « nombre jamais en retard » doit être comprise au moment du contrôle sous verrou : une création validée avant ce contrôle est comptée ; une création sérialisée après le changement ne peut pas être annoncée à l’avance.

### Cas limites de sélection

| Cas | Lecture du contrat v2 / contrôle à prévoir |
|---|---|
| `ids: []`, mélange `ids`/`filters`, doublons ou identifiants non entiers | Refus 422 prévu par la validation de la sélection ; aucun effet métier. |
| Filtres valides ne donnant aucun résultat | Comptage possible à `{ selected: 0, eligible: 0 }` ; le bouton est désactivé. Précision proposée ci-dessous pour un appel direct d’exécution avec zéro attendu. |
| Sélection non vide, mais `M = 0` | Même bouton désactivé : comptes déjà désactivés pour la désactivation, ou compétence déjà présente pour l’ajout. |
| Nombre éligible passant de M à M+1 ou M−1 | 409, aucune écriture ni audit, nouveau nombre puis confirmation explicite. Vérifier séparément les deux sens, notamment après une attente de verrou. |
| Compétence ajoutée à un apprenant désactivé | Autorisé, conformément à la décision 10. |
| Identifiant supprimé envoyé par la CME | Le compte n’est pas visible à la CME : 422 à l’étape de validation de visibilité, comme pour un identifiant inexistant. Le cas « supprimé ignoré » ne doit pas permettre de contourner cette étape. |
| Identifiant supprimé visible à l’administrateur | Peut être sélectionné, mais n’est éligible à aucune des deux actions. |

Le nombre final comparé doit bien provenir de l’état relu sous les verrous avant toute écriture. Le comptage préalable sert à la confirmation ; il ne constitue pas une réservation de l’ensemble. La v2 corrige le contrat, mais ces propriétés devront être démontrées sur MySQL lors du développement.

### Compléments de tests de la première revue

Ils sont repris :

- **échec du journal au milieu d’une action :** Tests « Masse », rollback des comptes, sessions, liens et compétences ;
- **suppression/restauration :** §4 et Tests, sessions fermées, liens annulés, désactivation antérieure conservée ;
- **état du compte sous son verrou :** §4 et test de concurrence entre réinitialisation, changement de mot de passe et désactivation ;
- **transaction englobante :** §4 et relais T-13, envoi uniquement après validation finale et aucun e-mail après annulation ; le test doit effectivement englober l’appel au service pour couvrir ce cas ;
- **seeder sans référentiels :** §10 et Tests, fiche ignorée avec message, sans création implicite de listes officielles ; relance sans doublon ;
- **erreur → correction → nouvel essai :** §9 et Tests, 422 puis correction et nouvelle tentative, 409 puis confirmation renouvelée ; absence du mot de passe des caches de requêtes et de mutations.

## Nouveaux constats

### 1. Mineur — L’ancre de fin de la règle CNIE n’est pas stricte en PHP

- **Section et ligne :** §1, `docs/tasks/PLAN-T-12.md:46`, règle proposée pour `Cnie::isValid()`.
- **Nature :** comportement confirmé du moteur PCRE local ; risque uniquement si l’implémentation reprend littéralement cette expression sans ancrage strict. Aucun helper T-12 n’est encore écrit.
- **Problème et scénario :** avec l’expression `/^[A-Z0-9]{5,30}$/`, PHP accepte aussi la chaîne fictive `AB12345` suivie d’un saut de ligne LF. L’ancre `$` peut correspondre juste avant ce dernier saut de ligne. Cela contredit la décision 9 : seuls les espaces sont retirés et tout autre caractère doit être refusé.
- **Impact :** identifiant invalide accepté par une règle partagée par la saisie, la recherche et le futur import. Le traitement préalable d’une requête web ne suffit pas à garantir le contrat d’un helper également appelé directement par T-13.
- **Correction proposée dans le plan :** indiquer un ancrage absolu, par exemple `\A[A-Z0-9]{5,30}\z`, ou une option PCRE de fin stricte équivalente. Ajouter un cas avec LF final et un cas avec caractère interdit conservé par la normalisation. Ne pas remplacer cette correction par un `trim()` qui retirerait silencieusement des caractères interdits.
- **Portée :** correction technique mineure, sans nouvel arbitrage sur le format validé.

### 2. Suggestion — Fixer la source de l’action exécutée et expliciter les cas à zéro

- **Section et lignes :** §5, `PLAN-T-12.md:85` et `:87`, rapprochés des routes distinctes du §6.
- **Nature :** précision de contrat ; aucun contournement d’autorisation constaté.
- **Scénario :** le corps d’exécution comporte un champ `action`, alors que la route distingue déjà `/bulk-deactivate` et `/bulk-competences`. Le cas d’un corps annonçant une action différente de la route n’est pas défini. De même, le bouton désactivé avec `M = 0` ne définit pas le résultat d’un appel API direct avec `expected_eligible = 0`.
- **Impact potentiel :** interprétations différentes entre contrôleur, service et tests ; la permission ne doit jamais être choisie uniquement d’après une route alors que le service exécuterait une autre action fournie par le client.
- **Correction proposée dans le plan :** faire déterminer l’action d’exécution par la route, ou exiger explicitement l’égalité entre route et champ `action`. Pour le comptage commun, valider l’action et le droit correspondant. Ajouter le test d’une discordance sans effet métier. Pour zéro attendu et zéro réel, définir une réponse stable sans modification métier ; si le nombre attendu est non nul et le nombre réel devient zéro, conserver le 409 prévu. Reprendre explicitement ces cas ainsi que les identifiants supprimés envoyés par la CME dans la liste des tests.

## Hypothèses H2 et H5

- **H2 : avis favorable sur la nouvelle rédaction.** La limite porte désormais clairement sur les éligibles et les deux actions ; le refus serveur et la mesure MySQL sont prévus. La limite de 2 000 identifiants transmis est une borne de validation distincte, également explicite. La mesure de désactivation de 2 000 comptes doit inclure les sessions, les liens et le journal, avec un résultat exploitable au regard du délai de la requête. Aucune performance n’est démontrée à ce stade ; aucun découpage ni file supplémentaire n’est requis avant cette mesure.
- **H5 : avis favorable.** Même normalisation, comparaison exacte, administrateur seul, restriction également appliquée au comptage et aux actions par filtre. La recherche dans le corps POST et l’exclusion de la recherche libre des synthèses ferment le problème identifié dans la première revue. Le détail d’ancrage du nouveau helper est traité au constat 1.

## Questions au responsable technique

Aucune question métier restante. Les décisions 9, 10 et 11 répondent aux trois questions initiales. L’ancrage de validation et les précisions de contrat ci-dessus relèvent de la rédaction technique du plan.

## Validation et limites

Revue documentaire et lecture ciblée du code existant. **Aucune suite Laravel ou frontend, aucune migration, aucun seeder ni contrôle MySQL exécuté** : aucun code T-12 n’est disponible à tester.

Un contrôle isolé en mémoire, sans fichier ni accès à l’application, a été exécuté avec **PHP 8.5.12 / PCRE 10.44** :

| Chaîne fictive | Expression avec `$` | Expression avec `\A` et `\z` |
|---|---|---|
| `AB12345` | acceptée | acceptée |
| `AB12345` suivi de LF | acceptée | refusée |
| `AB12345` suivi de CR | refusée | refusée |

Ce contrôle démontre uniquement le comportement des expressions ; il ne constitue pas un test du futur helper, de Laravel ou des verrous MySQL. Les tests et mesures annoncés dans le plan restent à exécuter après développement. La validation du plan ne vaut pas approbation du code sans les résultats locaux demandés par `AGENTS.md`.

Aucun fichier existant modifié, aucun commit ni push. Le rapport initial est conservé ; le présent rapport est le seul fichier créé pendant cette contre-revue.

## Verdict

**Plan validé après corrections mineures.** Les six constats initiaux sont corrigés et les trois décisions complémentaires sont cohérentes avec la v2. Rectifier l’ancrage de la règle CNIE et préciser les cas techniques signalés au constat 2. Aucun nouveau constat majeur ou bloquant dans le périmètre examiné.

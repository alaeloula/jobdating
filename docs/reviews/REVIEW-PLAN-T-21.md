# Revue du plan T-21 — Consultation du CV par lien temporaire

Date : 27/11/2026 (Africa/Casablanca). Branche : `feature/T-21-consultation-cv`. Base `develop` : `eab9845a4c506370550bef47cc5b93891ba42181`.

Objet : revue du plan **v1.0**, avant implémentation. Les constats portent sur le contrat proposé et ses omissions ; ils ne constituent pas des bugs reproduits dans une implémentation T-21, qui n'existe pas encore.

## Références et périmètre

Lus : `AGENTS.md`, `CLAUDE.md`, `docs/tasks/PLAN-T-21.md`, `CURRENT_TASK.md` et son diff par rapport à la base ; passages pertinents du CDC V2 (matrice 3.2, ENT-03/RG-CV-01 à 03, APP-03, 7.5, 7.6, 11.6), `DECISIONS.md`, décision 9 révisée du plan T-20 v1.3, architecture 5.2/5.3 et fin de l'entrée T-20 de `PROGRESS.md`.

Code de référence examiné : services CV de T-20, contrôleur CV, modèle CV, ressource apprenant, routes et traitement des exceptions, validité de session, journal d'audit, rôles et permissions ; composants CV, écrans CV/Apprenants, navigation, routes frontend et client API. Les trois maquettes demandées ont été ouvertes. Aucun vrai CV ni fichier de Téléchargements n'a été ouvert.

Les décisions **1 à 4 sont acquises** : lecteur du navigateur intégré au site, entreprise en T-24, journal d'audit existant et durée de 15 minutes. Leurs conséquences techniques sont évaluées sans les remettre en cause.

## Avis sur les propositions 5 à 7

| Proposition | Avis |
|---|---|
| 5 — Lien lié au compte et session obligatoire | **Favorable.** Signer l'identifiant du compte, le comparer à l'utilisateur réellement authentifié et maintenir `auth:sanctum`/`session.valid` sur le service du fichier. Le lien n'est pas un accès public. Ne pas assimiler compte identique à session identique : le plan autorise implicitement une autre session du même compte. |
| 6 — Lien lié à la version | **Favorable au principe, représentation à corriger.** L'empreinte du contenu ne distingue pas deux ajouts successifs du même contenu : constat 3. |
| 7 — Droits relus au service | **Favorable et nécessaire.** Centraliser l'autorisation pour l'émission du lien et le service du PDF. Tester les changements intervenus entre ces deux requêtes. La seule permission `cv.view` est insuffisante : l'entreprise la possède déjà, mais doit rester refusée en T-21. |

Pour le compte cible désactivé, distinguer le lecteur et l'apprenant : la session d'un **lecteur désactivé** est refusée ; cela n'implique pas que l'administrateur, la CME ou la Direction ne puissent plus lire le CV d'un **apprenant désactivé** dont la fiche leur reste visible. Expliciter cette matrice dans le plan. L'apprenant supprimé reste visible seulement par l'administrateur, conformément au périmètre annoncé.

## Constats, du plus grave au moins grave

### 1. Majeur — L'isolement du document annoncé en T-20 n'est pas assuré par le circuit proposé

**Localisation :** `docs/tasks/PLAN-T-21.md:43` et `:48` (§ 3, en-têtes ; § 4, blob et plein écran).

**Défaut confirmé du plan :** ni la CSP proposée ni son repli ne prévoient `sandbox`. `frame-ancestors 'self'` limite les sites autorisés à intégrer le document ; cette directive n'isole pas son comportement. De plus, le PDF est lu par `fetch`, converti en blob puis ouvert dans un cadre ou un nouvel onglet : les en-têtes de la réponse HTTP initiale ne sont pas transférés au nouveau blob. Pour une navigation vers un blob, HTML définit l'héritage des politiques depuis l'environnement créateur. Voir les spécifications [HTML, conteneurs de politiques](https://html.spec.whatwg.org/multipage/browsers.html#policy-containers) et [CSP, sandbox et frame-ancestors](https://www.w3.org/TR/CSP3/#directive-sandbox).

**Scénario :** un PDF autorisé par T-20, dont le contenu actif n'est volontairement plus inspecté, suit le chemin normal `fetch → blob → iframe`, puis « Ouvrir en plein écran ». Le plan ne définit aucune frontière d'isolement applicable à ces deux affichages. Ajouter seulement `sandbox` à la réponse téléchargée par `fetch` ne corrigerait pas le chemin blob.

**Impact :** la protection qui justifie la décision 9 révisée reste indéfinie. Le défaut d'architecture est établi ; une exécution malveillante ou un accès au contexte applicatif n'ont pas été démontrés dans un navigateur et ne sont pas affirmés ici.

**Correction minimale du plan :** définir un circuit d'affichage qui conserve une protection effective du document dans le cadre **et** en plein écran. Une piste à valider en premier est la navigation vers la réponse PDF signée portant une CSP `sandbox`, avec traitement explicite de la session et des erreurs. Si le blob est conservé, décrire séparément l'isolement du cadre et celui du plein écran ; un simple `noopener` ne constitue pas ce mécanisme. Tester le lecteur natif avec les restrictions retenues avant de construire toutes les intégrations. Le repli qui retire la protection sans solution équivalente doit être supprimé du plan. L'affichage dans le site reste acquis.

**Rare / report :** concerne tous les affichages ; le comportement dangereux éventuel dépend du document et du navigateur. **À régler dans T-21**, avant réutilisation en T-24 ; pas de report comme anomalie sans impact.

### 2. Mineur — La date d'ajout de « Mon CV » n'a aucune source API prévue

**Localisation :** `docs/tasks/PLAN-T-21.md:38` et `:51` (§ 3 et § 4).

**Défaut confirmé du contrat :** l'écran apprenant doit afficher la date d'ajout, mais `/me/cv/link` renvoie seulement `{url, expires_at}`. `/me` ne contient pas l'état du CV (`backend/app/Http/Resources/CurrentUserResource.php`) et la fiche `/learners/{id}` est réservée aux rôles administrateur/CME/Direction. La date d'expiration du lien ne remplace pas la date d'ajout.

**Scénario et impact :** un apprenant ouvre « Mon CV » avec un CV existant : le lecteur peut obtenir les octets, mais le bandeau « ajouté le [date] » ne peut pas être renseigné avec les réponses définies. APP-03 et la maquette ne sont pas entièrement couverts.

**Correction minimale :** inclure un état minimal, par exemple `cv: {added_at, source}`, dans la réponse `/me/cv/link`, ou définir une route dédiée d'état personnel. Préciser aussi le résultat sans CV, sans exposer les données de la fiche administrative. Tester date et absence de CV sur l'écran apprenant.

**Rare / report :** cas nominal de chaque apprenant avec CV ; pas de risque de confidentialité si la date est simplement absente, mais fonction T-21 incomplète. À préciser maintenant, sans report.

### 3. Mineur — Une empreinte de contenu ne révoque pas définitivement une ancienne version de lien

**Localisation :** `docs/tasks/PLAN-T-21.md:24` et `:41` (décision 6 et génération du lien).

**Défaut confirmé de la formule proposée :** T-20 remplace le CV en conservant l'identifiant de `cv_documents` (`backend/app/Services/Cv/CvService.php`, mise à jour de `$current`). Le lien utilise cet identifiant et les 16 premiers caractères du SHA-256.

**Scénario :** dans les 15 minutes, téléverser A, émettre son lien, remplacer par B, puis remettre A. L'identifiant du document et l'empreinte redeviennent ceux du premier lien ; la session et la signature restant valides, la comparaison prévue accepte à nouveau ce lien. Aucun besoin de collision cryptographique.

**Impact :** l'engagement « après un remplacement, un ancien lien renvoie 404 » n'est pas tenu. Il ne s'agit pas d'une fuite d'une ancienne version : A est alors de nouveau le contenu courant.

**Correction minimale :** utiliser un identifiant de version renouvelé à chaque remplacement effectif, par exemple un UUID dédié, dans le lien et le journal. Conserver le comportement T-20 « fichier identique au CV courant → inchangé », qui ne crée pas de nouvelle version. Ajouter le test A → B → A.

**Rare / report :** rare, sans accès supplémentaire aux données ni blocage propre à T-24. Report envisageable dans `docs/ERREURS_A_CORRIGER.md` si le responsable accepte explicitement la sémantique « empreinte du contenu courant » ; sinon corriger le plan avant développement. Aucun report écrit durant cette revue.

### 4. Mineur — Le cycle de vie de la visionneuse ne couvre pas le remplacement pour le même apprenant

**Localisation :** `docs/tasks/PLAN-T-21.md:48` à `:50` (§ 4).

**Omission de conception ; bug d'exécution non confirmé :** le plan traite la fermeture et le changement d'apprenant, mais ne dit pas quand recharger ou supprimer le blob si le CV de ce même apprenant est remplacé ou retiré. Le fonctionnement existant de `useCvOperations` invalide la liste et l'historique ; il ne définit pas encore de signal de renouvellement pour `CvViewer`.

**Scénario :** ouvrir le CV A, puis utiliser « Remplacer » ou « Retirer » dans le panneau du même apprenant. Une visionneuse chargée seulement au montage ou au changement d'identifiant conserverait A, avec une date éventuellement actualisée par la liste. Une réponse de chargement de A arrivée après le remplacement pourrait également réinstaller ce contenu.

**Impact :** incohérence entre l'état affiché et l'aperçu ; conservation inutile d'un ancien document déjà reçu. Le serveur peut parfaitement invalider l'ancien lien sans mettre à jour ce blob local. L'expiration du lien n'est pas, à elle seule, un mécanisme de rappel des octets déjà reçus.

**Correction minimale :** définir l'identité de chargement par apprenant **et version**, invalider les requêtes et l'aperçu après une mutation locale, révoquer les URL remplacées et empêcher les réponses tardives de rétablir la version précédente. Définir aussi le propriétaire et la durée de vie du blob du plein écran : fermer le panneau ne doit pas compromettre son chargement dans un autre onglet. Tester ces enchaînements, y compris après « Réessayer ».

**Rare / report :** remplacement/retrait pendant consultation plausible dans les écrans de gestion, sans fuite vers un autre utilisateur démontrée. À couvrir dans T-21 ; les mêmes composants seront repris en T-24.

## Points vérifiés sans remarque de principe

- La couverture des quatre rôles traités correspond à la matrice : administrateur/CME/Direction en lecture, apprenant pour son seul CV ; aucun téléversement apprenant. L'entreprise est explicitement reportée en T-24.
- Le stockage privé chiffré et `CvStorage::read()` fournissent la base du service ; un chemin de stockage ne doit jamais être accepté depuis la requête. Aucun besoin de dépendre de la plateforme CME pour consulter un CV déjà conservé.
- Un lien signé de 15 minutes, lié au compte et contrôlé au service, couvre RG-CV-03 et l'expiration de 11.6. `expires` doit être validé avec l'heure du serveur ; l'horloge du navigateur ne doit servir qu'à l'affichage ou à une anticipation de renouvellement.
- `no-store, private`, le type PDF, `nosniff` et le nom généré sont appropriés. Le nom ASCII doit aussi neutraliser séparateurs, caractères de contrôle et noms vides, en utilisant le constructeur de disposition du framework.
- Le choix du journal d'audit permet de conserver auteur, sujet apprenant, date et IP ; il faut ajouter les actions à `AuditAction`, leur catégorie, leurs libellés et filtres. L'écart avec `cv_access_logs` est documentaire, puisque la décision 3 est acquise.
- Aucun journal à la seule émission d'un lien ; journal après lecture/déchiffrement réussis et contrôle des droits. Conserver uniquement des références métier/version/source, sans URL signée, nom de fichier d'origine, chemin ni contenu.
- Les tests annoncés couvrent une bonne base : cinq rôles, session absente, signature modifiée, expiration, autre utilisateur, retrait/remplacement, octets, erreurs de lecture et absence de téléversement apprenant.

## Précisions et tests à ajouter au plan

**Signature et téléchargement.** La mention « `download` hors signature » doit se traduire par une exception explicite et limitée à ce paramètre lors de la validation. Une vérification locale avec le `UrlGenerator` installé donne : lien initial valide ; ajout de `download=1` avec validation ordinaire **invalide** ; validation avec `['download']` dans les paramètres ignorés **valide**. Définir les valeurs acceptées et tester que `user`, `cv`, `version` et `expires` restent protégés. L'[API officielle Laravel 12](https://api.laravel.com/docs/12.x/Illuminate/Routing/UrlGenerator.html#method_hasValidSignature) expose cette liste d'exclusion. Cette précision complète le choix proposé, sans imposer de signer `download`.

**Erreurs et client frontend.** Définir des codes distincts pour expiration, signature invalide, version obsolète et refus de droits. Le client JSON existant émet un événement global sur tout 403, et `ApiEventsListener` renvoie à l'accueil ; il ne sait pas non plus lire une réponse binaire. Le chargement PDF doit avoir un traitement explicite : expiration seule → renouvellement unique, véritable refus → comportement d'autorisation, 401 → fin de session, 404 de version/retrait → aperçu vidé, 503/réseau → Réessayer. Tester une deuxième expiration et l'absence de boucle ou de redirection prématurée.

**Droits après émission.** Tester séparément perte de rôle/permission, désactivation ou suppression du lecteur, suppression de l'apprenant cible, et lien demandé par un administrateur pour un apprenant supprimé puis effectivement servi. Vérifier des statuts précis, plutôt que « entreprise : 403/404 » indifféremment. Tester le CV d'un autre apprenant via la route fichier, pas seulement via les routes de création de lien. Si le circuit retenu utilise une navigation HTTP directe, vérifier aussi une URL collée dans un nouvel onglet : Sanctum active actuellement la session API selon l'origine/référent de la requête.

**Journal.** Vérifier exactement une ligne par service applicatif réussi, aucune après refus, fichier absent ou déchiffrement impossible ; tester aussi le téléchargement et une panne d'écriture du journal. Définir le succès comme un service autorisé et préparé, pas comme une preuve que le client a reçu tous les octets. Les sauvegardes locales depuis la barre du lecteur ou le menu du navigateur après réception d'un blob ne repassent pas nécessairement au serveur : préciser cette limite plutôt que promettre un événement réseau pour chaque sauvegarde locale.

**Mémoire.** L'estimation de 14 Mo au § 6 ne suffit pas pour dimensionner le service. Sur PHP Windows 8.5.12, avec un PDF fictif de 5 242 880 octets et l'`Encrypter` Laravel installé en AES-256-CBC : charge chiffrée **9 320 872 octets**, mémoire allouée avant déchiffrement **24 Mio**, pic **46 Mio**, soit **22 Mio supplémentaires** pendant le déchiffrement seul. C'est une mesure indicative de CLI, pas celle d'une requête Docker complète ; elle établit que 14 Mo ne constitue pas une borne sûre. Prévoir une mesure HTTP sous PHP 8.4 et documenter les copies simultanées côté navigateur, notamment aperçu et téléchargement.

**Navigateurs et mémoire frontend.** Prévoir une recette avec un PDF fictif de plusieurs pages sur Chrome, Edge, Firefox, Safari, Android et iOS : aperçu, plein écran, téléchargement, nom du fichier, fermeture du panneau, erreurs et restrictions retenues au constat 1. Tester la révocation des URL à la fermeture, au changement de version, sur résultat tardif et après téléchargement ; traiter le double clic. Les tests React avec blobs simulés ne prouvent pas le fonctionnement du lecteur PDF natif.

## Découpage, échéance et questions au responsable technique

Le périmètre reste raisonnable avant le **25/12/2026**, à partir du 27/11, sous réserve de la disponibilité de l'équipe et des autres tâches. Aucune estimation de charge certaine n'est possible avec ce seul plan. Le principal risque est l'interaction lecteur natif/isolement/gestion des erreurs, qui doit être vérifiée en premier.

Je propose de placer une étape courte de validation du circuit d'affichage sécurisé avant les trois étapes existantes, puis backend commun d'autorisation/liens/fichier/journal, frontend partagé et intégrations, enfin régression et recette navigateur. T-24 ajoutera le périmètre entreprise au contrôle commun sans ouvrir ses accès dans T-21.

Questions à trancher dans la prochaine version du plan :

1. Quel circuit d'isolement protège aussi le plein écran tout en conservant le lecteur natif intégré ? Les navigateurs cibles et le matériel de recette sont-ils disponibles dès cette validation ?
2. Confirmez-vous une version d'ajout distincte de l'empreinte de contenu, ou acceptez-vous explicitement le cas A → B → A ?
3. Confirmez-vous la lecture du CV d'un apprenant désactivé par les trois rôles de gestion/consultation, et la limite de traçabilité des sauvegardes locales du lecteur natif ?

Ces questions ne suspendent pas la remise du rapport et ne demandent pas de réapprouver les décisions 1 à 4.

## Validation locale et limites

Exécutés : vérification de la branche et de la base, lecture des références et du code, comparaison du document suivi à la base, inspection des trois maquettes, essai autonome du générateur de signatures et mesure de déchiffrement ci-dessus, `git diff --check` sans erreur d'espacement (avertissement LF/CRLF sur `CURRENT_TASK.md`). Les essais autonomes utilisent uniquement des valeurs fictives, sans accès à la base ni modification du code.

Aucune suite backend/frontend, aucun Pint/PHPStan/build et aucune recette navigateur n'ont été exécutés pour T-21. Aucun test T-21 n'existe encore. Résultats T-20 **communiqués dans `PROGRESS.md`, non rejoués ici** : Pint/PHPStan sans erreur ; 44 tests CV et suite backend complète de 599 tests réussis, 3144 assertions ; frontend lint, 129 tests et build réussis. Ces résultats décrivent la base T-20 et ne valident pas T-21.

Les sources web primaires citées ont été consultées pour la sémantique CSP/blob et l'API Laravel. Aucun comportement propre à un lecteur PDF ou à Safari n'est présenté comme testé. Aucun fichier de code ou de configuration modifié, aucun commit ni push ; seul livrable ajouté : ce rapport.

## Verdict

**Plan à revoir.**

Le périmètre métier et les propositions 5/7 sont cohérents. Le circuit de protection du PDF doit être défini avant implémentation ; compléter aussi la réponse nécessaire à « Mon CV », la représentation de version et le renouvellement de l'aperçu. Les décisions déjà validées restent inchangées.

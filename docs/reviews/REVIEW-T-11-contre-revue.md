# Contre-revue indépendante — T-11 (JD-16) : entreprises

- **Date :** 19/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-11-entreprises`.
- **Base et HEAD :** `develop`, `bde6a2a70078930be9bb5038f807fd95ec54ce2d`.
- **État :** travail non commité ; diff avec `develop` et fichiers non suivis concernés examinés.
- **Livrable :** ce rapport uniquement. Aucun code ni test modifié ou créé dans le dépôt, aucun commit ni push. Le rapport initial est conservé.

## 1. Périmètre

Consignes : `AGENTS.md`, `CLAUDE.md` et demande de contre-revue. Références : `REVIEW-T-11-entreprises.md`, paragraphe « Revue du code par Codex… » de l'entrée T-11 de `PROGRESS.md`, plan T-11 actualisé et décisions du 19/11.

Examen limité aux trois corrections, à leurs effets de bord et aux changements de liste demandés après recette. Fichiers principaux : `CompanyService.php`, `CompanyController.php`, `CompaniesPage.tsx`, tests Companies backend/frontend ; lecture complémentaire du gestionnaire de transactions Laravel, du formulaire et du seeder.

La décision de saisir les profils **après création** est appliquée : pas de remise en cause de cette option. Le reste de T-11 et E-01 à E-03 ne sont pas réaudités. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Rejeu des constats initiaux

| Constat | Statut | Preuve indépendante |
|---|---|---|
| 1 — Logos et transaction englobante | **Partiellement corrigé** | Les scénarios initiaux avec un seul niveau englobant passent : ancien fichier conservé sur rollback, nouveau supprimé, compte/fiche annulés. La perte de l'ancien logo n'est plus observée. Avec deux niveaux englobants dont l'intermédiaire est validé, un nouveau fichier reste cependant orphelin après le rollback final : constat 4 ci-dessous. |
| 2 — État d'impression repris par la fiche suivante | **Corrigé** | Deux resets sans fermeture : version 2 confirmée, puis version 3 affichée sans ancien message et avec bouton de confirmation actif. Les confirmations envoyées portent successivement **2 puis 3**. |
| 3 — Profils ignorés à la création | **Corrigé selon l'option (a)** | Chacun des deux champs envoyé seul, rempli, vide, nul, chaîne vide, scalaire ou objet : **422** avec erreur sur le champ. Aucune entreprise ni compte créé. Sans ces champs : **201** ; saisie ultérieure par `PUT /profiles` : **200**. |

Le callback de rollback enregistré avant la transaction du service corrige bien l'ancien cas à un niveau. La clé `${entreprise}-${version}` remonte effectivement la fiche. La règle `missing`, contrairement à `prohibited`, refuse aussi une liste vide ; les messages indiquent en français la saisie après création.

## 3. Effets de bord contrôlés

### Logos

Contrôles du service réel sur MySQL, avec un disque simulé en mémoire et comptage des suppressions :

| Cas | Résultat |
|---|---|
| Remplacement sans transaction englobante | Ancien supprimé une fois, nouveau conservé. |
| Création puis rollback de l'appelant | Compte et fiche absents, nouveau fichier supprimé une fois. |
| Remplacement puis rollback de l'appelant | Ancien présent avant le rollback et après ; ligne restaurée vers lui ; nouveau supprimé une fois. |
| Suppression puis rollback de l'appelant | Ancien conservé, aucune suppression du fichier. |
| Remplacement puis commit de l'appelant | Ancien encore présent avant commit, supprimé une seule fois après ; nouveau conservé. |
| Deux remplacements dans la même transaction, puis commit | Tous les fichiers restent jusqu'au commit ; ancien et intermédiaire supprimés chacun une fois ; dernier conservé. |
| Deux remplacements, puis rollback | Ancien conservé et référencé ; les deux nouveaux supprimés chacun une fois. |

Deux limites supplémentaires ont été reproduites : annulation finale après commit d'un niveau intermédiaire, et double appel de suppression après échec interne suivi d'un rollback englobant. Elles sont détaillées au constat 4.

Le chemin d'échec interne garde son `catch` de nettoyage. Le reste du stockage et de la validation des images, déjà revu, n'est pas repris ici.

### Fiche et création

- Ancienne version confirmée après reset : **409**, code `stale_credentials`. La nouvelle version peut être confirmée en **200**. Le contrat serveur reste cohérent avec le remontage du composant.
- Après les deux confirmations frontend, le secret contrôlé est absent des données de queries, des données et variables des mutations TanStack.
- Le formulaire construit son `FormData` avec les champs de fiche, le nom d'utilisateur et le logo éventuel ; aucun champ de profils n'y est ajouté. Ce point a été vérifié par lecture du code.
- Le seeder de démonstration crée directement la fiche par le modèle et ne passe pas par `CompanyController::store` : la nouvelle validation ne casse pas cette voie. Le seeder n'a pas été exécuté dans cette contre-revue.

## 4. Changements d'interface

**Vérifiés dans le composant réel en DOM simulé :**

- avatar avec `h-12 w-12`, cercle et `object-contain` : 3 rem, soit 48 px avec la base de 16 px ;
- texte alternatif « Logo de [entreprise] » présent ;
- initiales affichées lorsque `has_logo` est faux ; les initiales décoratives sont masquées aux lecteurs d'écran, le nom complet restant présent dans la ligne ;
- badges de filière et compétence visibles, liste nommée par `aria-label` ; aucun secret ni coordonnées de recruteur dans ces badges ;
- « Aucun profil recherché » pour une entreprise sans sélection.

Le repli après échec de chargement n'est pas implémenté : constat 5. Le contraste des badges actifs est élevé d'après les couleurs du thème : environ **9,02:1** pour les filières et **7,19:1** pour les compétences. Le petit texte de l'état vide est beaucoup plus clair : suggestion 6. Ces ratios sont calculés à partir du thème Tailwind, sans mesure d'écran ni audit visuel complet.

## 5. Qualité des nouveaux tests

- **`CompanyTest::test_logo_et_transaction_englobante_annulee()`**, ligne 306 : il aurait échoué sur le code initial, dès la vérification que l'ancien fichier existe après rollback. Il vérifie aussi la création annulée et le remplacement validé. Il ne couvre pas un niveau intermédiaire validé avant annulation finale, ni le nombre d'appels de suppression après échec interne.
- **`test_profils_refuses_a_la_creation()`**, ligne 211 : il aurait échoué sur l'ancien code, qui répondait 201. Son cas de liste vide a effectivement détecté l'insuffisance de `prohibited`, d'après le résultat communiqué. Les bancs indépendants étendent la vérification aux deux champs et aux autres formes de valeur présente.
- **Test « deux réinitialisations sans fermer la fiche »**, ligne 142 : il aurait échoué sur l'ancien composant. L'attente du **nouveau** secret évite d'asserter trop tôt sur l'ancienne fiche encore visible pendant la requête. Le rejeu indépendant confirme aussi l'envoi de la deuxième confirmation et l'absence du secret en cache.
- **Test des profils visibles dans la liste**, ligne 173 : il aurait échoué sur l'ancien affichage sans badges. Il vérifie le cercle, l'absence de profils et les initiales sans logo, mais ne déclenche jamais l'erreur de l'image : il passe malgré le constat 5.

Aucune ancienne version n'a été installée pour relancer ces tests. Leur capacité de détection est étayée par la lecture et par les reproductions de la revue initiale. Les commandes PHPUnit/Vitest communiquées n'ont pas été relancées ici.

## 6. Constats complémentaires

### 4 — Le nettoyage reste incomplet avec plusieurs niveaux englobants

- **Sévérité : Mineur**, résidu du constat 1. La perte de l'ancien fichier, qui motivait la sévérité majeure initiale, n'est plus observée.
- **Fichier et lignes :** `backend/app/Services/Companies/CompanyService.php:337–340`, appels avant les transactions de `create()` et `replaceLogo()`.
- **Qualification : bug confirmé** sur MySQL avec stockage en mémoire.
- **Référence :** contrat de commit final et absence de fichier orphelin, plan v2 et demande de contre-revue, partie 3.B.

**Scénario :** ouvrir une transaction globale, puis une transaction intermédiaire ; appeler `create()` ou `replaceLogo()` ; valider l'intermédiaire ; annuler ensuite la globale.

**Résultat :** pour une création, compte et fiche disparaissent mais le nouveau logo reste, avec **zéro appel de suppression**. Pour un remplacement, la base et l'ancien fichier sont correctement restaurés, mais le nouveau reste orphelin. Le callback a été enregistré sur l'intermédiaire : sa validation lui fait perdre la compensation de rollback, comme l'explique déjà le commentaire pour la transaction du service. Déplacer l'enregistrement d'un niveau ne couvre donc pas tous les niveaux.

**Autre effet observé :** si l'audit échoue dans `replaceLogo()` avec une transaction englobante, le `catch` supprime le nouveau fichier une première fois, puis le callback le supprime à nouveau au rollback de l'appelant : **deux appels**. Aucune perte supplémentaire n'a été observée avec la suppression idempotente ; cela montre néanmoins que la compensation n'est pas consommée après son premier usage.

**Impact :** fichiers privés orphelins après annulation ; nettoyage redondant lors de certains échecs. Les données SQL et l'ancien logo sont désormais préservés dans les scénarios contrôlés.

**Correctif minimal :** faire survivre la compensation jusqu'à l'annulation finale tout en couvrant une annulation intermédiaire, avec une exécution unique partagée entre callback et `catch`. Ajouter les deux niveaux englobants, le rollback après commit intermédiaire et l'échec interne suivi du rollback de l'appelant aux tests.

**Fréquence et report :** cas moins fréquent, lié à la composition des transactions. Le futur import T-13 est précisément un consommateur concerné ; sa structure d'imbrication n'est pas encore écrite. Ce cas ne doit donc pas être classé sans impact sur les tâches suivantes par défaut.

### 5 — Une image en échec ne laisse pas place aux initiales

- **Sévérité : Mineur.**
- **Fichier et lignes :** `frontend/src/pages/CompaniesPage.tsx:13–20`.
- **Qualification : bug confirmé dans le DOM simulé**, par événement d'erreur de chargement.
- **Référence :** changement de recette demandé, partie 3.C : initiales si aucun logo **ou si l'image ne charge pas**.

**Scénario :** une entreprise porte `has_logo: true`, mais sa requête de logo échoue, par exemple lors d'une indisponibilité réseau. Déclencher l'événement `error` sur l'image.

**Résultat :** l'image reste rendue, aucune initiale n'apparaît. Le composant ne tient compte que de `has_logo` et ne possède pas de traitement `onError`.

**Impact :** avatar cassé à la place du repli demandé ; le nom de l'entreprise reste lisible. Aucun effet sur les droits ou les données.

**Correctif minimal :** mémoriser l'échec de l'image et utiliser les initiales ; réinitialiser cet état lorsque la source pertinente change. Ajouter un test avec `fireEvent.error`.

**Fréquence et report :** dépend d'un échec d'image, sans dépendance bloquante identifiée pour les tâches suivantes. Report possible si le responsable technique le valide, sans le présumer ici.

### 6 — Renforcer le contraste des petits textes secondaires

- **Sévérité : Suggestion.**
- **Fichier et lignes :** `frontend/src/pages/CompaniesPage.tsx:39` et `:44–49`.
- **Qualification : mesure calculée sur les couleurs du thème**, pas une mesure de rendu en navigateur.
- **Référence :** vérification du contraste minimal demandée en partie 3.C.

**Observation :** « Aucun profil recherché » utilise `text-xs text-slate-400` sur fond blanc, soit environ **2,63:1**. Les badges inactifs en slate-500/slate-100 donnent environ **4,35:1**. Le texte de l'état vide est particulièrement peu contrasté.

**Impact :** lecture moins aisée des petits libellés secondaires.

**Correction minimale proposée :** choisir une teinte plus sombre pour ces textes, en conservant les fonds et le barré des valeurs inactives. Il s'agit d'une amélioration visuelle, sans impact sur les tâches suivantes ; le contraste est constant, pas un cas rare.

## 7. Validation et limites

### Contrôles exécutés par cet auditeur

Environnement constaté : **PHP 8.4.27 dans Docker**, **MySQL 8.0.46**, base **`jobdating_test`**, collation de connexion **`utf8mb4_unicode_ci`** ; **Node 24.21.0** côté frontend. Aucun SQLite.

Bancs PHP en mémoire via `docker compose run --rm -T php php -r …`, données fictives et tables préfixées `review_t11_<identifiant>_`. Les copies conservent colonnes/index, pas les clés étrangères ni les déclencheurs : aucune conclusion nouvelle sur ceux-ci. Le disque simulé compte les opérations sans créer de fichier réel. Les contrôles HTTP de validation et de version traversent le noyau Laravel avec cookies.

Banc frontend avec la page et les composants réels, React/TanStack et DOM simulé ; seule l'API est remplacée par des réponses conformes au contrat. Calculs de contraste à partir des couleurs OKLCH du thème converties en luminance ; pas de contrôle WCAG complet ni d'impression physique.

`git diff --check develop` réussit. Les tables de contrôle sont nettoyées en `finally` ; la recherche finale des tables préfixées `review_t11_` est vide. Aucun fichier de contrôle créé dans le dépôt. Le reste de T-11 n'a pas été retesté.

### Résultats communiqués par Claude, non rejoués par cet auditeur

Résultats du **19/11/2026** :

- Pint réussi ; PHPStan sans erreur.
- Suite complète Docker : **366 tests exécutés, 365 réussis et 1 échec** sur la liste vide acceptée par `prohibited`.
- Après remplacement par `missing` : **25 tests Companies réussis**. **La suite complète n'a pas été relancée après cette correction** ; ce rapport ne présente donc pas 366 tests réussis sur la tête actuelle.
- Frontend : lint réussi, **58 tests réussis**, build réussi.
- Navigateur : liste avec logos ronds, initiales et badges.

Aucune de ces commandes n'a été relancée. Les résultats indépendants sont ceux détaillés dans ce rapport.

## 8. Verdict

**À fusionner après corrections mineures.**

Les constats 2 et 3 sont corrigés. La perte de l'ancien logo du constat 1 est corrigée, mais le nettoyage doit encore couvrir plusieurs niveaux englobants. Compléter également le repli d'image ; le renforcement du contraste reste une suggestion.

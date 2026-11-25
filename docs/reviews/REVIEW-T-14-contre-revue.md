# Contre-revue du code T-14 — Événements, participants et emplacements

- **Date :** 25/11/2026, fuseau `Africa/Casablanca`.
- **Branche :** `feature/T-14-evenements`, changements toujours non commités.
- **Base et HEAD :** `develop`, `1b05242254e94e4843dd414d22de80621cec6f29`.
- **Rapport initial conservé :** `docs/reviews/REVIEW-T-14-evenements.md`.
- **Références :** prompt de contre-revue, `AGENTS.md`, `CLAUDE.md`, plan v1.4, décisions du 23/11, paragraphe des corrections dans l’entrée T-14 de `PROGRESS.md`.

## Périmètre

Contre-revue des six constats et des effets de bord de leurs corrections, à partir du diff avec `develop` et des fichiers non suivis concernés. Le reste de T-14 n’est pas réexaminé intégralement. Les décisions 1 à 14, le libellé « Ajouter des entreprises existantes » et le report de l’import entreprises à T-64 sont appliqués.

Fichiers principalement examinés : `events/api.ts`, `ParticipantsPanel`, `LocationEditor`, `LocationsPage`, condition de suppression dans `EventsPage`, `EventService`, règles HTTP des coordonnées, `EventTest`, `EventConcurrencyTest` et configuration des tests.

Aucun code ni autre document modifié, aucun commit ni push. Seul ce rapport est ajouté au dépôt. Les bancs complémentaires sont placés hors dépôt et utilisent uniquement des données fictives. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun fichier réel ni accès aux données de développement `jobdating` ; E-01 à E-06 ne sont pas rouverts.

## Rejeu des constats

**Lecture des statuts :** « corrigé » désigne ici le traitement de la cause dans le code. La preuve exécutée est précisée pour chaque ligne. Les scénarios HTTP backend n’ont pas pu être rejoués indépendamment dans Docker ; ils ne sont pas présentés comme exécutés.

| # | Statut | Scénario et résultat de la contre-revue |
|---|---|---|
| 1 — Valeurs de E1 enregistrables dans E2 | **Corrigé** | `api.ts:132` conserve les lignes précédentes uniquement à événement identique. Le nouveau test frontend diffère la réponse de E2, vérifie la disparition des lignes/formulaires de E1, puis les valeurs de E2 ; il passe localement. Les clés de `LocationEditor` incluent désormais l’événement dans les deux écrans. Le test de changement automatique du courant passe aussi. Deux contrôles complémentaires confirment que le succès ou l’erreur d’un ancien formulaire démonté n’altère pas le brouillon de B. |
| 2 — Suppression d’une archive | **Corrigé, vérification backend par lecture** | `EventService.php:197` refuse l’archive sous le verrou avant toute suppression ; `EventsPage` retire le bouton. Le test frontend « ne propose pas la suppression » passe. Le test backend renforcé exige 409 `event_archived`, événement conservé et participation conservée ; ce scénario HTTP n’a pas été rejoué par moi à cause de Docker. |
| 3 — Coordonnée isolée après PATCH | **Corrigé** | `EventService.php:68` contrôle la paire effective sous verrou ; `assertCoordinates`, lignes 230–238, distingue absence de champ et valeur nulle. Le test backend ajouté couvre les deux effacements partiels refusés et l’effacement complet autorisé, mais n’a pas été rejoué dans Docker. Un contrôle de la méthode réelle, en mémoire sous PHP Windows, valide neuf cas dont zéro, chaînes numériques, absence et effacement ; il ne remplace pas un test HTTP/MySQL. |
| 4 — Ancien nom complet incorrect dans le journal | **Corrigé, vérification backend par lecture** | `$before` est capturé à `EventService.php:71`, avant le changement d’année ligne 84. Le test ajouté vérifie les deux noms complets lors d’un changement d’année, puis d’un changement combiné de nom et de date. Assertions et placement du correctif cohérents ; test backend non rejoué par moi. |
| 5 — Succès tardif de A effaçant la sélection de B | **Corrigé** | Les remises à zéro sont déplacées dans le callback de succès, exécuté seulement si la génération correspond (`ParticipantsPanel.tsx:74`). Le nouveau test frontend conserve la sélection de B après le succès de A et passe localement. Un contrôle complémentaire A → B → A confirme qu’une ancienne réponse ne libère pas le `busy` du nouvel ajout. |
| 6 — Preuves de concurrence et matrice trop permissives | **Partiellement corrigé** | `posix` est déclaré et les attentes sont bornées. La matrice vérifie maintenant un statut exact et annule les mutations entre rôles. En revanche, `release()` accepte une requête stable pendant 300 ms sans prouver qu’elle attend un verrou ni qu’elle appartient à l’enfant. Un contrôle du helper réel avec réponses de base substituées confirme ce faux positif logique ; voir R1. |

## Effets de bord

### Filtres, changements d’événement et réponses tardives

Le maintien des anciennes lignes lors d’un changement de **filtre du même événement** reste cohérent : les actions ciblent toujours la même participation. Un contrôle avec réponse de filtre différée conserve une ligne de A, modifie son emplacement et constate un PATCH visant bien A. Le changement de filtre ne réintroduit donc pas le mélange d’éditions du constat 1 dans le scénario rejoué.

`ParticipantsPanel` remet son état à zéro quand l’identifiant effectif change, y compris après actualisation du courant sans sélection manuelle. La remise à zéro pendant le rendu évite de conserver un panneau ou une confirmation d’un autre événement. La génération change ensuite dans l’effet.

Le retour **A → B → A** a été contrôlé avec deux demandes d’ajout distinctes : le résultat de la première demande de A n’efface pas la sélection de la nouvelle demande de A et ne libère pas son bouton en cours. La nouvelle demande peut ensuite réussir normalement.

Le garde de génération est mis à jour dans `useEffect`, donc sa mise à jour n’est pas effectuée pendant le rendu lui-même. Un contrôle supplémentaire a provoqué une réponse d’erreur de A depuis un effet de disposition lors du basculement automatique vers B : **aucune erreur obsolète affichée dans ce contrôle**. Cela n’est pas une preuve exhaustive pour tous les ordonnancements React, mais ne permet pas de retenir un nouveau bug confirmé sur cette seule fenêtre théorique.

Les formulaires d’emplacement sont désormais remontés avec une clé contenant l’événement. Sur l’écran CME, après une sauvegarde de A encore en attente et un passage à B, le brouillon de B reste intact lorsque la réponse de A arrive, qu’elle soit 200 ou 503. Le garde `mounted` évite le callback de succès de l’ancien formulaire. Les mises à jour locales d’erreur et de `pending` de l’instance démontée n’ont pas altéré l’instance de B dans ces essais.

### Coordonnées et journal

Le contrôle de la paire utilise `array_key_exists` et une comparaison stricte à `null` : les valeurs zéro ne sont pas assimilées à une absence. Les neuf cas vérifiés par invocation de la méthode réelle sont : événement sans coordonnées ; paire de zéros numériques ; paire de zéros sous forme de chaînes ; paire de chaînes numériques conservée ; effacement de la latitude seule refusé ; effacement de la longitude seule refusé ; effacement des deux autorisé ; latitude seule ajoutée à une paire absente refusée ; latitude nulle sur une paire déjà absente autorisée.

Ce contrôle porte sur la méthode de service, sans base. Les règles HTTP `numeric`, les bornes géographiques et `required_with` restent en place ; notamment, une modification numérique via HTTP demande toujours les champs associés prévus par ces règles. Aucune nouvelle validation HTTP ou SQL n’est déduite du seul contrôle en mémoire.

La capture du journal précède désormais toutes les mutations d’année et de nom. Elle intervient après les validations initiales, qui ne modifient pas le modèle. Les deux nouvelles assertions de nom complet correspondent exactement au défaut initial ; elles échoueraient avec la capture tardive de l’ancien code.

### Matrice des droits et transactions imbriquées

La matrice construit des fixtures utilisables et des corps de requête adaptés, puis compare les statuts exacts : 200, 201 ou 204 pour les rôles autorisés, 403 pour les autres, et 401 sans session.

Avec `RefreshDatabase`, la transaction supplémentaire ouverte pour chaque rôle est un niveau imbriqué. Les transactions des services se terminent avant le `rollBack` du rôle ; celui-ci annule leurs mutations et remet la base à l’état de la fixture pour le rôle suivant. Les routes retrouvent leurs modèles en base ; elles ne réutilisent pas un événement supprimé en mémoire par le rôle précédent. Par lecture, le mécanisme est cohérent et une réponse 404/500 ne satisferait plus le test autorisé. Son exécution effective reste couverte ici uniquement par les résultats communiqués par Claude.

### Concurrence

Les bornes de 30 secondes, l’attente non bloquante de fin et la déclaration des deux extensions améliorent le dispositif. Le chemin de désactivation en masse attend explicitement la fin de la désignation pendant que le parent conserve ses verrous, ce qui correspond à l’absence de dépendance attendue dans ce cas.

Pour les scénarios censés attendre un verrou, la nouvelle observation de `SHOW FULL PROCESSLIST` ne suffit toutefois pas à établir la relation enfant → verrou du parent. Le constat résiduel ci-dessous porte uniquement sur cette preuve de test ; aucun nouvel interblocage applicatif n’a été reproduit.

## Constat résiduel

### R1. Mineur — Le détecteur de blocage accepte encore une requête lente ou une autre connexion

**Fichier et ligne :** `backend/tests/Feature/Events/EventConcurrencyTest.php:150`, condition de réussite ligne 156.

**Nature :** faux positif logique confirmé par invocation du helper réel avec réponses de base substituées ; scénario MySQL réel non exécuté. C’est le reliquat du constat 6, pas un nouveau défaut fonctionnel.

**Scénario :** le contrôle appelle réellement la méthode privée `release()` avec une sonde renvoyant la même entrée `SHOW FULL PROCESSLIST` : base `jobdating_test`, commande `Query`, texte `SELECT SLEEP(2)`, état `User sleep`. Aucun enfant n’est démarré, aucune connexion serveur ni transaction n’est ouverte. Le helper conclut néanmoins au blocage après **0,407 s** et **19 lectures de sonde**, car il compare seulement l’identifiant et le texte de la requête dans le temps.

En situation réelle, une requête lente peut satisfaire cette condition sans attendre de verrou. Le filtre exclut les identifiants du parent et de la sonde, mais ne connaît pas l’identifiant MySQL de l’enfant : une autre connexion du même compte sur `jobdating_test` peut également être retenue. La colonne `Command` décrit le type de commande ; elle ne prouve pas une attente de verrou. [MySQL 8 — SHOW PROCESSLIST](https://dev.mysql.com/doc/refman/8.0/en/show-processlist.html).

**Impact :** un test peut annoncer un chevauchement bloquant sans en avoir établi la preuve. Les données métier ne sont pas modifiées par ce défaut du banc ; les résultats ne démontrent pas pour autant l’absence d’une régression de verrouillage.

**Correctif minimal proposé, sans application :** faire transmettre par l’enfant son `CONNECTION_ID()`, ne surveiller que cette connexion et vérifier une attente de verrou liée à la transaction du parent, au moyen des informations de verrous disponibles au compte de test. Conserver les délais bornés. Ajouter un contrôle négatif : requête lente sans verrou, puis connexion tierce active, qui ne doivent jamais être acceptées comme l’attente de l’enfant. Adapter les droits nécessaires uniquement à l’environnement de test si le mécanisme d’observation l’exige.

**Fréquence et suites :** dépend de la charge ou d’une activité concurrente ; non mesurée. Aucun impact direct sur le fonctionnement métier, mais incidence sur la preuve de concurrence que T-30 doit réutiliser. À corriger dans le banc ; ne pas le qualifier de défaut reproduit sans impact ultérieur à reporter automatiquement dans T-49.

Aucun autre défaut fonctionnel nouveau confirmé dans le périmètre de cette contre-revue.

## Validation et limites

### Contrôles exécutés le 25/11

| Contrôle | Résultat |
|---|---|
| Dans `frontend/` : `npx.cmd vitest run src/events/events.test.tsx` | **22 tests réussis**, Vitest 5.1.0, 11,74 s. |
| Banc frontend hors dépôt | **5 contrôles réussis**, 4,82 s : filtre différé dans le même événement ; A → B → A avec deux demandes ; ancien formulaire démonté, réponse 200 puis 503 ; observation de la fenêtre rendu/effet. |
| Méthode réelle `EventService::assertCoordinates`, invoquée en mémoire | **9 cas conformes**, sans connexion de base. |
| Helper réel `EventConcurrencyTest::release`, avec réponses de sonde substituées | Faux positif reproduit : entrée de requête lente acceptée comme blocage, sans enfant ni serveur. |
| `git diff --check` | Réussi ; avertissements de conversion LF/CRLF sur les documents existants. |

**Environnements réellement utilisés :** Node **24.21.0** pour le frontend ; PHP Windows **8.5.12** pour les deux contrôles de méthodes en mémoire. Ces derniers ne sont ni des tests Laravel/MySQL sous Docker, ni une validation de PHP 8.4.

La commande backend demandée a été tentée avec `APP_ENV=testing`, `DB_DATABASE=jobdating_test` et `DB_CONNECTION=mysql` forcés :

```text
docker compose run --rm -T -e APP_ENV=testing -e DB_DATABASE=jobdating_test -e DB_CONNECTION=mysql php php artisan test --do-not-cache-result tests/Feature/Events
```

**Elle n’a exécuté aucun test backend** : Docker Desktop a répondu 500 sur son API `_ping`. Les vérifications par les contextes Docker disponibles ont également échoué. Le port 3307 était ouvert, mais un banc SQL en lecture seule n’a pas obtenu de réponse exploitable et a été arrêté. Il n’apporte donc aucune preuve MySQL. Aucun redémarrage du moteur ni intervention sur la base de développement n’a été effectué.

Les deux bancs de tests backend préparés hors dépôt pour les coordonnées et le détecteur MySQL **n’ont pas été exécutés**. Ils ne sont pas comptés dans les résultats. Aucun contrôle SQLite n’a été utilisé. Les essais frontend utilisent un DOM simulé et une API simulée ; ils ne constituent pas une recette dans un vrai navigateur.

### Résultats communiqués par Claude, non rejoués par moi

Après correction, le 23/11/2026 :

- Pint et PHPStan sans erreur ; suite backend complète Docker : **517 tests réussis, 2696 assertions**, durée indiquée **751,68 s**, code 0 ; 41 méthodes dans `EventTest` et 10 tests de concurrence.
- Frontend : lint, suite complète **114 tests** et build réussis.
- Actions non rejouées par Claude dans un vrai navigateur.

La suite complète, Pint, PHPStan, lint et build n’ont pas été relancés. L’avis backend s’appuie sur le code et les résultats locaux communiqués par Claude ; les résultats du 23/11 de la revue précédente ne sont pas présentés comme un rejeu des corrections actuelles. Aucune exécution GitHub Actions n’a été vérifiée pendant cette contre-revue.

## Verdict

**À fusionner après corrections mineures.**

Les deux défauts majeurs et les trois défauts mineurs fonctionnels du rapport initial sont traités. Compléter la preuve de blocage du banc de concurrence conformément à R1. Les résultats frontend locaux sont positifs ; le rejeu backend indépendant reste limité par l’indisponibilité de Docker, explicitement distincte des résultats communiqués par Claude.

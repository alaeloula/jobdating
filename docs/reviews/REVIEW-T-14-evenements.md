# Revue du code T-14 — Événements, participants et emplacements

- **Date :** 23/11/2026.
- **Branche :** `feature/T-14-evenements` ; changements non commités.
- **Base :** `develop`, `1b05242254e94e4843dd414d22de80621cec6f29` ; HEAD identique à cette base.
- **Références :** `AGENTS.md`, `CLAUDE.md`, plan T-14 v1.4, deux revues du plan, décisions du 23/11, entrée T-14 de `PROGRESS.md`, CDC ADM-05/ADM-07, RG-ENTR-03, RG-IMP-06/07, ENT-01, APP-00/01, matrice 3.2, modèle 8.2 et TRA-01.

## Périmètre

Examen du diff avec `develop` et des fichiers non suivis de T-14 : migration, modèles, contrats, services, contrôleurs, ressources, routes, intégrations apprenants/import/entreprises/démonstration, audit, pages et composants React, tests et documents associés. Comparaison avec le périmètre des maquettes examinées pendant la revue du plan.

Les décisions 1 à 14 sont respectées : aucun retour demandé au sélecteur entreprise ni à son historique. Programme, reprise, import des entreprises T-64, passages, compteurs, recherche, CV et suggestions restent hors périmètre. E-01 à E-06 ne sont pas rouverts.

Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun fichier réel utilisé. Les contrôles complémentaires emploient des données fictives et des fichiers temporaires **hors dépôt**. Les contrôles de base visent exclusivement `jobdating_test` ; aucune requête de revue sur la base de développement `jobdating`.

## Constats

### 1. Majeur — Des données de l’ancienne édition peuvent être enregistrées dans la nouvelle

**Fichiers/lignes :** `frontend/src/events/api.ts:130`, `frontend/src/events/ParticipantsPanel.tsx:267`, `frontend/src/pages/LocationsPage.tsx:115`.

**Statut : bug confirmé par deux contrôles frontend hors dépôt.** L’envoi HTTP erroné est reproduit avec une API simulée ; aucun écrasement de données de développement n’a été effectué.

**Scénario :**

1. Dans « Entreprises participantes », afficher E1 avec Alpha, étage `1`. Alpha participe également à E2, avec un autre emplacement.
2. Sélectionner E2 et différer sa réponse de liste.
3. La ligne d’Alpha provenant de E1 reste affichée, car `keepPreviousData` s’applique aussi au changement d’identifiant d’événement. Les actions utilisent déjà `event.id` de E2.
4. Ouvrir « Emplacement », modifier la table et enregistrer. Le banc constate un `PATCH /api/v1/events/2/companies/7/location` avec l’étage `1` de E1, **avant toute réponse de liste pour E2**.

Un second contrôle sur l’écran CME confirme qu’un changement de courant lors d’une actualisation peut réutiliser un brouillon de E1 pour E2 : la clé du `LocationEditor` contient l’entreprise et les valeurs, mais pas l’événement. À valeurs initiales identiques, le formulaire conserve son état local tout en recevant le nouvel `eventId`.

**Impact :** écrasement possible de l’emplacement d’une autre édition, sans que l’utilisateur ait vu ses valeurs. La route serveur est correctement autorisée pour l’administrateur/CME : elle ne peut pas deviner que le formulaire affiche un ancien contexte.

**Correctif minimal :** ne conserver les données précédentes qu’à identifiant d’événement identique, ou masquer/désactiver les actions tant que les données affichées sont provisoires. Inclure l’identifiant d’événement dans les clés des formulaires et réinitialiser les brouillons au changement de contexte. Ajouter un test avec réponse E2 différée et une même entreprise inscrite aux deux éditions.

**Fréquence et suites :** fenêtre favorisée par une connexion lente ; scénario normal de gestion de plusieurs éditions. Impact sur l’intégrité des participations réutilisées ensuite : ne pas reporter comme défaut rare sans conséquence.

### 2. Majeur — Un événement archivé reste supprimable avec ses participations

**Fichiers/lignes :** `backend/app/Services/Events/EventService.php:192`, `frontend/src/pages/EventsPage.tsx:188`.

**Statut : bug confirmé par requête HTTP sur MySQL.**

**Référence :** décision 3 et plan §4 : un événement archivé est en lecture seule, sans modification de l’événement ni de ses participants.

**Scénario :** créer un événement non courant, l’archiver, lui associer une participation sans passage, puis appeler `DELETE /api/v1/events/{id}` en administrateur. Résultat observé : **204**, événement supprimé et participation supprimée en cascade. L’écran propose également « Supprimer » pour l’archive : sa condition vérifie seulement l’absence de statut courant.

**Impact :** la protection annoncée par l’état « Archivé : lecture seule » est contournée et les participations sont effacées définitivement. Le refus actuel sur les passages ne couvre pas les événements qui n’en ont pas.

**Correctif minimal :** refuser la suppression d’une archive sous le verrou de l’événement avec le refus `event_archived` existant ; demander sa désarchivation explicite avant suppression. Masquer ou désactiver le bouton correspondant. Compléter le test de lecture seule par un DELETE vérifiant la conservation des participations.

**Fréquence et suites :** reproductible sur toute archive sans passage, sans concurrence ; pas un cas rare. Concerne la conservation des éditions et leur reprise future.

### 3. Mineur — Une modification partielle peut laisser une coordonnée isolée

**Fichier/ligne :** `backend/app/Http/Controllers/EventController.php:132`.

**Statut : bug confirmé par requête HTTP sur MySQL.**

**Référence :** plan §3 : latitude et longitude facultatives, ensemble ou aucune des deux.

**Scénario :** l’événement possède latitude `33.5000000` et longitude `-7.5000000`. Envoyer `PATCH /api/v1/events/{id}` avec uniquement `{"latitude":null}`. Résultat observé : **200**, latitude nulle et longitude conservée. Les règles `required_with` portent sur les champs reçus, sans vérifier la paire obtenue après application du PATCH.

**Impact :** données géographiques incohérentes pour les futurs consommateurs. Le test actuel ne vérifie que la création avec une latitude seule.

**Correctif minimal :** valider la paire effective, en fusionnant les valeurs reçues avec celles conservées, ou exiger les deux champs lorsqu’un PATCH touche les coordonnées. Autoriser leur effacement simultané. Tester l’effacement partiel dans les deux sens et l’effacement complet.

**Fréquence et suites :** cas d’API partielle ; le formulaire actuel envoie les deux champs. Faible exposition dans l’interface, mais invariant de données à préserver pour les tâches suivantes ; pas démontré sans impact ultérieur.

### 4. Mineur — Le journal perd le changement de nom complet lors d’un changement d’année

**Fichier/ligne :** `backend/app/Services/Events/EventService.php:80`.

**Statut : bug confirmé par contrôle HTTP et lecture du journal sur MySQL.**

**Référence :** TRA-01, journal des valeurs avant/après ; décisions 13/14 et plan §3 sur le nom complet.

**Scénario :** modifier la date d’« Audit Fictif 2026/2027 » du `2026-12-15` au `2027-09-15`. Le nom affiché devient correctement « Audit Fictif 2027/2028 ». Cependant, `school_year` est modifié **avant** la capture `$before`. Le journal observé ne contient que :

```json
{"avant":{"date":"2026-12-15"},"apres":{"date":"2027-09-15"}}
```

La comparaison des noms utilise déjà la nouvelle année des deux côtés ; le changement du nom complet n’est donc pas enregistré. Lors d’une modification simultanée du nom saisi, le nom complet « avant » porterait également la mauvaise année.

**Impact :** historique incomplet ou trompeur de l’identité affichée de l’événement. La date enregistrée permet une reconstitution, mais ne remplace pas une capture avant/après correcte.

**Correctif minimal :** capturer l’état avant toute mutation de `school_year`, puis appliquer les changements et calculer le différentiel. Ajouter les assertions sur les noms complets avant/après lors d’un changement d’année, seul puis combiné à un renommage.

**Fréquence et suites :** changement d’année d’un événement sans passage, probablement occasionnel. N’affecte ni les droits ni les calculs métier suivants ; pourrait être reporté seulement sur décision explicite du responsable technique, en conservant cette limite de traçabilité.

### 5. Mineur — Un succès tardif de E1 efface la sélection en cours dans E2

**Fichier/ligne :** `frontend/src/events/ParticipantsPanel.tsx:80`.

**Statut : bug confirmé par contrôle frontend hors dépôt.**

**Scénario :** lancer l’ajout d’Alpha dans E1 et laisser la réponse en attente ; sélectionner E2, ouvrir son panneau d’ajout et cocher Beta. Faire ensuite réussir la requête de E1. Le panneau d’ajout de E2 se ferme et sa sélection est vidée.

**Cause :** la génération protège les messages et l’état `busy` dans `run`, mais le corps de `add` appelle encore `setPicked([])` et `setAdding(false)` sans vérifier cette génération.

**Impact :** perte de saisie et interruption d’une action sur la nouvelle édition. La requête initiale vise bien E1 ; ce constat ne démontre pas une mauvaise écriture en base.

**Correctif minimal :** soumettre toutes les mises à jour d’état liées au résultat à la vérification de génération, ou isoler l’état du panneau par événement. Ajouter le cas de succès tardif pendant une nouvelle sélection ; le test existant couvre seulement une erreur tardive de retrait.

**Fréquence et suites :** changement d’événement pendant une requête lente, occasionnel ; effet limité à la saisie de cette interface, sans incidence établie sur les tâches suivantes. Report éventuellement possible après arbitrage explicite, indépendamment du constat 1.

### 6. Suggestion — Renforcer la preuve apportée par certains tests

**Fichiers/lignes :** `backend/tests/Feature/Events/EventConcurrencyTest.php:123`, `backend/tests/Feature/Events/EventTest.php:797`.

**Statut : limites confirmées par lecture ; risque de faux positif, sans échec CI observé.**

**Scénarios :**

- Les processus et connexions de concurrence sont réels. Le parent tient ses verrous, libère le démarrage de l’enfant puis dort 400 ms. Aucun accusé de réception ne prouve que l’enfant a atteint la requête concurrente avant la validation du parent. Si l’enfant est retardé au-delà de cette fenêtre, les mêmes assertions peuvent réussir après une exécution successive. Les tests ont réussi localement, ce qui ne prouve pas chaque chevauchement.
- Dans la matrice, un rôle autorisé doit seulement recevoir un statut différent de 403. Une réponse 404 ou 500 satisferait cette assertion. Les tests fonctionnels séparés réduisent le risque, mais cette boucle seule ne prouve pas le succès de la route autorisée.

**Impact :** une suite verte peut surestimer la couverture de concurrence ou d’accès nominal. Aucun défaut supplémentaire de verrouillage n’est déduit de cette seule faiblesse.

**Correctif minimal :** synchroniser explicitement l’arrivée de l’enfant et vérifier son attente effective avant de libérer le verrou ; borner aussi l’attente finale. Pour les rôles autorisés, utiliser les préconditions/données nécessaires et vérifier le statut attendu par opération, plutôt qu’exclure seulement 403. Conserver les vérifications de refus pour les autres rôles.

**Fréquence et suites :** risque surtout lié à l’ordonnancement sous charge CI ; aucune fréquence mesurée. Utile avant de réutiliser ce banc pour T-30. Suggestion de fiabilisation, sans nouveau blocage métier.

## Points vérifiés sans remarque

- **Événement et année scolaire :** année calculée depuis la date, nom complet centralisé, bornes septembre/août, unicité de l’année incluant les archives, index unique du marqueur courant. La borne de modification reste le jour de création, pas le jour courant. Les restrictions sur les événements terminés/courants et la conservation des participations lors du retrait du statut correspondent aux décisions.
- **Migration :** quatre tables créées, clés étrangères et unicités présentes, ligne `event_locks.id = 1` initialisée dans la migration ; suppression des tables dans l’ordre inverse des dépendances. Les tests `DatabaseMigrations` exécutés exercent les montées et retours de migrations.
- **Inscription :** lecture du courant après le verrou partagé ; désignation sous verrou exclusif avant les lectures cohérentes ; identifiants et paquets triés ; reprises de transaction. Création, réactivation, restauration active et import sont raccordés ; l’insertion idempotente ne remet jamais `removed_at` à zéro. Le cas d’import inchangé et la réactivation par import ont été rejoués localement.
- **Concurrence :** les chemins existants qui ne rendent pas actif gardent leur ordre. Le code et la documentation prennent en compte les verrous indirects de clés étrangères. Les dix tests de concurrence passent sous MySQL. Les services événements traduisent 1213/1205 après les tentatives ; le détecteur de concurrence Laravel installé reconnaît aussi le message MySQL 1205. Le test d’injection fourni exerce 1213, pas un véritable timeout 1205 : ce dernier n’a pas été reproduit ici.
- **Entreprise :** affectation API obligatoire ; événement relu sous verrou dans la transaction de création ; compte, fiche, participation et audits atomiques ; nettoyage du logo en cas d’échec. Les essais ciblés couvrent les refus et les échecs après création de la fiche. L’exception de service sans affectation est documentée pour la reprise T-16, elle n’est pas accessible par l’API de création.
- **Droits et confidentialité :** routes de gestion protégées par les permissions existantes ; CME sans ajout/retrait ; vues personnelles filtrées par rôle et propriétaire. La réponse apprenant est construite explicitement sans CNIE, identifiant de connexion, contact ni évaluation. Les entreprises désactivées sont exclues de cette vue. Le contexte de réinitialisation étranger est refusé avant changement du mot de passe et de sa version, avec test local réussi dans `Events`.
- **Journal :** catégorie/actions ajoutées et liaison au service existant ; inscription massive résumée en une ligne de comptes ; rollback des écritures contrôlé. Réserve ciblée sur le différentiel du changement d’année au constat 4.
- **Relais :** `EventUsage` compte par événement/participant, conserve les occurrences et prévoit le protocole partagé/exclusif pour T-30 ; l’implémentation à zéro avant les passages est une limite acceptée, pas un manque de T-14. Les écrans/exports entreprise futurs sont explicitement limités au courant ; les historiques administratifs restent conservés. L’import entreprises est identifié par T-64.
- **Interface :** nouvelles routes et liens par rôle, fiche avec participation, accueil entreprise sans sélecteur, « Ma journée », cas sans événement, reprise après erreurs simples et affichage des rapports. Les défauts de changement de contexte sont détaillés ci-dessus ; les défauts mobiles E-04 et d’import E-05/E-06 ne sont pas requalifiés.

## Validation et limites

### Exécuté pendant cette revue

**Environnement relevé :** PHP **8.4.28** dans Docker, MySQL **8.0.46**, base **`jobdating_test`**, isolation **`REPEATABLE-READ`** ; extensions `pcntl` et `posix` présentes. Frontend sous Node **24.21.0**, Vitest **5.1.0**, DOM simulé `jsdom`.

| Contrôle | Résultat local |
|---|---|
| `docker compose run --rm -T -e APP_ENV=testing -e DB_DATABASE=jobdating_test -e DB_CONNECTION=mysql php php artisan test --do-not-cache-result tests/Feature/Events` | **71 tests réussis, 375 assertions**, 314 s ; inclut les dix tests de concurrence et les jeux de données. |
| Même commande, `tests/Feature/Learners/LearnerImportTest.php --filter=test_import_inscrit_les_actifs_sans_retablir_un_retrait` | **1 test réussi, 16 assertions**, 46,66 s. |
| Dans `frontend/` : `npx.cmd vitest run src/events/events.test.tsx` | **18 tests réussis**, 14,57 s. |
| Banc backend temporaire hors dépôt, monté en lecture seule dans Docker | **3 scénarios confirmés, 11 assertions** : suppression d’archive et cascade ; PATCH d’une seule coordonnée ; omission du changement de nom complet dans l’audit. |
| Banc frontend temporaire hors dépôt | **3 scénarios confirmés** : données E1 utilisées pour un PATCH E2 ; formulaire CME réutilisé entre événements ; succès tardif E1 fermant la sélection E2. |

Les bancs complémentaires vérifient le **comportement fautif observé** : leur réussite n’est pas une validation de ces comportements. Aucun fichier de test n’a été ajouté au dépôt. Les données des contrôles backend complémentaires sont annulées par le dispositif de test.

### Communiqué par Claude, non rejoué

- Pint et PHPStan sans erreur ; suite backend complète : **515 tests, 2672 assertions**, environ 7 min 40, code 0.
- Frontend : lint, suite complète **110 tests**, build réussis.
- Mesure : désignation de **2 000 apprenants** en environ **410 ms**, pic mémoire **16 Mio**.
- Navigateur : ouverture en lecture seule des pages Événement, Entreprises participantes, Emplacements et Apprenants ; actions non rejouées par Claude.

La suite complète n’a pas été relancée. `CompanyTest.php` complet, Pint, PHPStan, lint, build et mesure de charge n’ont pas été rejoués par moi. Les nouveaux bancs frontend sont des contrôles DOM/API simulée ; ils ne constituent pas une recette dans un navigateur réel, ni une mesure de contraste ou de mise en page. Aucune nouvelle recette visuelle dans un navigateur réel n’a été effectuée pendant cette revue.

### CI et portée des résultats de concurrence

Le workflow ne déclare pas explicitement `pcntl`/`posix`. Cela ne démontre pas un échec : la documentation de `setup-php` les liste parmi les extensions chargées par défaut pour PHP 8.4 sur Ubuntu 24.04. Le résultat réel du prochain job reste à vérifier ; je n’ai pas exécuté GitHub Actions. [Liste officielle des extensions](https://github.com/shivammathur/setup-php/wiki/Php-extensions-loaded-on-ubuntu-24.04#php-84).

Le test n’exige formellement que `pcntl`, alors qu’il appelle aussi `posix_kill`. Déclarer les deux besoins et vérifier en CI que les tests de concurrence sont exécutés serait une sécurisation du dispositif, sans preuve actuelle de panne CI. La réserve sur la preuve de chevauchement est au constat 6. Aucun passage sous SQLite n’est invoqué et aucun résultat local ne prouve l’absence universelle d’interblocage.

## Verdict

**À corriger avant fusion.**

Corriger en priorité l’isolation des écrans/formulaires entre éditions et la suppression des archives annoncées en lecture seule. Traiter également les trois défauts mineurs ou obtenir un arbitrage explicite pour les seuls cas sans impact ultérieur indiqués dans ce rapport. Les décisions métier validées et les fonctionnalités reportées restent inchangées.

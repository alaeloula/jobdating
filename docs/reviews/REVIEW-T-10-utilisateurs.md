# Revue indépendante — T-10 (JD-15) : comptes de l'équipe

- **Date :** 19/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-10-utilisateurs`.
- **Base et HEAD :** `develop`, `27217ae552f8fa5e64051e9debd52c4398282e2d`.
- **État :** travail non commité ; examen de `git diff develop` et des fichiers non suivis concernés.
- **Livrable :** ce rapport uniquement. Aucun fichier de code ni test modifié ou créé, aucun commit ni push.

## 1. Références et périmètre

Consignes : `AGENTS.md`, `CLAUDE.md` et demande de revue. Contrat : CDC V2, ADM-01 et RG-USR-01 à 05, RG-ACC-01 à 06, matrice 3.2 et TRA-01 ; décisions des 14/11 et 17/11 ; réponses validées du 18/11 et entrée T-10 de `PROGRESS.md:252`.

Il n'y a pas de revue du plan à exiger pour cette tâche. Le périmètre validé est respecté : comptes CME, Direction et administrateur ; suppression logique et restauration ; protections du propre compte et du dernier administrateur actif ; réinitialisation avec affichage unique et e-mail. Entreprises, apprenants et photo restent hors périmètre. E-01 à E-03 ne sont pas rouverts.

Fichiers examinés : routes, `StaffAccountController`, `StaffAccountResource`, `StaffAccountService`, `AccountRuleException`, `PasswordGenerator`, `GeneratedPasswordNotification`, tests backend T-10 ; `AccountsPage.tsx`, `accounts/api.ts`, tests frontend, routes et lien d'en-tête. Lecture complémentaire des services T-05, du modèle, de l'observateur d'audit et des mécanismes Laravel de transaction, notification et file.

La maquette `admin-10-comptes-cme-direction.png` a été ouverte et examinée. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Constats

### 1 — Une invitation d'administrateur permet de retirer le dernier administrateur connectable

- **Sévérité : Majeur.**
- **Fichier et lignes :** `backend/app/Services/Accounts/StaffAccountService.php:247–251`.
- **Qualification : bug confirmé sur MySQL**, avec entrelacement déterministe et passage par le noyau HTTP pour l'action suspendue.
- **Référence :** contrat du 18/11, « toujours au moins un administrateur actif » ; RG-USR-03. Le statut `actif` de `StaffAccountResource` exige lui-même un compte activé.

**Scénario reproductible :**

1. A et B sont deux administrateurs activés ; C est une invitation d'administrateur, jamais activée.
2. B demande la désactivation de A. Suspendre sa requête après les contrôles de session et de permission, avant l'opération du service.
3. A désactive B ; la transaction termine et ferme ses sessions. La requête de B, déjà autorisée, reste en cours.
4. Reprendre la requête de B. Le service compte C comme un autre administrateur actif, car il filtre `deactivated_at` mais pas `activated_at`.

**Résultat obtenu :** réponse **200** pour l'action reprise ; A et B désactivés ; **zéro administrateur activé et non désactivé**, une invitation restante. La première opération a été intercalée par une sonde en mémoire appelant le service réel ; la seconde a traversé l'authentification et `users.manage`. Ce n'est pas un test de deux requêtes HTTP réellement parallèles.

**Impact :** la garantie du dernier administrateur actif est rompue. L'administration devient inaccessible aux comptes déjà activés, jusqu'à activation de C ou intervention extérieure. Cette protection conditionne aussi la gestion des comptes des tâches suivantes.

**Correctif minimal :** ne compter que les administrateurs non supprimés, non désactivés **et activés** (`activated_at` non nul), sous le verrou existant. Ajouter le scénario avec invitation et action déjà autorisée. Une nouvelle vérification de l'acteur peut renforcer la défense, mais ne remplace pas le comptage correct.

**Report :** cas concurrent peu fréquent, mais avec impact sur le contrat d'administration ; ne relève pas du report « rare et sans impact sur les développements suivants ».

### 2 — Les actions croisées entre administrateurs peuvent produire un interblocage non repris

- **Sévérité : Mineur.**
- **Fichier et lignes :** `backend/app/Services/Accounts/StaffAccountService.php:113–124` et `:247–251` ; même ordre de verrouillage pour suppression et changement de rôle.
- **Qualification : bug confirmé avec deux processus PHP et deux connexions MySQL simultanées.**
- **Référence :** protections concurrentes du contrat T-10.

**Scénario :** A désactive B pendant que B désactive A. Chaque transaction verrouille d'abord sa cible ; chacune demande ensuite le verrou sur l'autre administrateur via `guardLastAdmin()`.

**Résultat obtenu :** les deux processus ont atteint leur premier verrou avant poursuite. Une opération a réussi ; l'autre a levé `QueryException`, **SQLSTATE 40001, erreur MySQL 1213**. Un administrateur actif est resté : ce scénario, sans invitation, ne démontre pas une perte de l'invariant. La transaction n'a aucune tentative de reprise ; l'exception technique remonte au traitement HTTP générique en 500.

**Impact :** une action concurrente reçoit une erreur technique plutôt qu'un résultat métier après relecture. La transaction victime est annulée ; aucune corruption persistante n'a été observée.

**Correctif minimal :** prévoir une reprise bornée des transactions victimes d'interblocage, puis réévaluer la règle sur l'état relu ; ou acquérir un verrou commun avant celui de la cible afin de sérialiser ces opérations. Ne pas simplement convertir toute `QueryException` en refus métier.

**Report :** cas rare, sans dépendance bloquante identifiée pour T-11/T-12 ; candidat au report T-49 si le responsable technique le valide.

### 3 — Une erreur de chargement de la liste est présentée comme une liste vide

- **Sévérité : Mineur.**
- **Fichier et lignes :** `frontend/src/pages/AccountsPage.tsx:76` et `:187–190`.
- **Qualification : bug confirmé en mémoire**, avec le composant réel, son hook TanStack et un appel API simulé en échec 503.
- **Référence :** liste ADM-01 et traitement des erreurs serveur demandé pour T-10.

**Scénario :** ouvrir `/comptes` sans données déjà en cache ; faire échouer le chargement `/users`, puis laisser les éventuelles reprises se terminer.

**Résultat obtenu :** la query est en état `error`, mais l'écran affiche **« Aucun compte. »**, sans alerte ni message d'indisponibilité. `isPending` est faux et l'absence de données devient un tableau vide.

**Impact :** l'administrateur reçoit une information fausse sur l'état des comptes et aucun moyen explicite de relancer ce chargement depuis le message. Les autorisations backend ne sont pas affectées.

**Correctif minimal :** rendre un état d'erreur avec message et nouvelle tentative lorsque `accounts.isError` ; réserver « Aucun compte » à un chargement réussi sans résultat.

**Report :** défaut limité à cet écran, sans impact bloquant identifié sur les tâches suivantes ; report éventuel à arbitrer. Il survient à chaque échec final de chargement, pas seulement lors d'une course exceptionnelle.

### 4 — La recherche exacte « 0 » est ignorée

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/app/Http/Controllers/StaffAccountController.php:37`.
- **Qualification : bug confirmé par requête HTTP sur MySQL.**
- **Référence :** recherche de la liste ADM-01.

**Scénario :** deux comptes visibles, `neutral@example.test` et `review0@example.test` ; demander `/api/v1/users?search=0`.

**Résultat obtenu :** **200**, total **2**, les deux adresses sont renvoyées. PHP considère la chaîne `'0'` comme vide dans `empty()`, donc aucun filtre n'est ajouté.

**Impact :** résultat incorrect pour cette recherche valide ; le périmètre de droits reste inchangé.

**Correctif minimal :** tester explicitement la présence d'une chaîne non vide, sans `empty()`, et ajouter ce cas ciblé.

**Report :** cas ciblé peu fréquent, sans effet sur les tâches consommatrices ; candidat au report T-49 si validé.

## 3. Points vérifiés sans remarque

| Contrôle indépendant | Résultat |
|---|---|
| Les neuf routes sans session | **401** sur chacune. |
| Les neuf routes pour CME, Direction, Entreprise et Apprenant | **403** sur chacune, pour chacun des quatre rôles. |
| Les sept routes visant un compte précis, avec cible Entreprise | **404** pour l'administrateur. La recherche de cible est commune aux opérations. |
| Réinitialisation de son propre compte, deux clients réels de session | **200**, hash conforme au secret retourné ; client courant **200/200**, autre client **401**. |
| Réinitialisation et confidentialité | Mot de passe absent des payloads bruts de `jobs` et des audits ; après déchiffrement en mémoire, la notification contient exactement le mot de passe retourné. Aucun secret n'a été imprimé par le banc. |
| Notification générée | Job Laravel `SendQueuedNotifications`, notification `GeneratedPasswordNotification`, chiffrement effectif et propriété `afterCommit` vraie. Aucun e-mail réellement envoyé par ces bancs. |
| Création annulée dans une transaction englobante | Aucun job avant commit ; après rollback, ni compte, ni audit supplémentaire, ni job. |
| Création validée | Compte non activé, rôle Direction unique, un job d'activation ajouté après commit. |
| Réinitialisation annulée dans une transaction englobante | Hash initial restauré ; aucun job avant commit ni après rollback. |
| Recherche de `%`, `_` et `\` | Comparaison littérale : un seul compte correspondant dans le jeu contrôlé. |
| Opérations sur un compte supprimé | Modification, désactivation, réactivation, réinitialisation et renvoi du lien : **422** ; restauration **200**, retour à l'état actif. |

À la lecture :

- Chaque route bénéficie de l'authentification, du contrôle de session et de `users.manage`. Seul l'administrateur possède cette permission. La ressource expose une liste explicite de champs sans hash, jeton ni `remember_token`.
- L'unicité de l'e-mail inclut les comptes supprimés ; seuls les trois rôles autorisés sont acceptés. Le modèle et sa contrainte existante préservent le rôle unique. Les champs non validés ne sont pas transmis aux opérations du compte.
- Désactivation et suppression ferment les sessions et révoquent les deux types de liens. La suppression reste logique, sans effacement de l'historique. Les protections du propre compte sont appliquées côté service ; le frontend reflète ces interdictions.
- `PasswordGenerator` utilise `random_int` pour chaque caractère, un alphabet sans caractères ambigus et la règle `StrongPassword` avant retour ; ses messages d'échec ne contiennent pas le secret.
- L'observateur journalise les opérations sur le compte et le modèle journalise les changements de rôle. La réinitialisation fournit explicitement l'administrateur comme auteur et neutralise le double audit de modification du mot de passe.
- La route de réinitialisation porte `no.store` ; la réponse précise `no-store, private`. Le mot de passe affiché reste dans l'état local React, hors des caches de query et de mutation, et disparaît à la fermeture. La confirmation, le bouton « Copier », la garde de route administrateur et le lien d'en-tête sont présents.
- La structure de l'écran reprend la maquette ; suppression, restauration et administrateurs sont des extensions explicitement validées. Aucune dépendance ajoutée dans le diff.

## 4. Qualité des tests

Les tests backend vérifient des effets en base et des refus métier, pas seulement des codes de réponse. Les scénarios de réinitialisation contrôlent le hash, l'audit, les sessions supprimées et la notification ; le frontend vérifie le contrat envoyé et les deux caches TanStack pour le secret.

Limites importantes :

- Le test du dernier administrateur désactive les autres administrateurs, mais n'ajoute aucune invitation d'administrateur. Il passe malgré le constat 1. Il appelle volontairement le service avec un acteur CME pour isoler la règle : ce n'est pas un test de concurrence ou d'autorisation HTTP.
- Aucun test fourni ne couvre deux transactions qui verrouillent chacune leur cible avant de contrôler les autres administrateurs. Le constat 2 a nécessité deux processus distincts.
- Le test « e-mail après validation » avec `Queue::fake()` ne démontre pas à lui seul le comportement autour d'un commit physique ; les bancs de rollback avec une vraie file en base le complètent.
- Le test de réinitialisation T-10 utilise `actingAs` et une ligne de session artificielle. Il ne démontre pas la conservation de sa propre session après reset ; ce point a été contrôlé ici avec deux clients à cookies.
- Les tests frontend utilisent des listes réussies ; aucun ne vérifie l'échec final du chargement. La recherche `'0'` n'est pas couverte non plus.

Ces lacunes expliquent pourquoi les résultats positifs communiqués restent compatibles avec les constats. Elles ne remettent pas en cause les vérifications effectivement présentes.

## 5. Questions au responsable technique

Aucun nouvel arbitrage fonctionnel n'est nécessaire pour le constat 1. Après sa correction, le report éventuel des constats mineurs doit suivre la décision existante sur T-49 ; aucun de ces reports n'est présumé approuvé par ce rapport.

## 6. Validation et limites

### Exécuté par cet auditeur

Environnement constaté : **PHP 8.4.27 dans Docker**, **MySQL 8.0.46**, base **`jobdating_test`**, collation de connexion **`utf8mb4_unicode_ci`** ; **Node 24.21.0** pour le banc frontend. Aucun SQLite.

Les bancs PHP ont été transmis en mémoire à `docker compose run --rm -T php php -r …`. Données fictives, copies de tables préfixées `review_t10_<identifiant>_`, nettoyage en `finally`. Les copies préservent colonnes et index mais pas les clés étrangères ni les déclencheurs ; aucune validation supplémentaire de ceux-ci n'est déduite des bancs.

Le constat 1 utilise un entrelacement déterministe après les contrôles d'accès, avec opérations réelles du service. Le constat 2 utilise **deux processus PHP, deux connexions MySQL et une barrière après le verrouillage de chaque cible** : l'interblocage est réellement observé. Les autres contrôles HTTP utilisent le noyau Laravel et des cookies, en environnement de test.

Le banc frontend compile en mémoire la page et son module d'API, avec React, TanStack Query et un DOM simulé ; seul l'appel API est remplacé pour produire l'échec 503. Aucun fichier de test ou de compilation n'a été écrit dans le dépôt. La maquette a été examinée, mais aucune nouvelle recette visuelle en navigateur ou à 375 px n'a été effectuée.

`git diff --check develop` réussit. La recherche finale des tables préfixées `review_t10_` est vide : toutes les tables de contrôle ont été supprimées. Aucun test de charge ni autre suite complète n'a été lancé.

### Communiqué par Claude, non rejoué par cet auditeur

Résultats du **18/11/2026** :

- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur.
- `StaffAccountTest.php` : **20 tests réussis**, dans Docker avec PHP 8.4.
- Suite complète : **329 tests réussis, 1503 assertions**, environ 4 minutes, code de sortie 0.
- Frontend : lint réussi, **50 tests réussis**, build réussi.
- Navigateur : liste, création avec e-mail d'activation dans Mailpit, réinitialisation avec affichage unique et e-mail reçu.

Ces commandes et parcours complets n'ont pas été relancés ici. Les résultats indépendants sont les bancs ciblés décrits dans ce rapport. Le site local reste signalé en PHP 8.5.12 dans les informations communiquées ; les contrôles PHP de cette revue ont utilisé Docker en 8.4.

## 7. Verdict

**À corriger avant fusion.**

Le comptage des administrateurs doit exclure les invitations non activées pour préserver le contrat du dernier administrateur actif. Trois défauts mineurs sont également confirmés : interblocage non repris, erreur de chargement masquée et recherche `'0'` ignorée.

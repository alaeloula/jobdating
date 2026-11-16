# Revue indépendante — T-06 (JD-13) : journal d'audit

- **Date :** 16/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-06-journal-audit`.
- **Base :** `develop`, `71968dfc4b1cce2f551e8c0754ef5ee77778d72d`.
- **État :** travail non commité ; examen de `git diff develop` et des fichiers non suivis concernés.
- **Livrable :** ce rapport uniquement. Aucun code modifié, aucun fichier de test créé dans le dépôt, aucun commit ni push.

## 1. Références et périmètre

Références lues : AGENTS.md, CLAUDE.md, CURRENT_TASK.md, décisions du 16/11 sur l'audit, entrée T-06 de PROGRESS.md, CDC TRA-01, EXI-DATA-02, EXI-LOG-01/02/03 et règles de confidentialité et de consultation administrative. Maquette `admin-13-journal-audit.png` ouverte et examinée.

Fichiers examinés : `AuditLogger`, `UserAuditObserver`, modèles `AuditLog` et `User`, enums d'audit, migration `2026_11_16_120000_create_audit_logs_table.php`, contrôleurs d'authentification et d'audit, requête de filtres, ressource JSON, fournisseur de services, routes API et console, `docker-compose.yml`, les trois fichiers de tests backend du journal, les fichiers frontend `src/audit/`, `AuditLogPage.tsx`, les changements de routes et de mise en page.

Le périmètre livré correspond à la tâche. L'export, les branchements futurs des passages/évaluations/CV/imports/exports et l'installation du planificateur sur le serveur ne sont pas considérés comme manquants. Les fichiers locaux exclus par le prompt ne sont pas commentés.

## 2. Constats

### 1 — Des variantes usuelles de mot de passe sont conservées dans le journal

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Services/AuditLogger.php:21`, filtrage à la ligne 84.
- **Qualification : bug confirmé du service, reproduit puis relu dans MySQL.** Aucun secret réel utilisé.

**Scénario reproductible :** appeler `record(AuditAction::AccountUpdated, new: ...)` avec des valeurs fictives sous `pwd`, `nested.PASSWD` et `nested.mot_de_passe`. Recharger la ligne persistée : les trois valeurs sont présentes. Une valeur sous `password` est, elle, correctement supprimée.

Le filtre ne reconnaît que les fragments `password`, `token` et `secret`. La récursion et l'insensibilité à la casse fonctionnent, mais ne compensent pas l'absence d'alias.

**Impact :** la garantie du service unique « jamais de mot de passe » n'est pas tenue pour ses entrées. Un appel utilisant ces noms inscrirait durablement le secret dans les JSON consultables par l'administrateur. Les branchements actuels de connexion n'envoient pas leur mot de passe à `record()` : cette reproduction prouve une défaillance du garde-fou du service, pas une fuite constatée des connexions réelles.

**Correctif minimal proposé :** reconnaître les alias et leurs variantes normalisées à toute profondeur, ou filtrer les champs selon un contrat explicite d'action. Distinguer le marqueur non secret « mot de passe modifié » du champ contenant une valeur de mot de passe : l'observateur utilise actuellement `mot_de_passe = modifié`. Ajouter les régressions correspondantes sans conserver de valeur sensible.

### 2 — La CNIE échappe au masquage lorsqu'elle n'est pas une chaîne

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Services/AuditLogger.php:88`.
- **Qualification : bug confirmé du service, reproduit avec persistance MySQL.**

**Scénario reproductible :** enregistrer `nested.cnie = 12345678` et `CNIE = ['value' => 'ZZ123456']`. Après relecture : le nombre et la chaîne complète sous `value` sont conservés. Le cas textuel direct est correctement masqué par le test existant.

**Impact :** le masquage dépend du type de la valeur fournie ; une CNIE peut rester intégrale dans le journal. Le service est précisément chargé de protéger les valeurs avant/après, indépendamment des futurs branchements de ressources. Aucune route de consultation de CNIE n'est supposée déjà livrée.

**Correctif minimal proposé :** traiter une clé CNIE comme sensible avant la récursion générique. Masquer sa valeur textuelle/numérique selon la règle retenue, et supprimer ou remplacer complètement les structures inattendues plutôt que les conserver en clair. Ajouter les cas imbriqués et non textuels.

### 3 — Un blocage global IP est journalisé à nouveau pour chaque nouvel identifiant

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Providers/AppServiceProvider.php:82`, clé `Cache::add` à la ligne 84 et appel depuis la réponse 429 à la ligne 105.
- **Qualification : bug confirmé par exécution du middleware de limitation et du service réels.**

**Scénario reproductible :** depuis la même IP, soumettre 103 identifiants fictifs distincts dans une fenêtre d'une minute. Le compteur par cible reste inférieur à cinq, mais la limite globale de 100/min/IP bloque les trois dernières requêtes. Résultat observé : fin de séquence `422, 422, 429, 429, 429`, **trois lignes `auth.login_throttled`**.

Le banc de contrôle a exécuté `ThrottleRequests` avec le limiteur `login` enregistré, une horloge figée, un cache en mémoire et une réponse 422 simulant le contrôleur pour les requêtes autorisées. Les trois écritures de blocage passent par le vrai callback et MySQL. Ce n'est pas un test de charge réseau.

La clé de déduplication contient toujours `loginTarget(identifier)|IP`, même quand le blocage provient de la limite IP commune. Changer de cible produit alors une nouvelle clé de journalisation.

**Impact :** la décision « une fois par blocage » et la protection annoncée contre le remplissage du journal ne sont pas respectées pour le blocage global. Après les 100 tentatives initiales, un attaquant peut continuer à produire une écriture par nouvelle cible refusée, alors que l'authentification est déjà bloquée.

**Correctif minimal proposé :** transmettre au callback l'identité de la limite réellement dépassée et dédupliquer sur cette limite et sa fenêtre : IP pour le blocage global, compte/cible + IP pour le blocage individuel. Conserver le test existant sur un même compte et ajouter le scénario global avec plusieurs cibles.

### 4 — La tolérance SQL autorise une suppression avant l'anniversaire d'un an

- **Sévérité : Mineur.**
- **Fichier et ligne :** `backend/database/migrations/2026_11_16_120000_create_audit_logs_table.php:50`.
- **Qualification : bug confirmé sur MySQL 8.0.46.**

**Scénario reproductible :** insérer une ligne avec `created_at = NOW() - INTERVAL 1 YEAR + INTERVAL 12 HOUR`, puis effectuer un DELETE direct ciblant cette ligne. Écart mesuré au seuil annuel SQL : **43 200 secondes**. Résultat du DELETE : **une ligne supprimée**, alors qu'elle n'a pas encore un an.

Cette reproduction utilise uniquement l'horloge SQL pour l'insertion et la comparaison : un décalage PHP/MySQL ne peut pas expliquer le résultat. Le `+ INTERVAL 1 DAY` ouvre intentionnellement cette fenêtre.

**Impact :** la seconde protection annoncée refuse les suppressions récentes sauf dans une fenêtre pouvant atteindre un jour avant l'anniversaire. La commande applicative `purge()` conserve son prédicat strict ; aucune suppression prématurée par cette commande n'est affirmée. Le défaut concerne la garantie SQL contre une requête directe.

**Correctif minimal proposé :** aligner les conventions temporelles de PHP et de la connexion MySQL, puis comparer au seuil annuel exact, sans tolérance d'un jour. Vérifier juste avant, à et après ce seuil dans les deux environnements de fuseau prévus. Ne pas compenser un décalage horaire par une réduction de la durée de conservation.

## 3. Points vérifiés sans remarque

- Les deux endpoints d'audit sont placés dans `auth:sanctum`, `session.valid` et `permission:audit.view`. Les tests vérifient le refus sans session et le refus des quatre autres rôles, y compris sur `/filters`.
- La pagination est bornée à 100, avec 50 par défaut. Les filtres sont validés ; type et identifiant d'élément sont exigés ensemble. Le tri `created_at DESC, id DESC` est stable.
- Le filtre de date de fin seule a été essayé avec le validateur réel : accepté. Aucun défaut n'est retenu sur ce cas.
- Les index couvrent auteur, date, élément et catégorie/date. Aucun test de capacité ni `EXPLAIN` sur un volume de production n'a été effectué.
- Les libellés sont recopiés, sans dépendre d'une jointure vers un compte encore existant. L'absence volontaire de clé étrangère évite qu'une suppression de compte altère l'historique. Le service prend l'auteur depuis le garde serveur ou le paramètre typé ; aucune saisie publique de `user_id` d'auteur n'est introduite.
- Les créations, modifications, désactivations/réactivations, suppressions/restaurations et changements de rôle sont branchés. Création de compte et attribution de rôle sont deux actions distinctes, pas un doublon du même événement.
- Le changement de mot de passe est noté sans son ancienne ni sa nouvelle valeur dans l'observateur courant. La mise à jour de `last_login_at` n'est pas présentée comme une modification du compte ; la connexion possède sa propre ligne.
- Le drapeau `replacingRoles` est réinitialisé dans un `finally`. La ligne de changement de rôle est écrite dans la même transaction que l'association ; une exception propagée entraîne leur rollback commun. Vérification de cette structure par lecture ; aucun test d'injection d'erreur à mi-transaction n'a été ajouté.
- Les refus de connexion du contrôleur comportent motif et identifiant, pas le mot de passe. Le cas d'un blocage individuel répété sur le même compte est correctement dédupliqué et testé ; le constat 3 vise le blocage global.
- Le modèle refuse les modifications et suppressions via ses événements. Le déclencheur UPDATE refuse la modification directe ; les tests MySQL le confirment. La suppression d'une ligne très récente est refusée.
- `purge()` ne cible que `audit_logs` ; aucune suppression de compte, passage ou autre table n'y figure. Le test de commande confirme le maintien des comptes. La planification quotidienne à 3 h est présente.
- Le lien frontend dépend de `audit.view`, la garde de route réserve l'écran à l'administrateur. Les tests couvrent l'absence de lien et d'appel API pour un autre rôle, les catégories et le détail avant/après avec IP.
- `Intl.DateTimeFormat` impose `Africa/Casablanca`. Les boutons de catégories et de détail portent `aria-pressed` et `aria-expanded`. Le tableau prévoit le défilement horizontal et la pagination. React rend les valeurs comme texte ; aucun HTML brut provenant des JSON n'est injecté.
- La structure de la maquette est reprise : catégories, auteur, période, tableau et détail. Le filtre « Connexions » et l'absence d'export sont conformes aux décisions. Aucun nouveau parcours navigateur complet n'a été exécuté pendant cette revue.

## 4. Questions et limites à faire expliciter

1. **TRUNCATE et comptes SQL de production.** Un `DB::table('audit_logs')->truncate()` a effectivement effacé une ligne récente dans les tables de contrôle avec le compte SQL local. Les événements du modèle et les déclencheurs DELETE ne protègent pas cette DDL. MySQL exige le droit DROP pour TRUNCATE et ne déclenche pas les événements DELETE ([documentation MySQL](https://dev.mysql.com/doc/refman/8.0/en/truncate-table.html)). Aucune route permettant ce TRUNCATE n'a été trouvée : ce résultat ne démontre pas une attaque HTTP accessible à un rôle métier. Confirmer pour T-01 la séparation entre compte de migration et compte d'exécution, ainsi que les privilèges DDL de ce dernier. Une garantie contre le compte propriétaire du schéma ne peut pas reposer uniquement sur ces déclencheurs.
2. **Migration `down()`.** Elle retire volontairement les déclencheurs et la table ; elle est donc destructive. Ce n'est pas un mécanisme de purge métier. Confirmer les règles opérationnelles interdisant son utilisation sur un journal à conserver en production.
3. **Écritures de comptes hors observateur.** `saveQuietly()` et les mises à jour de masse ne déclenchent pas les événements Eloquent. L'usage visible sur `last_login_at` est intentionnel et accompagné d'un événement de connexion. Aucun nouveau contrôleur administratif de masse n'est livré ici : leur journalisation devra être explicite dans les tâches concernées. Ces voies ne doivent pas être présentées comme automatiquement couvertes par l'observateur.
4. **Auteur et IP hors HTTP.** Une exécution console laisse normalement l'auteur et l'IP à null ; l'écran l'affiche actuellement comme « Visiteur non connecté ». Confirmer si « Système » doit distinguer les seeders/commandes des visiteurs. Le proxy de confiance reste un point de mise en ligne, sans nouveau bug T-06 établi.

Les identifiants de connexion saisis font volontairement partie du journal réservé à l'administrateur, selon la décision du 16/11. Leur présence n'est pas considérée en elle-même comme une fuite ; les secrets et la CNIE des constats 1 et 2 sont une autre question.

## 5. Qualité des tests

Les 23 tests du journal couvrent des cas utiles : droits des cinq rôles, champs renvoyés, historique d'un élément, filtres combinés, pagination, événements, valeurs avant/après, changement de rôle sans doublon, blocage individuel et protections SQL élémentaires.

Ils laissent passer les quatre scénarios reproduits :

- le test de confidentialité ne fournit pas d'alias de mot de passe et ne fournit que des CNIE textuelles directes ;
- le test de purge utilise une ligne de plus d'un an et deux jours et une autre de onze mois, donc ne vérifie pas la fenêtre d'un jour du déclencheur ;
- le test de blocage ne dépasse pas la limite globale IP avec plusieurs cibles ;
- les tests des filtres frontend vérifient le paramètre envoyé, mais l'API simulée renvoie la même ligne indépendamment du filtre. Ils vérifient le câblage, pas le filtrage serveur, qui est couvert séparément par les tests backend.

Autres cas importants à ajouter lors des corrections : rollback d'un changement de rôle si l'écriture d'audit échoue, bornes annuelles exactes et fuseaux de connexion, secret/CNIE sous structures inattendues. Aucun fichier de test n'a été créé par cette revue. Les contournements via `upsert`, `insertOrIgnore`, `forceDelete` et toutes les variantes de sauvegarde n'ont pas été exécutés exhaustivement ; le raisonnement sur le déclencheur UPDATE ne remplace pas une campagne complète de ces opérations.

## 6. Validation locale et limites

Environnement : **PHP 8.5.12, MySQL 8.0.46, Node 24**. Aucun test SQLite. PHP 8.4 exact reste non vérifié (ENV-01).

| Commande exécutée | Résultat |
|---|---|
| `docker compose up -d mysql` | Conteneur MySQL actif avec le compose courant |
| `php vendor/bin/pint --test` | Réussi |
| `php vendor/bin/phpstan analyse --no-progress` | Réussi, aucune erreur |
| `php artisan test --no-ansi` | **201 tests réussis, 575 assertions**, **1 142,18 s** (19 min 02 s), sortie 0 |
| `npm.cmd run lint` | Réussi |
| `npm.cmd run test` | **20 tests réussis**, 2 fichiers |
| `npm.cmd run build` | Réussi, TypeScript et Vite |
| `git diff --check -- backend frontend` | Réussi |

Les résultats annoncés pour les 23 tests d'audit et les 36 tests d'authentification sont confirmés dans cette suite complète. Les contrôles verts ne couvrent pas les défauts reproduits.

Reproductions supplémentaires exécutées en mémoire, sans fichier de test dans le dépôt :

- Tables MySQL de contrôle préfixées `craa543c6_`, `crc654817_` et `crd47d099_`. Les scénarios destructifs ont uniquement ciblé ces tables. Les tables de contrôle ont été supprimées en fin d'exécution ; aucun journal applicatif n'a été tronqué par ces reproductions.
- Pour isoler les déclencheurs sans toucher aux noms du schéma de travail, les noms de tables/cibles de déclencheurs ont été adaptés en mémoire. Les clauses de protection, dont le seuil annuel, sont inchangées. La suite du projet a, elle, exécuté la migration originale sur `jobdating_test`.
- La première reproduction a confirmé les valeurs sensibles persistées, puis rencontré une erreur dans le script de contrôle lors de l'insertion d'une expression SQL dans un attribut de date Eloquent. Son nettoyage s'est exécuté. Les contrôles suivants ont inséré la date via le query builder. Cette erreur du banc n'est pas attribuée au projet.
- Un premier essai de la limite globale a dépassé la fenêtre temporelle avant d'atteindre le seuil : il n'a pas démontré le défaut. Le rejeu déterministe avec horloge figée et cache en mémoire a établi les trois réponses 429 et les trois lignes pour le même blocage IP.
- Maquette inspectée et code frontend comparé ; pas de capture navigateur de l'écran réel ni de validation des privilèges SQL de production pendant cette revue.

## 7. Verdict

**À corriger avant fusion.**

Les protections de confidentialité du service et la déduplication du blocage global doivent être corrigées et couvertes par des régressions. Le seuil SQL doit également respecter l'année de conservation sans fenêtre de suppression anticipée. Aucun correctif n'a été appliqué par l'auditeur.

# Deuxième contre-revue indépendante — T-05 (JD-12), constat 6

- **Date :** 17/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-05-mot-de-passe`.
- **Base et HEAD :** `develop`, `d0b92d4fa65c915f230493ff5834758b010afd9f`.
- **État :** travail non commité ; diff avec `develop` et fichiers non suivis concernés examinés.
- **Livrable :** ce rapport uniquement. Aucun code ni test modifié, aucun autre fichier créé dans le dépôt par l'auditeur, aucun commit ni push. Les rapports précédents sont conservés.

## 1. Références et périmètre

Consignes appliquées : `AGENTS.md`, `CLAUDE.md` et demande de deuxième contre-revue. Références : constat 6 de `REVIEW-T-05-contre-revue.md`, décision Entreprise dans `DECISIONS.md:33`, correction décrite dans `PROGRESS.md:228`.

Fichiers examinés :

- `backend/app/Http/Controllers/Auth/PasswordChangeController.php`, notamment lignes 70 à 78 ;
- `backend/app/Services/Auth/SessionRevoker.php`, notamment lignes 46 à 95 ;
- `backend/tests/Feature/Passwords/PasswordChangeTest.php`, nouveau test à la ligne 141 ;
- code installé de Sanctum `AuthenticateSession::validatePasswordHash()`, de Laravel `SessionGuard::login()` et du stockage de session ; `SessionOpener` et `AuditLogger` pour leurs interactions.

Revue limitée au constat 6 et aux effets de sa correction. Les constats 1 et 3 restent validés ; E-01 à E-03 restent reportés à T-49. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Rejeu du constat 6

| Constat | Statut | Preuve locale |
|---|---|---|
| Une session Entreprise révoquée peut redevenir valide lors d'un changement ultérieur | **Corrigé** | Ancienne ligne supprimée lors du changement ; ancien client refusé **401 deux fois**. Le recruteur courant et son collègue restent acceptés **200 deux fois chacun**. |

**Scénario exécuté :**

1. Connexion du client A avec M0 : **200** ; conservation de ses cookies et de sa ligne de session.
2. Réinitialisation vers M1 sous verrou, révocation des liens et `closeAll()` dans une transaction : **zéro session** du compte en base.
3. Réinsertion de la ligne de A : reproduction déterministe d'une écriture tardive après la révocation. Son empreinte est bien différente de celle attendue pour M1.
4. Connexion de deux recruteurs B et C avec M1 : **200** chacun. Changement de B vers M2 : **204**.
5. Vérification de la suppression de la ligne de A avant toute requête de ce client ; deux appels `/me` par client : A **401/401**, B **200/200**, C **200/200**.

La réinitialisation administrative est représentée par son contrat de service réel, sans prétendre tester un endpoint administratif futur. Comme lors de la reproduction précédente, les cookies passent par le noyau HTTP Laravel.

**Pourquoi la correction fonctionne :** le contrôleur capture le hash en vigueur sur la ligne verrouillée, avant l'écriture du nouveau mot de passe. Le service ne renouvelle que les empreintes correspondant à ce hash précédent. Une empreinte issue de M0 ne peut donc plus être remplacée par celle de M2 après une réinitialisation vers M1.

## 3. Nouveaux constats

**Aucun nouveau constat à signaler dans le périmètre examiné.**

La suppression des sessions sans empreinte ou illisibles n'a pas fait apparaître de cas réaliste de déconnexion injustifiée dans le fonctionnement actuel. `SessionGuard::login()` écrit l'empreinte dès la connexion, avant la sauvegarde de la session ; Sanctum la réécrit après les requêtes authentifiées. L'ancien format brut reste accepté. Un payload illisible ne permet déjà pas à Laravel de reconstituer une session authentifiée.

Une session fabriquée ou issue d'un autre ancien mécanisme, contenant une identité mais aucune empreinte, serait supprimée. Aucun producteur de telles sessions légitimes n'a été identifié dans le code concerné ; ce cas ne justifie pas un nouveau constat. La limite concurrente déjà acceptée dans `PROGRESS.md` n'est pas rouverte.

## 4. Qualité du nouveau test

Référence : `PasswordChangeTest::test_entreprise_une_session_revoquee_ne_redevient_pas_valable()`, ligne 141.

Le test couvre bien la chaîne défaillante : ancienne connexion, remplacement du mot de passe et `closeAll()`, réinsertion de l'ancienne ligne, deux nouvelles connexions légitimes, puis changement depuis le profil. Il exige la suppression de l'ancienne ligne **avant** les appels du client révoqué : le nettoyage provoqué par un 401 ne peut pas faire passer cette assertion par accident. Les vérifications du collègue et du recruteur évitent de valider une solution qui fermerait toutes les sessions.

**Il échouerait sur le code de la contre-revue précédente :** ce code conservait la ligne et remplaçait son empreinte ; `assertDatabaseMissing` échouerait, puis l'ancien client recevrait 200. C'est précisément le résultat obtenu lors de la première contre-revue. Aucune ancienne version n'a été réinstallée pour relancer le test.

Le test reste séquentiel : il représente l'entrelacement par une réinsertion. Il ne mesure pas une course entre deux processus MySQL. Les formats hérités et payloads anormaux ont été contrôlés séparément par les bancs ci-dessous.

## 5. Points vérifiés sans remarque

| Contrôle local ciblé | Résultat |
|---|---|
| Correspondance avec Sanctum installé | Même vérification : HMAC calculé par `hashPasswordForCookie()`, puis hash brut en compatibilité ; comparaisons `hash_equals`. Le service refuse aussi une empreinte non textuelle. |
| Empreinte HMAC valide | Deux recruteurs Entreprise restent connectés sur plusieurs requêtes après changement. |
| Empreinte brute valide | Acceptée par Sanctum avant changement ; remise au format brut pour tester le service, puis convertie en HMAC courant ; client accepté **200/200** après changement. |
| Empreintes brute et HMAC périmées | Lignes supprimées. |
| Empreinte absente, nulle ou tableau | Lignes supprimées, sans erreur du service. |
| Payload illisible ou valeur sérialisée non tableau | Lignes supprimées, sans erreur du service. `allowed_classes => false` est conservé. |
| Autres rôles | Pour Administrateur, CME, Direction et Apprenant : changement **204**, session courante **200**, autre client **401/401**. |
| Fermeture complète Entreprise | Après `closeAll()`, les deux clients reçoivent **401**. |
| Session courante | Identifiant renouvelé et heure de connexion conservée pour les cinq rôles. |
| Audit | Le hash précédent est absent des lignes d'audit du compte contrôlé ; le changement transmet uniquement la note attendue au journal. |
| Exception et trace | Exception injectée pendant la lecture des sessions, avec arguments de trace activés : hash précédent absent du message et de la trace ; présence de `SensitiveParameterValue`. Les deux paramètres sensibles sont aussi confirmés par réflexion. |

Le hash précédent reste une variable locale ; aucun stockage supplémentaire ni passage au journal n'a été ajouté. Les annotations `#[SensitiveParameter]` couvrent les deux méthodes recevant explicitement cette valeur. Le contrôle d'exception porte sur ce chemin représentatif, sans prétendre auditer tous les outils de diagnostic possibles.

## 6. Validation et limites

### Exécuté par cet auditeur

Environnement constaté : **PHP 8.5.12 CLI**, **MySQL 8.0.46**, base **`jobdating_test`**, collation de connexion **`utf8mb4_unicode_ci`**. Aucun SQLite.

Bancs PHP en mémoire : rejeu du constat 6 avec trois clients, contrôles des cinq rôles, fermeture complète, formats HMAC/brut, payloads anormaux, audit et exception injectée. Les requêtes avec cookies utilisent le noyau HTTP, en environnement de test, pilote `database` et sessions non chiffrées. Ce n'est pas une recette dans un navigateur réel.

Les tables de contrôle `review_t05_<pid>_` utilisent des données fictives et sont supprimées en `finally`. Les copies préservent colonnes et index, pas les clés étrangères ni les déclencheurs ; aucune conclusion sur ces derniers n'est déduite des bancs. La recherche finale des tables préfixées est vide. `git diff --check develop` réussit.

Aucun test de charge ni concurrence réelle entre deux connexions MySQL. ENV-01 reste ouvert pour PHP 8.4 exact. Les contrôles de la première contre-revue non concernés par cette correction n'ont pas été repris.

### Communiqué par Claude, non rejoué par cet auditeur

Résultats du 17/11/2026 après correction, sur PHP 8.5.12, MySQL 8.0 et Node 24 :

- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur.
- `php artisan test tests/Feature/Passwords/PasswordChangeTest.php` : **16 tests réussis**.
- Suite complète `php artisan test` : **309 tests réussis, 1312 assertions**, environ 29 minutes, code de sortie 0.
- Frontend : aucun fichier modifié depuis la contre-revue précédente, selon les informations communiquées ; aucun contrôle frontend relancé ici.

Ces commandes n'ont pas été relancées. Le nouveau test PHPUnit a été lu ; les exécutions indépendantes sont les bancs ciblés décrits ci-dessus.

## 7. Verdict

**À fusionner.**

Le constat 6 est corrigé. Le scénario qui réactivait une session révoquée reste refusé, tandis que les recruteurs valides conservent leur accès. Aucun nouveau défaut justifié par cette correction n'a été identifié. Ce verdict respecte le périmètre limité et les reports acceptés à T-49.

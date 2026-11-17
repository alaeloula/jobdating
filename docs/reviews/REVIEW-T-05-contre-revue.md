# Contre-revue indépendante — T-05 (JD-12) : activation et mots de passe

- **Date :** 17/11/2026, Africa/Casablanca.
- **Branche :** `feature/T-05-mot-de-passe`.
- **Base et HEAD :** `develop`, `d0b92d4fa65c915f230493ff5834758b010afd9f`.
- **État :** travail non commité ; examen du diff avec `develop` et du contenu des fichiers non suivis concernés.
- **Livrable :** ce rapport uniquement. Aucun code ni test modifié, aucun autre fichier créé par l'auditeur dans le dépôt, aucun commit ni push.

**Nouvelle lecture le 17/11/2026 après retransmission du prompt :** les contrôleurs de changement et d'activation, le service de révocation et les tests concernés ont été relus. La logique à l'origine du constat 6 est toujours présente : toute empreinte existante est remplacée, sans comparaison avec celle du mot de passe courant avant le changement. Aucun test couvrant la chaîne de réactivation décrite ci-dessous n'a été ajouté dans `PasswordChangeTest.php`. Le verdict est maintenu. Les résultats des bancs locaux ci-dessous proviennent de la première contre-revue ; ils n'ont pas été exécutés une seconde fois lors de cette nouvelle lecture.

## 1. Références et périmètre

Consignes : `AGENTS.md`, `CLAUDE.md` et demande de contre-revue. Références : `REVIEW-T-05-mot-de-passe.md`, corrections décrites dans `PROGRESS.md:219`, décision Entreprise du 17/11 dans `DECISIONS.md:33`, `ERREURS_A_CORRIGER.md` et T-49 dans `BACKLOG.md:70`.

Examen limité aux corrections des constats 1 et 3, à leurs tests et effets de bord, au report accepté des constats 2/4/5 et à la conservation des sessions Entreprise. Fichiers principaux : `PasswordChangeController.php`, `ActivationController.php`, `SessionRevoker.php`, `SessionOpener.php`, les tests `PasswordChangeTest.php` et `ActivationAndResetTest.php`, `ProfilePage.tsx` et `passwords.test.tsx`. Lecture complémentaire du service de jetons, du middleware Sanctum et du stockage des sessions pour vérifier leurs interactions.

Le reste de T-05 n'est pas réaudité. Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Rejeu des constats initiaux

| Constat | Statut | Preuve obtenue localement |
|---|---|---|
| 1 — Changement avec un objet utilisateur chargé avant une réinitialisation | **Corrigé** | Réinitialisation réelle : **200**. Changement avec l'ancien objet et l'ancien mot de passe : **422**, « Mot de passe actuel incorrect. ». Mot de passe de réinitialisation conservé ; aucun audit `PasswordChanged`. |
| 3 — Échec du journal de connexion après activation | **Corrigé** | Exception injectée uniquement sur `LoginSucceeded` : **500**, activation et nouveau mot de passe annulés, `last_login_at` nul, aucun audit d'activation, lien toujours valide. Requête suivante avec les cookies reçus : **401**, aucune session authentifiée du compte. Après rétablissement du journal, le même lien permet l'activation : **200**, puis `/me` : **200**. |

### Constat 1 : vérification sous verrou et session courante

Référence : `backend/app/Http/Controllers/Auth/PasswordChangeController.php:55–88`.

Le scénario initial a été rejoué avec les contrôleurs et services réels : objet conservé avant une réinitialisation, consommation du lien de réinitialisation, puis invocation du changement avec cet objet périmé. Les deux comparaisons de mots de passe utilisent désormais le modèle relu sous verrou ; l'écriture porte aussi sur ce modèle. Le contrôle de désactivation est dans cette transaction.

Le transfert des attributs enregistrés au modèle du garde, avant la migration de session, permet à Sanctum de conserver l'empreinte correcte. Le banc HTTP avec deux clients par rôle confirme : changement **204**, session courante **200**, nouvel identifiant et heure de connexion conservée pour les cinq rôles. Les autres clients sont refusés pour les quatre rôles non Entreprise ; l'exception Entreprise est examinée ci-dessous.

### Constat 3 : annulation et compte précédemment connecté

Référence : `backend/app/Http/Controllers/Auth/ActivationController.php:47–71`.

L'ouverture de session et son audit sont maintenant dans le rappel transactionnel de `consume()`. Le nettoyage du garde et de la session couvre l'ouverture commencée avant l'exception.

Le rejeu a aussi été effectué avec un autre compte B déjà connecté dans le même client. Après l'échec de l'activation de A, `/me` répond **401** et aucune session de B ne subsiste : B n'est pas reconnecté par l'annulation. C'est cohérent avec la décision B, puisque `closeForeignSession()` intervient avant la transaction d'activation. Une nouvelle tentative avec le lien de A réussit après rétablissement du journal.

## 3. Constats 2, 4 et 5 reportés

Le report vers **T-49, avant la recette**, est appliqué conformément à la décision du responsable technique. Ces éléments ne sont pas comptés parmi les corrections exigées par cette contre-revue.

| Constat initial | Suivi | Avis sur la description et la correction prévue |
|---|---|---|
| 2 — Réponse `/me` tardive réinstallant B | **E-01** | Scénario, helper concerné et annulation attendue de toutes les requêtes, dont `auth/me`, fidèles au constat. Le serveur conserve l'identité A ; l'affichage erroné reste un défaut d'interface, sans contournement backend démontré. |
| 4 — Échec de mise en file de l'audit après remise de l'e-mail | **E-02** | Décrit correctement la perte de la ligne d'audit, sans renvoi de l'e-mail. Écriture directe, puis tâche de secours : correction prévue cohérente ; l'indisponibilité simultanée des deux voies reste explicitement documentée comme risque résiduel accepté. |
| 5 — Nouveau fragment ignoré sur une page déjà montée | **E-03** | Scénario, hook et tests prévus fidèles : traiter chaque nouveau fragment et neutraliser la vérification précédente. Le contournement par réouverture du lien est indiqué. |

Je ne relève pas, dans les corrections examinées, d'aggravation de ces cas ni d'effet bloquant supplémentaire sur les contrats repris par les tâches suivantes. Aucun désaccord argumenté avec leur report ne ressort de ce périmètre.

## 4. Nouveau constat

### 6 — La mise à jour des empreintes Entreprise peut réactiver une session déjà révoquée

- **Sévérité : Majeur.**
- **Fichier et lignes :** `backend/app/Services/Auth/SessionRevoker.php:75–80` ; appel après écriture du nouveau mot de passe dans `backend/app/Http/Controllers/Auth/PasswordChangeController.php:70–74`.
- **Qualification : bug confirmé sur MySQL**, par entrelacement déterministe et requêtes HTTP avec cookies distincts. Ce n'est pas une mesure de fréquence ni un essai avec deux processus simultanés.
- **Référence :** décision du 17/11, `DECISIONS.md:33` : la réinitialisation administrative doit fermer toutes les sessions Entreprise pour reprendre la main après une fuite. La protection des sessions réécrites après révocation est aussi documentée en tête de `SessionRevoker.php` et testée dans `ActivationAndResetTest.php:204`.

**Problème :** le service remplace toute empreinte présente par la nouvelle, sans vérifier qu'elle correspondait au mot de passe courant juste avant le changement. Il traite donc aussi une empreinte devenue invalide lors d'une révocation précédente.

**Scénario reproductible :**

1. Connecter un client A au compte Entreprise avec M0 ; conserver ses cookies et la ligne de session.
2. Réinitialiser le compte vers M1 et appeler `closeAll()` dans la transaction : toutes ses lignes de session sont supprimées.
3. Réinsérer la ligne de A avec son empreinte de M0, pour reproduire l'écriture tardive d'une requête déjà en cours lors de la réinitialisation.
4. Connecter un client B avec M1, puis changer le mot de passe depuis son profil vers M2.
5. Appeler deux fois `/api/v1/me` avec les anciens cookies de A.

**Résultat obtenu :** connexion initiale **200** ; zéro session après `closeAll()` ; nouvelle connexion B **200** ; empreinte de A confirmée différente de celle de M1 avant le changement ; changement B **204** ; ancien client A accepté **200**, puis **200** à nouveau. A ne connaît ni M1 ni M2.

La réinitialisation administrative a été représentée par son contrat de service réel : écriture du mot de passe sous verrou, révocation des liens et `closeAll()` dans une transaction. Aucun écran ni endpoint administratif futur n'a été testé. La réinsertion reproduit le mécanisme déjà utilisé par le test existant des sessions réécrites après révocation.

**Impact :** une session que la réinitialisation devait rendre inutilisable retrouve l'accès lorsque le compte effectue ensuite un changement depuis son profil. Cela compromet le contrat de reprise de contrôle destiné à T-10 à T-12. Ce cas diffère de la limite acceptée dans `PROGRESS.md` : cette limite déconnecte un recruteur lors d'un changement ; ici une session révoquée redevient autorisée.

**Correctif minimal proposé, sans l'appliquer :** capturer le hash du mot de passe courant sur le modèle verrouillé, avant sa modification, et transmettre l'information nécessaire au service. Ne remplacer que les empreintes correspondant à ce hash précédent, avec les formats acceptés par Sanctum (HMAC, et ancien format brut si conservé). Laisser les empreintes périmées invalides, ou supprimer leurs lignes. Conserver cette information seulement en mémoire, sans journalisation. Ajouter la chaîne « réinitialisation → réécriture tardive → nouveau changement Entreprise → ancien client toujours 401 », tout en gardant les recruteurs encore valides connectés.

## 5. Qualité des tests

- **`PasswordChangeTest.php:209`** : le test fournit réellement l'ancien objet après changement du hash en base et exige 422, conservation du nouveau secret et absence d'audit. Il détecte le défaut initial ; l'ancien code aurait accepté le changement. Le pilote `array` utilisé ici isole ce contrôle du middleware de session : il ne prouve pas à lui seul le fonctionnement des verrous MySQL.
- **`ActivationAndResetTest.php:135`** : le double fait échouer le second audit, après réussite du premier. Les assertions sur activation, mot de passe, `last_login_at`, lien, cookies et sessions détectent la validation partielle initiale. L'ancien code échouerait sur ces assertions. Le rejeu indépendant a aussi vérifié la réutilisation effective du lien après rétablissement du journal.
- **Tests de sessions dans `PasswordChangeTest.php:83`, `:109` et `:138`** : les requêtes suivantes des deux clients vérifient des effets réels. Le test Entreprise couvre plusieurs requêtes, pas seulement la première. Celui de `closeAll()` prouve la suppression nominale, mais ne combine pas une réécriture tardive avec un changement de profil ultérieur : il peut donc passer malgré le nouveau constat 6. Le test de réécriture tardive existant porte sur un Apprenant et ne couvre pas cette interaction Entreprise.
- **`frontend/src/passwords/passwords.test.tsx:312`** : le test exige le nouveau message Entreprise après le succès ; il détecterait le maintien de l'ancien texte. Il ne prétend pas vérifier la persistance des sessions backend.

L'échec attendu sur l'ancien code est déduit de sa logique décrite dans la revue initiale ; aucune ancienne version n'a été installée pour réexécuter ces tests.

## 6. Points vérifiés sans remarque

| Contrôle ciblé exécuté | Résultat |
|---|---|
| Deux clients pour chacun des cinq rôles | Connexions 200 ; changement 204 ; client courant 200. Autre client : 401 deux fois pour Administrateur, CME, Direction et Apprenant ; 200 deux fois pour Entreprise. |
| Identifiant et heure de connexion | Identifiant du client courant renouvelé et heure conservée pour les cinq rôles. |
| Fermeture complète nominale Entreprise | Après `closeAll()`, les deux clients reçoivent 401. |
| Payload illisible, valeur sérialisée non tableau, tableau sans empreinte | Valeurs laissées intactes ; aucun échec du service. Une empreinte absente n'est pas inventée par ce service. |
| Tableau valide avec empreinte et autre donnée | Nouvelle empreinte HMAC enregistrée ; autre donnée conservée. |
| Objet avec méthode `__wakeup` dans le tableau | Aucun appel de cette méthode pendant la réécriture : `allowed_classes => false` est effectif. |
| Connexion normale via `SessionOpener` | Succès des connexions des cinq rôles ; activation réussie avec `last_login_at` enregistré et `/me` accepté. |

À la lecture : les limites de cinq tentatives et de douze heures restent en place ; la migration conserve les données de session. Les tests existants ciblent ces limites. Ils n'ont pas été relancés séparément ici. Le message de `ProfilePage.tsx:97` et la note d'audit distinguent correctement Entreprise et autres rôles.

L'écriture `base64(serialize(...))` correspond au format du pilote `database` avec sessions non chiffrées. La limite documentée d'une écriture concurrente réinstallant l'ancienne empreinte après un changement de profil est acceptée ; elle n'est pas ajoutée aux constats.

## 7. Validation et limites

### Contrôles exécutés par cet auditeur

Environnement constaté : **PHP 8.5.12 CLI**, **MySQL 8.0.46**, base **`jobdating_test`**, collation de connexion **`utf8mb4_unicode_ci`**. Aucun contrôle sous SQLite.

Bancs PHP en mémoire, données fictives, copies de tables préfixées `review_t05_<pid>_`, supprimées en `finally`. Les copies conservent colonnes et index, mais pas les clés étrangères ni les déclencheurs : aucune conclusion supplémentaire sur ces derniers. Les contrôles de cookies passent par le noyau HTTP Laravel, avec pilote de session `database` non chiffré, dans l'environnement de test ; il ne s'agit pas d'une recette dans un navigateur réel.

Les résultats locaux sont ceux détaillés ci-dessus : rejeux des constats 1/3, nouvel entrelacement Entreprise, cinq rôles, compte B avant échec, reprise de l'activation et robustesse des payloads. `git diff --check` réussit. Les entrelacements sont imposés de manière déterministe ; aucun test de charge ni banc de concurrence entre deux connexions MySQL n'a été exécuté.

### Résultats communiqués par Claude, non rejoués par cet auditeur

Résultats du 17/11/2026, après corrections, sur PHP 8.5.12, MySQL 8.0 et Node 24 :

- `vendor/bin/pint --test` : réussi ; `vendor/bin/phpstan analyse` : aucune erreur.
- `PasswordChangeTest.php` : **15 tests réussis** ; `ActivationAndResetTest.php` : **12 tests réussis**.
- Suite backend complète : **308 tests réussis, 1302 assertions**, code de sortie 0, environ 29 minutes.
- Frontend : lint réussi, **43 tests réussis**, build réussi.

Ces commandes n'ont pas été relancées. ENV-01 reste ouvert pour PHP 8.4 exact. Les trois reports acceptés vers T-49 ne sont pas des motifs du verdict ci-dessous.

## 8. Verdict

**À corriger avant fusion.**

Les constats initiaux 1 et 3 sont corrigés. Un nouveau défaut majeur doit être corrigé dans la conservation des sessions Entreprise : ne pas renouveler l'empreinte d'une session déjà invalidée par une réinitialisation.

# Prompt de contre-revue Codex — T-06 (JD-13) : journal d'audit

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-06-journal-audit.md` (verdict « À corriger avant fusion »). Claude a traité tes constats : tu fais la **contre-revue**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou hors dépôt uniquement). Seul livrable : `docs/reviews/REVIEW-T-06-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-06-journal-audit`, base `develop` (`71968df`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, section « Corrections après la revue indépendante (Codex, …REVIEW-T-06…, 16/11) ».
- MySQL : `docker compose up -d mysql` (option `--log-bin-trust-function-creators=1` du compose). Tests sur `jobdating_test`, jamais SQLite.

## 2. Décisions du responsable technique prises après ta revue (16/11)

À appliquer, ne pas remettre en cause (voir `docs/DECISIONS.md`) :
- **Constat 2 (CNIE non textuelle) : volontairement NON corrigé.** La CNIE reste masquée quand elle est fournie sous forme de texte ; les autres formats ne reçoivent pas de traitement supplémentaire. Ne pas le reclasser en défaut ; tu peux seulement confirmer que rien n'a changé à cet endroit.
- **Refus de connexion journalisés seulement pour un compte existant** : un identifiant inconnu n'écrit plus rien (les blocages restent journalisés).
- **Auteur « Système »** et IP nulle pour une action hors requête web (commande, seeder, planificateur) ; « Visiteur non connecté » reste l'affichage d'une requête web sans session.
- **Purge applicative à un an et un jour**, déclencheur MySQL au seuil exact d'un an (conservation d'au moins un an).
- Tes questions 1 à 3 (TRUNCATE / comptes SQL, `down()` destructif, écritures silencieuses ou de masse) sont **notées pour T-01 et T-10 à T-13**, avec un avertissement dans la migration : ne pas les reclasser en défauts de T-06.

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats 1, 3 et 4** avec les mêmes scénarios, et dire : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers |
|---|---|---|
| 1 | Filtre étendu (`pwd`, `passwd`, `mot de passe`, `mdp`, `pass` en entier, `api key`, `secret`, `token`, `credential`), clé normalisée (casse, accents, séparateurs), toute profondeur ; note « Mot de passe modifié » sous la clé `note` | `app/Services/AuditLogger.php`, `app/Observers/UserAuditObserver.php` |
| 3 | Chaque limite transmet sa clé de déduplication (compte + IP / IP seule) ; champ `limite` | `app/Providers/AppServiceProvider.php` |
| 4 | Déclencheur : `OLD.created_at >= NOW() - INTERVAL 1 YEAR` ; purge : `< now()->subYear()->subDay()` | migration `2026_11_16_120000_…`, `AuditLogger::purge()` |

**B. Vérifier que les corrections n'ouvrent pas de nouveau problème**, notamment :
- le filtre élargi ne supprime-t-il pas à tort des champs légitimes (`passage`, `passages`, `password_changed_at`… : qu'en penser ?) ;
- un refus visant un compte existant reste journalisé avec identifiant et motif ; un identifiant inconnu n'écrit rien, sans différence de réponse HTTP (statut, message, code) entre les deux cas ;
- la déduplication par limite : un blocage individuel puis un blocage global dans la même minute produisent-ils bien une ligne chacun, et pas plus ;
- « Système » : détection hors requête web (`request()->route() === null`) fiable pour commandes, seeders, planificateur, files d'attente ? Faux « Système » possible pendant une requête web ?
- le déclencheur au seuil exact et l'écart de fuseau PHP / MySQL : la purge à un an et un jour peut-elle encore être refusée ? Une ligne de moins d'un an peut-elle être supprimée directement ?

**C. Qualité des nouveaux tests** (`tests/Feature/Audit/AuditLogStorageTest.php`, `AuditEventsTest.php`) : échoueraient-ils sur l'ancien code ?

## 4. Contrôles

**Ne relance pas la suite complète** (`php artisan test`, environ 20 minutes) : Claude vient de l'exécuter sur le même code. Utilise ses résultats ci-dessous, et indique-les dans ton rapport comme **communiqués par Claude, non rejoués par toi**.

Résultats de Claude (16/11/2026, après les corrections) :
- `vendor/bin/pint --test` : réussi ;
- `vendor/bin/phpstan analyse` : aucune erreur ;
- `php artisan test tests/Feature/Audit` : 27 tests réussis ;
- `php artisan test` (suite complète) : **205 tests réussis, 706 assertions** (environ 19 min, code de sortie 0) ;
- frontend : aucun fichier modifié depuis ta revue (lint, 20 tests et compilation réussis lors de ta revue).

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24.

**Tu restes libre d'exécuter tous les contrôles que tu juges utiles** (rejeu de tes scénarios des constats 1, 3 et 4, vérifications de la partie 3.B, bancs ciblés, autres fichiers de test…). **Évite seulement de relancer ce que Claude a déjà exécuté** (liste ci-dessus). Tes bancs de contrôle restent en mémoire ou dans des tables préfixées supprimées ensuite, sans fichier dans le dépôt.

La suite de Claude est **terminée** : la base `jobdating_test` est libre pour tes contrôles.

Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-06-contre-revue.md`

Même structure que `docs/reviews/REVIEW-T-04-contre-revue.md` : en-tête ; rejeu des constats 1, 3, 4 (scénario, résultat, statut) et mention du constat 2 non corrigé par décision ; nouveaux constats éventuels (sévérité, fichier et ligne, confirmé ou hypothétique, scénario, impact, correctif minimal) ; qualité des tests ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

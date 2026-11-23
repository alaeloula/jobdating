# Prompt de revue Codex — T-13 (JD-18) : import des apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as revu le **plan** de T-13 (`docs/reviews/REVIEW-PLAN-T-13.md`, puis `REVIEW-PLAN-T-13-contre-revue.md`). Le code est écrit : tu fais la **revue du code**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-13-import.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-13-import`, base `develop` (`81f9d02`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Tests dans Docker (PHP 8.4, image avec `pcntl`)** : `docker compose run --rm php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.
- **Données réelles** : aucun fichier réel d'apprenants n'est dans le dépôt ; utilise uniquement des fichiers fictifs.

## 2. Références

1. **`docs/tasks/PLAN-T-13.md`, version 2.1** : contrat de référence ; vérifie dans le code chaque point **[v2]** et **[v2.1]**.
2. `docs/DECISIONS.md` : 14/11 (fichier des mots de passe), 19/11, **20/11 « Import des apprenants (T-13) »**. Elles priment sur le CDC.
3. CDC V2 : ADM-04 (RG-IMP-01 à 07, critères, exceptions), ADM-03, TRA-01, EXI-DATA-03.
4. `docs/PROGRESS.md`, entrée « 2026-11-20 — T-13 », **paragraphe « Limites et points à signaler »** ; `docs/ERREURS_A_CORRIGER.md` (E-01 à E-04 : ne pas les signaler).

## 3. Points à vérifier en priorité

- **Lecture** (`app/Services/Imports/YouCodeFileReader.php`, `app/Support/PrivateTemp.php`) : CSV et XLSX, en-têtes en ligne 1 ou 2, types de cellules, formules, limites (dont XLSX décompressé et chaînes partagées), colonnes ignorées jamais copiées, dossier temporaire privé supprimé.
- **Aperçu** (`LearnerImportPlanner`) : chaque motif de rejet, contrôle de la colonne `Classe`, doublons (CNIE, e-mail sans casse), e-mail d'un autre compte, fiche supprimée, décision inchangée (statut compris), **empreinte** par ligne.
- **Confirmation** (`LearnerImportService`, `LearnerImportController`) : 409, clé idempotente, même clé et autre empreinte refusée, mise en file après commit sur `database-imports`, message de file = identifiant seul, rien à importer → 422.
- **Traitement** (`LearnerImportRunner`, `ProcessLearnerImport`, `config/queue.php`) : prise en charge conditionnelle, hachage hors transaction pour tous les nouveaux comptes, ordre des verrous, revalidation avant toute écriture, reprises, finalisation dans la transaction, états terminaux protégés (`failed()`, purge), erreurs expurgées (journal technique, `failed_jobs`, colonne `error`), identité de l'auteur dans le journal.
- **`LearnerService`** : `insert`, `apply`, `importCreate`, `importUpdate` ; création et modification à la main **inchangées** (régressions T-12).
- **Fichier des mots de passe** (`PasswordFileExporter`) : contenu, neutralisation, `no-store`, plusieurs téléchargements, suppression, journal ; dossier temporaire.
- **Nettoyage** (`CleanupImports`, `routes/console.php`) : seuils, états, dossiers.
- **Droits** : `learners.import` sur toutes les routes, 5 rôles.
- **Frontend** (`src/pages/LearnerImportPage.tsx`, `src/learners/importApi.ts`, navigation) : étapes, aperçu effacé, réponse tardive ignorée, clé de confirmation, 409, suivi, téléchargement (pas de cache), historique.
- **États d'erreur du frontend** : enchaînements « erreur → correction → nouvel essai » (aperçu en erreur, confirmation en erreur réseau, suivi en erreur, téléchargement en erreur, échec de l'import puis « Recommencer ») : l'état revient-il bien à la normale ?
- **Docker et CI** : `pcntl` dans `docker/php/Dockerfile` ; extensions nécessaires à OpenSpout dans la CI.
- **Tests** (`tests/Feature/Learners/LearnerImportTest.php`, `src/learners/import.test.tsx`) : faux positifs, cas manquants importants. Limites annoncées : pas de test à deux connexions, relivraison non testée à travers le worker, mesure de 1 000 comptes non faite — si tu le peux, fais toi-même ces contrôles sur des données fictives.
- **Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste.

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**.

Résultats de Claude (20/11/2026) :
- Pint et PHPStan sans erreur ; suite backend complète dans Docker : **433 tests réussis, 2230 assertions** (≈ 5 min 30, code de sortie 0), puis deux corrections d'annotations de type demandées par PHPStan (sans effet sur le comportement) et `tests/Feature/Learners` relancé : **55 tests réussis** (dont 16 pour l'import) ; frontend lint, **85 tests** et build réussis ;
- frontend : lint réussi, **85 tests réussis**, build réussi.

Environnement : PHP 8.4 dans Docker (avec `pcntl`) pour les tests, MySQL 8.0, Node 24. La suite de Claude est **terminée** : `jobdating_test` est libre. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-13-import.md`

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal ; indique si un constat est rare et sans impact sur les tâches suivantes) ; vérification des points [v2] et [v2.1] ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

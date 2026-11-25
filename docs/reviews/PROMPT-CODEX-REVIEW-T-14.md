# Prompt de revue Codex — code de T-14 (JD-19) : événements, participants et emplacements

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du code** de T-14. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire, dossiers temporaires hors dépôt ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-14-evenements.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles :** n'utilise **aucun** fichier réel, notamment dans le dossier Téléchargements. Données fictives uniquement (CNIE `ZZ…`, e-mails `@example.test`). Ne touche pas à la base de développement `jobdating` : elle contient les essais du responsable technique.

## 1. Ce qui est à revoir

- Branche `feature/T-14-evenements`, base `develop` (`1b05242`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Plan : `docs/tasks/PLAN-T-14.md` (**version 1.4**), avec `docs/reviews/REVIEW-PLAN-T-14.md` et `docs/reviews/REVIEW-PLAN-T-14-contre-revue.md`. Suivi : `docs/PROGRESS.md`, entrée « 2026-11-23 — T-14 ».
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm -T php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Références et décisions

1. CDC V2 : **ADM-05**, **ADM-07**, ADM-02 (RG-ENTR-03), ADM-04 (RG-IMP-06 et 07), ENT-01, APP-00, APP-01, matrice 3.2, modèle 8.2, TRA-01.
2. `docs/DECISIONS.md`, lignes du **23/11/2026** « Événements et participants (T-14) » et « Année des événements et vue entreprise (T-14) ». Elles **priment sur le CDC**.
3. **Décisions 1 à 14 du plan : ne les remets pas en cause.** En particulier : entreprise limitée à l'événement courant, sans sélecteur ; anciens passages invisibles pour elle ; nom terminé par l'année scolaire ; un seul événement par année scolaire.
4. Hors périmètre : programme (T-15), reprise (T-16), **import des entreprises (T-64, JD-44)**, passages et compteurs (T-30, T-33), recherche (T-23), CV et suggestions.
5. Maquettes dans `C:\Users\Youcode\Desktop\jobdating\maquettes\` : `admin-02`, `admin-03`, `admin-04`, `admin-05`, `cme-02`, `entreprise-ordi-01`, `apprenant-01`.
6. `docs/ERREURS_A_CORRIGER.md` (E-01 à E-06) : ne les signale pas.

## 3. Points à vérifier en priorité

- **Couverture** du plan v1.4 et des critères d'acceptation d'ADM-05 et ADM-07.
- **Verrous** : ordre `event_locks` → événement → classes → comptes → fiches dans `EventService`, `EventParticipationService`, `LearnerService`, `LearnerImportRunner`, `CompanyService` ; lecture après verrou ; reprises ; 1205 et 1213 ; aucun cycle réel.
- **Inscription automatique** : création, import, réactivation, restauration ; ligne retirée jamais rétablie ; compte désactivé ou supprimé ; lignes d'import inchangées.
- **Événement courant et année scolaire** : index uniques, désignation, retrait, archivage, suppression, changement de date, collision au dernier moment, fuseau `Africa/Casablanca`.
- **Création d'entreprise** : transaction unique, événement sous verrou, nettoyage du logo, aucun état partiel.
- **`EventUsage`** : contrat et protocole suffisants pour T-30.
- **Droits et fuites** : chaque route pour les 5 rôles ; `me/company-event` et `me/learner-event` ; CME limitée aux emplacements ; aucune CNIE, aucun identifiant de connexion, contact ou donnée d'évaluation pour un apprenant ; `event_id` de la réinitialisation contrôlé avant tout effet.
- **Journal** : actions, une seule ligne pour une inscription en masse, aucune donnée personnelle inutile.
- **Frontend** : `src/events/*`, `EventsPage`, `LocationsPage`, `CompanyHomePage`, `LearnerDayPage`, `CompaniesPage`, `CompanyForm`, `CredentialsSheet`, `LearnersPage`, `AppLayout`, `AppRoutes` : conformité aux maquettes, états de chargement et d'erreur, **enchaînements « erreur → correction → nouvel essai »**, réponses tardives, double clic, changement d'événement pendant une requête, cas sans événement, état conservé à tort après une erreur.
- **Qualité des tests** : échoueraient-ils sur un code faux ? Les tests de `EventConcurrencyTest` créent-ils un vrai chevauchement ? Sont-ils fiables en CI (`pcntl` et `posix` ne sont pas déclarés dans `.github/workflows/ci.yml`) ?
- **Migration** : `up` et `down`, colonne générée, ligne unique de `event_locks`.
- Signale aussi tout autre défaut évident, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

**Ne relance pas la suite complète** (≈ 8 min) : cite ces résultats comme **communiqués par Claude, non rejoués**. Tu peux relancer `tests/Feature/Events`, `tests/Feature/Companies/CompanyTest.php`, `tests/Feature/Learners/LearnerImportTest.php` et `src/events/events.test.tsx`.

Résultats de Claude (23/11/2026) :
- Pint et PHPStan sans erreur ; suite backend complète dans Docker : **515 tests réussis, 2672 assertions** (≈ 7 min 40, code de sortie 0), dont 39 dans `EventTest` et 10 dans `EventConcurrencyTest` ;
- frontend : lint, **110 tests** et build réussis ;
- mesure : désignation avec **2 000 apprenants actifs en ≈ 410 ms**, pic mémoire 16 Mio ;
- navigateur : ouverture en lecture seule des pages Événement, Entreprises participantes, Emplacements et Apprenants. Les actions n'y ont **pas** été rejouées par Claude.

Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-14-evenements.md`

Première ligne : `# Revue du code T-14 — Événements, participants et emplacements`.

En-tête (date, branche, base) ; périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal, rare ou non) ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

# Prompt de contre-revue Codex — plan de T-14 (JD-19)

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-14.md` (« Plan à revoir » : 3 majeurs, 1 mineur, 1 suggestion, 2 questions). Claude a corrigé le plan : tu fais une **contre-revue limitée**, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-14-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles :** n'utilise **aucun** fichier réel, notamment dans le dossier Téléchargements.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-14.md` (**version 1.3**) et `docs/tasks/CURRENT_TASK.md`, sur `develop` (`1b05242`), non commités. Aucun code de T-14 n'existe.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 14 du plan. Les décisions **10 à 14** répondent à tes deux questions et ajoutent une demande du responsable technique : l'entreprise voit **uniquement l'événement courant**, sans sélecteur ; elle ne voit plus ses anciens passages ; le nom finit automatiquement par l'année scolaire ; un seul événement par année scolaire. Elles sont dans `docs/DECISIONS.md` et **priment sur le CDC** (écart à ENT-01, ENT-02, ENT-07, RG-ENT-08). Ne les remets pas en cause ; signale seulement une conséquence non traitée.

## 3. Ce qu'il faut vérifier

**A. Tes 5 constats** : corrigé / partiel / non corrigé.

| # | Correction annoncée | Section |
|---|---|---|
| 1 | Verrou exclusif avant toute lecture, lecture simple des identifiants, insertion par paquets, reprise de la transaction, essais avec `update`, `bulkAddCompetence`, `bulkDeactivate` | § 6, § 10 |
| 2 | **Remplacé par la décision 10** : événement courant seulement, `GET /me/company-event` sans paramètre, contexte transmis à la fiche, tests | § 5, § 7, § 9, § 10 |
| 3 | Transaction unique dans `CompanyService::create`, événement relu sous verrou, nettoyage du logo, tests d'échec et de concurrence | § 5, § 10 |
| 4 | `EventUsage` par événement, protocole T-30 avec relecture après verrou | § 4, § 6, § 14 |
| 5 | Inscription idempotente dans `DemoAccountsSeeder` | § 5, § 10 |

**B. Effets de bord des corrections**, en particulier :

- la photo de la lecture simple est-elle bien prise **après** le verrou exclusif sous `REPEATABLE READ`, y compris lors d'une reprise de transaction ?
- la vérification des clés étrangères à l'insertion dans `event_learners` (verrou partagé sur `learners`) peut-elle recréer un cycle avec un chemin existant ?
- le nouvel ordre `event_locks` → événement → classes → comptes → fiches est-il compatible avec `LearnerService`, `LearnerImportRunner` et `CompanyService` ?
- le verrou de l'événement dans `create` est-il compatible avec le nettoyage du logo et la limite connue des deux niveaux englobants ?
- `event_id` à la réinitialisation : aucune fuite entre entreprises, aucun mot de passe conservé ;
- année scolaire : calcul au 31 août et au 1er septembre, changement de date, unicité avec les archivés, concurrence, nom complet affiché partout, effet sur RG-EVT-04 et sur T-16 ;
- vue entreprise : événement futur non courant, statut courant retiré, ancien événement encore courant, relais vers T-23, T-30, T-33 et les exports.

**C. Signale aussi tout défaut évident** du plan, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`). Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-14-contre-revue.md`

Première ligne : `# Contre-revue du plan T-14 — Événements, participants et emplacements`.

En-tête ; périmètre ; tableau des 5 constats rejoués ; effets de bord ; nouveaux constats (Bloquant / Majeur / Mineur / Suggestion, section, scénario, correction) ; limites ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

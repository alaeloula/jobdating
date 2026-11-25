# Prompt de contre-revue Codex — T-14 (JD-19) : événements, participants et emplacements

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-14-evenements.md` (« À corriger avant fusion » : 2 majeurs, 3 mineurs, 1 suggestion). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire, dossiers temporaires hors dépôt ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-14-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles :** n'utilise **aucun** fichier réel, notamment dans le dossier Téléchargements. Données fictives uniquement. Ne touche pas à la base de développement `jobdating`.

## 1. Ce qui est à revoir

- Branche `feature/T-14-evenements`, base `develop` (`1b05242`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-23 — T-14 », paragraphe « Revue du code par Codex… ».
- **Périmètre** : les 6 constats et leurs effets de bord. Le reste de T-14 a déjà été revu.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm -T php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 14 du plan v1.4 et lignes du 23/11/2026 de `docs/DECISIONS.md`. Le bouton d'ajout s'appelle désormais « Ajouter des entreprises existantes » (accord du responsable technique). L'import des entreprises reste hors périmètre (**T-64, JD-44**).

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Anciennes lignes gardées seulement à événement identique ; état du panneau remis à zéro à tout changement d'événement ; événement dans la clé de `LocationEditor` ; réponse d'un formulaire démonté ignorée | `src/events/api.ts`, `ParticipantsPanel.tsx`, `LocationEditor.tsx`, `LocationsPage.tsx` ; tests « aucune ligne de l'ancien événement n'est modifiable », « le brouillon est effacé » |
| 2 | Suppression d'un archivé refusée sous verrou (`event_archived`) ; bouton retiré | `EventService::delete`, `EventsPage.tsx` ; `test_archivage_reversible_et_lecture_seule`, test « ne propose pas la suppression » |
| 3 | Paire latitude/longitude contrôlée sous verrou sur la valeur obtenue après le PATCH | `EventService::assertCoordinates` ; `test_modification_partielle_garde_les_coordonnees_ensemble` |
| 4 | État « avant » capturé avant toute modification de l'année | `EventService::update` ; `test_journal_du_nom_complet_au_changement_d_annee` |
| 5 | Mise à jour du panneau après succès soumise à la génération | `ParticipantsPanel.tsx` ; test « succès tardif de l'ancien événement » |
| 6 | Blocage réel de l'enfant constaté par `SHOW FULL PROCESSLIST` depuis une troisième connexion, attentes bornées, `posix` déclaré ; matrice : statut exact par route et par rôle, état annulé entre les rôles | `EventConcurrencyTest.php`, `EventTest::test_matrice_des_droits` |

**B. Effets de bord**, en particulier :

- filtre changé dans un même événement : anciennes lignes affichées, actions sûres ?
- événement courant changé par une actualisation sans action de l'utilisateur ; retour A → B → A ; réponses tardives et état `busy` ;
- `useEffect` qui incrémente la génération : fenêtre entre le rendu et l'effet ;
- enregistrement d'un emplacement pendant le changement d'événement, erreur tardive ;
- `assertCoordinates` : valeurs `0`, chaînes, effacement des deux, événement sans coordonnées ;
- détection du blocage : peut-elle confondre une requête simplement lente avec une attente de verrou, ou voir une autre connexion de `jobdating_test` ?
- `DB::beginTransaction` / `rollBack` dans la matrice avec `RefreshDatabase` : les tests prouvent-ils bien chaque statut ?

**C. Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

**Ne relance pas la suite complète** : cite ces résultats comme **communiqués par Claude, non rejoués**. Tu peux relancer `tests/Feature/Events` et `src/events/events.test.tsx`.

Résultats de Claude (23/11/2026) :
- Pint et PHPStan sans erreur ; suite backend complète dans Docker : **517 tests réussis, 2696 assertions** (durée indiquée 751,68 s, code de sortie 0), dont 41 méthodes dans `EventTest` et 10 tests de concurrence ;
- frontend : lint, **114 tests** et build réussis ;
- les actions n'ont **pas** été rejouées par Claude dans un vrai navigateur.

Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-14-contre-revue.md`

Première ligne : `# Contre-revue du code T-14 — Événements, participants et emplacements`.

En-tête (date, branche, base) ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal, rare ou non) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

# Prompt de revue Codex — code de T-64 (JD-44) : import des entreprises par fichier

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du code** de T-64. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire ou dossiers temporaires hors dépôt). Seul livrable : `docs/reviews/REVIEW-T-64-import-entreprises.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN fichier réel**, en particulier aucun classeur du dossier Téléchargements. Classeurs fictifs uniquement (`tests/Support/FakeWorkbook.php` ou `C:\Users\Youcode\Desktop\jobdating\fichiers-de-test\import-entreprises-fictif.xlsx`). Ne touche pas à la base de développement `jobdating`.

## 1. Ce qui est à revoir

- Branche `feature/T-64-import-entreprises`, base `develop` (`c3f0365`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Plan `docs/tasks/PLAN-T-64.md` (**version 1.3**) avec ses deux revues ; suivi `docs/PROGRESS.md`, entrée « 2026-11-25 — T-16 abandonnée ; T-64 ».
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm -T php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite. Les tests de concurrence demandent `pcntl`, `posix` et la lecture de `performance_schema` (droit accordé localement, `docker/mysql/init/02-lecture-des-verrous.sql`).

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 10 du plan et lignes du 25/11/2026 de `docs/DECISIONS.md` (classeur tel quel, lignes « Oui », nom seul, existante signalée seulement, nom simplifié, première occurrence créée, fiches comme l'import des apprenants, seconde feuille ignorée, événement supprimé et entreprises désactivées ou retirées). Le développement a démarré sans nouvelle contre-revue du plan : **vérifie que les corrections de la contre-revue du plan sont bien implémentées** (empreinte SHA-256 de 64 caractères, ordre des verrous création/renommage/réinitialisation/marquage, variantes XML).

## 3. Points à vérifier en priorité

- **Frontière de décodage** du lecteur (`CompanyWorkbookReader`) : aucune valeur hors des deux colonnes utiles et de la ligne 1 ; parcours XML (`read`/`next`) sans cellule, ligne ou chaîne sautée ; texte riche, chaînes en ligne, cellules sans `r`, lignes sans `r`, cellules fusionnées, formules, nombres ; relations et chemins ; XML mal formé ; limites ; sentinelles de `CompanyImportTest::test_sentinelles_jamais_decodees`.
- **Noms** : `CompanyNameKey`, migration de `name_key` des entreprises existantes, création, renommage (`CompanyService::update`), noms longs, clé vide, réservation des noms d'utilisateur (`UsernameSuggester::suggest`).
- **Verrous** : `CompanyNameLock` pris en premier ; ordre noms → événement → compte → entreprise à la création et dans `CompanyImportRunner` ; renommage noms → entreprise → compte ; réinitialisation et `CompanyImportCredentials::markPrinted` entreprise → compte ; tests `CompanyImportConcurrencyTest` ; durée du verrou mesurée (≈ 22 s pour 1 000 lignes).
- **Traitement** : prise en charge, reprises, aucun état partiel, idempotence, mise en file après validation, nettoyage (`CleanupImports`).
- **Fiches** : validité par hachage d'origine, exclusions, avertissements, emplacement, marquage, suppression, `no-store`, aucune fuite du hachage.
- **Liste blanche** : aucune autre valeur du classeur dans la base, le `payload`, le journal, les exceptions ou les réponses.
- **Droits** : chaque route pour les 5 rôles.
- **Frontend** (`CompanyImportPage`, `ImportSheets`, `importApi`, bouton dans `CompaniesPage`, impression `index.css`) : enchaînements « erreur → correction → nouvel essai », réponses tardives, double clic, même clé après erreur réseau, 409, fiches jamais en cache et effacées en changeant d'import.
- **Régressions** : `CompanyService::create/update` (T-11), trait `ForksDatabaseConnections` extrait de `EventConcurrencyTest` (T-14).
- **Qualité des tests** : échoueraient-ils sur un code faux ?
- Signale aussi tout autre défaut évident, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Contrôles

**Ne relance pas la suite complète** : cite ces résultats comme **communiqués par Claude, non rejoués**. Tu peux relancer `tests/Feature/Companies/CompanyImportTest.php`, `tests/Feature/Companies/CompanyImportConcurrencyTest.php` et `src/companies/companyImport.test.tsx`.

Résultats de Claude (25/11/2026) : voir l'entrée de `docs/PROGRESS.md` (Pint, PHPStan, suite backend, tests de l'import et de concurrence, frontend, mesure à 1 000 lignes). Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-64-import-entreprises.md`

Première ligne : `# Revue du code T-64 — Import des entreprises par fichier`.

En-tête (date, branche, base) ; périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal, rare ou non) ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

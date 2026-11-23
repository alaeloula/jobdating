# Prompt de contre-revue Codex — T-13 (JD-18) : import des apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-13-import.md` (« À corriger avant fusion », 4 constats majeurs, 5 mineurs). Claude a traité ce rapport : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs en mémoire, dossiers temporaires hors dépôt ou tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-13-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles :** n'utilise **aucun** fichier réel d'apprenants (en particulier aucun export YouCode School Admin du dossier Téléchargements). Données fictives uniquement (CNIE `ZZ…`, e-mails `@example.test`).

## 1. Ce qui est à revoir

- Branche `feature/T-13-import`, base `develop` (`81f9d02`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-20 — T-13 », paragraphe « Revue du code par Codex… ».
- **Périmètre** : les 9 constats, leurs effets de bord, et le **changement de règle sur la classe (plan v2.2)** décrit en partie 3.B. Le reste de T-13 a déjà été revu.
- **Tests dans Docker (PHP 8.4)** : `docker compose run --rm -T php php artisan test …`. MySQL sur `jobdating_test`, jamais SQLite.

## 2. Décisions du responsable technique (20/11), à ne pas remettre en cause

- Les 9 constats sont corrigés, y compris le constat 6 (non reporté à T-49).
- **Nouvelle décision prise pendant ta contre-revue (plan v2.2, `DECISIONS.md` mise à jour)** : l'export réel « Étudiants » filtré par campus et promotion contient plusieurs classes ; **la classe de chaque apprenant vient de la colonne `Classe` de sa ligne** (classes actives, sans casse ni espaces) ; seuls le campus et la promotion sont choisis ; classe inconnue, désactivée, vide ou ambiguë → ligne rejetée ; pas d'ajout de classe depuis l'import. Elle remplace la classe choisie et imposée : ne la remets pas en cause, vérifie son implémentation.
- Autres décisions du plan et de `DECISIONS.md` inchangées : comptes inactifs ; mots de passe chiffrés au repos et fichier Excel en clair au téléchargement ; plusieurs téléchargements ; entreprises et inscription à l'événement reportées.
- La limite connue sur la classe écrite avec un autre alphabet n'est pas rouverte.

## 3. Ce qu'il faut vérifier

**A. Rejouer tes constats** avec les mêmes scénarios (tes bancs de la revue) : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers et tests |
|---|---|---|
| 1 | Doublons CNIE et e-mail cherchés sur toutes les lignes ayant une clé admissible (CNIE valide ; e-mail en minuscules non vide), même déjà rejetées ; toutes les occurrences rejetées, premier motif conservé (`??=`) | `LearnerImportPlanner::rejectDuplicates` ; `LearnerImportTest::test_doublons_rejetes_meme_avec_une_autre_erreur` |
| 2 | Comptage réel des `<si>` de `xl/sharedStrings.xml` (XMLReader sur `zip://`, arrêt au-delà de 50 000) | `YouCodeFileReader::countSharedStrings` ; `test_xlsx_trop_de_chaines_partagees_sans_compteur_refuse` |
| 3 | `setPending(false)` dans `change()`, `restart()` et à l'ouverture d'un rapport | `src/pages/LearnerImportPage.tsx` ; test « changer de classe pendant l'aperçu libère le bouton » |
| 4 | « Voir le rapport » dans l'historique (`fetchImport`, suivi repris si en attente ou en cours) ; lignes rejetées dans le rapport ; historique paginé (`?page=`, `meta`) | `LearnerImportPage.tsx`, `src/learners/importApi.ts` ; test « historique : rapport … et pagination » |
| 5 | Séparateur choisi sur les deux premières lignes (le plus fréquent) | `YouCodeFileReader::delimiter` ; `test_point_virgule_avec_titre_et_numeros_apres_lignes_vides` |
| 6 | `markFailed(…, $constraint)` : la même condition d'ancienneté est reprise dans la mise à jour atomique | `LearnerImportRunner::markFailed`, `CleanupImports` ; `test_nettoyage_ne_coupe_pas_un_import_qui_vient_de_demarrer` (prise en charge simulée entre sélection et mise à jour, même connexion) |
| 7 | Lignes inchangées dans le `payload`, verrouillées et revalidées avec les mises à jour, sans écriture | `LearnerImportService`, `LearnerImportRunner` ; `test_ligne_inchangee_revalidee_au_traitement` |
| 8 | État `pollError` séparé, effacé à la première réponse valide | `LearnerImportPage.tsx` ; test « erreur passagère du suivi » |
| 9 | `SHOULD_PRESERVE_EMPTY_ROWS: true` ; numéros réels | `YouCodeFileReader` ; même test que le constat 5 |

**B. Classe lue sur chaque ligne (v2.2)** — `LearnerImportPlanner` (`activeClasses`, `lineReason`, comptes par classe, empreinte), `LearnerImportService`, `LearnerImportRunner` (classes verrouillées), migration (`class_counts` à la place de `class_id`), `LearnerImportController`, `LearnerImportResource`, `LearnerImportPage.tsx`, `importApi.ts` ; tests `test_classe_lue_sur_chaque_ligne_plusieurs_classes`, `test_classe_ambigue_rejetee`, `test_apprenant_connu_change_de_classe_selon_le_fichier`, `test_classe_desactivee_apres_l_apercu` :
- correspondance (casse, espaces, ambiguïté) ; classe renommée ou désactivée entre l'aperçu, la confirmation et le traitement ; classe d'un apprenant connu modifiée par le fichier (filière qui suit) ;
- ordre des verrous classes → comptes → fiches avec plusieurs classes ; reprise après déplacement de classe ;
- empreinte : un changement de classe retenue à comptes constants donne-t-il bien un 409 ?
- aucune donnée du fichier hors liste blanche dans `class_counts`, le journal ou les réponses.

**C. Effets de bord** :
- lignes vides conservées : fin de fichier avec lignes vides, limite de 1 000 lignes (les lignes vides comptent-elles ?), en-têtes en ligne 1 ou 2, CSV et XLSX ;
- détection du séparateur : fichier d'une seule ligne, valeurs contenant des virgules entre guillemets ;
- lignes inchangées revalidées : ordre des verrous (classes → comptes → fiches) conservé, comptes du rapport inchangés, empreinte et 409 inchangés ;
- frontend : réponse tardive d'un rapport ouvert puis d'un autre, ou après « Recommencer » ; boutons de mots de passe dans l'historique et dans le rapport ; pagination après suppression des mots de passe (invalidation de la requête) ; **séquences d'erreur** (rapport introuvable, page d'historique en erreur) ;
- qualité des nouveaux tests : échoueraient-ils sur l'ancien code ?

**D. Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

## 4. Contrôles

**Ne relance pas la suite complète** : Claude vient de l'exécuter. Cite ses résultats comme **communiqués par Claude, non rejoués**. Tu peux relancer `tests/Feature/Learners/LearnerImportTest.php` et `src/learners/import.test.tsx`.

Résultats de Claude (20/11/2026) :
- Pint et PHPStan sans erreur ; suite backend complète dans Docker, après le changement v2.2 : **442 tests réussis, 2278 assertions** (≈ 6 min, code de sortie 0), dont 25 dans `LearnerImportTest`
- frontend : lint, **88 tests** (dont 8 pour l'import) et build réussis.

Mesures déjà faites par toi (1 000 comptes ≈ 302 s, relivraison par le vrai worker, trois scénarios à deux connexions) : reprises dans `PROGRESS.md`, inutile de les refaire. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-13-contre-revue.md`

Première ligne : `# Contre-revue du code T-13 — Import des apprenants`.

En-tête (date, branche, base) ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, scénario, correctif minimal ; indique si un constat est rare et sans impact sur les tâches suivantes) ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

# Prompt de vérification Codex — T-20 (JD-22) : verrou des fichiers et contrôle de structure

À copier tel quel dans Codex, dans une **nouvelle conversation**, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es le **relecteur technique** du projet JobDating. Tu fais une **vérification limitée** de dernières corrections sur la tâche T-20 (téléversement du CV des apprenants par l'administrateur et l'équipe CME). **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-T-20-verification.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre aucun vrai CV ni aucun fichier du dossier Téléchargements.** Utilise seulement les PDF fictifs générés par les tests (`tests/Support/FakePdf.php`).

**Base de test partagée :** avant de lancer un test backend, vérifie avec `docker ps` qu'aucune autre commande `php artisan test` ne tourne. N'en lance jamais deux à la fois.

## 1. Périmètre

- Branche `feature/T-20-cv`, base `develop` (`2f8a9d8`), non commitée. Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Références : `docs/tasks/PLAN-T-20.md` **v1.3** (décision 9 révisée), `docs/PROGRESS.md` (fin de l'entrée T-20).
- Ne relis pas les anciens rapports de revue de T-20 : ce prompt contient tout ce qu'il faut.

## 2. Ce qu'il faut vérifier

**A. Verrou des fichiers pendant l'envoi** (`app/Services/Cv/CvStorage.php`, `app/Services/Cv/CvService.php`)

Un envoi prend un verrou MySQL nommé sur son fichier (`get_lock`) avant de l'écrire, et le garde jusqu'à la fin ; le nettoyage des fichiers orphelins ignore un fichier dont le verrou est pris.

1. **Reconnexion** : si la connexion MySQL est rouverte entre l'écriture du fichier et l'enregistrement en base, le verrou est perdu. Le service doit alors abandonner l'envoi (code `cv_not_saved`), sans ligne en base ni fichier restant. Test : `CvConcurrencyTest::test_reconnexion_avant_l_adoption_abandonne`.
2. **Erreur pendant la préparation** : si le chiffrement ou l'écriture échoue après la prise du verrou, le fichier partiel est effacé et le verrou libéré. Test : `CvUploadTest::test_erreur_de_preparation_libere_le_verrou`.
3. Vérifie que le verrou est libéré dans tous les cas de fin d'envoi (succès, fichier identique, refus, erreur) et que le nettoyage ne supprime jamais un fichier référencé ou dont le verrou est pris.

**B. Contrôle de structure du PDF** (`app/Services/Cv/PdfInspector.php`)

Décision 9 révisée le 27/11 par le responsable technique (**à ne pas remettre en cause**) : le contrôle vérifie seulement la **structure** du fichier (signature, fin de fichier, table des références, objets, page). L'examen du contenu du PDF a été retiré ; la consultation sécurisée du document relève de la tâche suivante (T-21).

1. Vérifie qu'il ne reste ni code mort ni test incohérent de l'ancien examen du contenu (`tests/Feature/Cv/CvUploadTest.php`, `tests/Support/FakePdf.php`).
2. Vérifie que des **PDF valides courants sont acceptés** : objets compressés (`/ObjStm`), table classique, fichier linéarisé, longueur de flux indirecte, et — avec des PDF fictifs que tu construis toi-même — une longueur de flux rangée dans un flux d'objets compressé, ainsi qu'un PDF avec une mise à jour incrémentale (`/Prev`).
3. Vérifie que les fichiers abîmés restent refusés : vide, image renommée, texte renommé, PDF coupé, sans page, sans `startxref`, sans table des références, plus de 5 Mo (`test_motifs_de_structure`, `test_fichier_refuse_sans_rien_ecrire`).

**C.** Signale aussi tout défaut évident dans ces fichiers, même hors de cette liste. Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (report possible dans `docs/ERREURS_A_CORRIGER.md`).

## 3. Contrôles

Tu peux relancer, **seul sur la base de test**, `tests/Feature/Cv`. Résultats de Claude à citer comme **communiqués, non rejoués** : Pint et PHPStan sans erreur ; tests du CV : 42 réussis, 1 sauté ; suite backend complète : 597 réussis, 1 sauté, 3135 assertions ; frontend lint, 129 tests et build réussis. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport

Première ligne : `# Vérification T-20 — Verrou des fichiers et contrôle de structure`. Puis : en-tête (date, branche), tableau des points A et B (vérifié / partiel / non conforme), nouveaux constats éventuels (gravité, fichier et ligne, scénario, correctif minimal, rare ou non), validation et limites, **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

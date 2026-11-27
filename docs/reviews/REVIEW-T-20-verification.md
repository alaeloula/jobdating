# Vérification T-20 — Verrou des fichiers et contrôle de structure

Date : 27/11/2026 (Africa/Casablanca). Branche : `feature/T-20-cv`. Base : `develop`, `2f8a9d802704810240c6e937718dae258d353842`. Modifications non commitées.

Vérification limitée au prompt `PROMPT-CODEX-VERIFICATION-T-20.md`, au plan T-20 v1.3 et à la fin de l'entrée T-20 de `PROGRESS.md`, avec application d'`AGENTS.md` et de `CLAUDE.md`. Les nouveaux fichiers PHP, non suivis par Git, ont été lus directement ; le raccordement du nettoyage a été comparé à la base. Les anciens rapports T-20 n'ont pas été ouverts.

La décision 9 révisée est appliquée : seul le contrôle de structure est évalué. L'acceptation d'un PDF avec JavaScript est donc attendue ; la protection de sa consultation reste à réaliser en T-21.

## Points A et B

| Point | État | Résultat |
|---|---|---|
| A.1 — Reconnexion avant adoption | Vérifié | `ownsLock()` compare le détenteur du verrou à la connexion courante, avant l'écriture de la référence. Le test de reconnexion passe : `cv_not_saved`, aucune ligne de CV et aucun fichier restant. |
| A.2 — Erreur de préparation | Partiel | Le test d'erreur de chiffrement passe. Le `catch` supprime le fichier partiel et son `finally` libère le verrou pour les erreurs de chiffrement et d'écriture. L'initialisation du disque reste toutefois hors de ce bloc : V1. |
| A.3 — Fin d'envoi et nettoyage | Partiel | Le `finally` du service libère le verrou après succès, fichier identique, refus ou erreur survenus après le retour de `store()`. Le nettoyage acquiert le verrou sans attendre et relit l'absence de référence avant suppression. Les tests de préparation active, de fichier référencé et de remplacement passent. Réserve sur l'échec précoce de `store()` décrit en V1. |
| B.1 — Retrait de l'ancien examen du contenu | Vérifié | Aucun ancien analyseur de contenu actif ni test exigeant son refus dans les fichiers examinés. Les tests acceptant du JavaScript documentent explicitement la décision révisée. Les constructions de flux de `FakePdf` servent aux variantes de structure. |
| B.2 — PDF valides courants | Vérifié | Table classique, objets compressés et longueur indirecte acceptés dans la suite CV. Trois variantes supplémentaires sont également acceptées par l'API : longueur stockée dans un objet compressé, mise à jour incrémentale et véritable PDF linéarisé. Réserve sur le fixture existant : V2. |
| B.3 — Fichiers abîmés ou trop volumineux | Vérifié | Les tests de motifs et de refus passent : vide, PNG renommé, faux PDF, fichier tronqué, absence de page, de `startxref` ou de table des références, taille supérieure à 5 Mo. Le texte renommé est refusé par le contrôle de signature dans `test_motifs_de_structure`. Les refus d'envoi testés ne laissent ni fichier ni ligne de CV/historique. |

## Nouveaux constats

### V1 — Mineur, bug confirmé : verrou conservé si l'initialisation du disque échoue

**Localisation :** `backend/app/Services/Cv/CvStorage.php:41`.

`Storage::disk(self::DISK)` est appelé après `get_lock`, mais avant le `try` de préparation. Flysystem peut lever une exception dès la construction du disque local, lors de la création de son répertoire racine. Le service ne reçoit alors aucun chemin et ne peut pas libérer ce verrou.

**Scénario reproduit localement**, sans simulation d'exception :

1. Dans une application démarrée pour le contrôle, faire pointer temporairement la configuration en mémoire du disque `local` vers un fichier ordinaire créé dans `%TEMP%`, puis appeler `Storage::forgetDisk('local')`.
2. Fixer l'UUID à `00000000-0000-4000-8000-000000000020` et appeler `CvStorage::store(FakePdf::classic())`.
3. Flysystem lève `Unable to create a directory .../root-is-a-file`.
4. Sur la même connexion MySQL, `is_free_lock('cv-file:00000000-0000-4000-8000-000000000020')` renvoie **0** après l'exception.

Le verrou a été libéré explicitement à la fin de cette reproduction ; le fichier temporaire a été supprimé. Aucune configuration du dépôt n'a été modifiée.

**Impact :** verrou nommé inutilement conservé jusqu'à la fermeture de la connexion. Aucun PDF n'est écrit et aucune référence n'est créée. Dans une requête PHP ordinaire, la fermeture de session borne l'effet ; un processus durable, comme un futur worker d'import, peut le conserver plus longtemps.

**Correctif minimal :** résoudre le disque avant de prendre le verrou, puis conserver le bloc de nettoyage actuel pour les erreurs après acquisition. Ajouter un test d'échec d'initialisation du disque : le test actuel couvre le chiffrement, pas cette étape.

**Fréquence et suites :** rare, lié à un stockage indisponible ou mal configuré. Aucun blocage fonctionnel de T-21 ; effet potentiel sur les ressources d'un worker T-22. Report possible dans `docs/ERREURS_A_CORRIGER.md` si explicitement accepté ; aucun report n'a été écrit pendant cet audit.

### V2 — Mineur, défaut de fixture confirmé : le PDF « linéarisé » n'a pas une linéarisation valide

**Localisation :** `backend/tests/Support/FakePdf.php:36` ; utilisé par le jeu `linéarisé` de `backend/tests/Feature/Cv/CvUploadTest.php:224`.

**Scénario reproduit :** générer `FakePdf::linearized()`, puis l'ouvrir avec pikepdf/libqpdf. Le lecteur reconnaît le dictionnaire `/Linearized`, mais `check_linearization()` renvoie **False**, avec le diagnostic `hint table is not a stream`. Les valeurs `/H [0 0]`, `/E 0` et `/T 0` ne constituent pas les structures de linéarisation annoncées. Corriger uniquement `/L` ne suffit pas.

**Impact :** le test nommé « linéarisé » passe sans prouver la compatibilité avec un véritable fichier linéarisé. Cela ne démontre pas de défaut du code applicatif : un véritable PDF linéarisé, construit séparément pour cet audit, passe le contrôle qpdf et l'envoi API.

**Correctif minimal :** remplacer ce fixture par un PDF fictif correctement linéarisé, avec ses tables et décalages réels, et conserver son utilisation dans le jeu d'acceptation. Un fixture validé peut être conservé sans ajouter de dépendance applicative.

**Fréquence et suites :** défaut systématique du fixture, sans impact de production observé ni blocage de T-21/T-22. Report possible dans `docs/ERREURS_A_CORRIGER.md` ; aucun report n'a été écrit pendant cet audit.

## Validation exécutée localement

Les exécutions backend ont été séquentielles. `docker ps` a été vérifié avant chaque lancement ; aucune autre suite backend n'était visible. Le contrôle initial des processus PHP Windows n'a montré qu'un `artisan tinker`, sans commande de test concurrente.

| Contrôle exécuté | Résultat |
|---|---|
| `docker compose run --rm php php artisan test tests/Feature/Cv` | **42 réussis, 1 sauté, 213 assertions**, code 0, durée annoncée 284,66 s. Les deux tests nommés dans A.1 et A.2 passent, ainsi que le nettoyage face à une préparation active sur une autre connexion. |
| Test PHPUnit temporaire supplémentaire, après la suite CV | **1 réussi, 6 assertions**, code 0, 43,011 s. Il réutilise `CvUploadTest::test_pdf_valide_accepte()` pour chacun des trois PDF supplémentaires : inspection sans motif de refus, puis réponse HTTP 201. |
| Vérification indépendante des PDF supplémentaires | pikepdf **10.18.3**, libqpdf **12.4.2** : ouverture sans récupération, une page, aucun avertissement de structure pour les trois PDF. `check_linearization()` vaut **True** pour le véritable PDF linéarisé. |
| Reproduction V1, PHP Windows 8.5.12 / MySQL local | Exception réelle d'initialisation du disque et verrou encore détenu, puis libération explicite. Aucune table modifiée par cette reproduction. |
| `git diff --check` | Code 0, aucune erreur d'espacement ; avertissements de conversion LF/CRLF sur certains fichiers existants. |

Les tests API tournent dans Docker, sous PHP **8.4.28**. Les outils, scripts et PDF supplémentaires restent hors du dépôt, dans `%TEMP%/jobdating-t20-verification` ; pikepdf a été installé uniquement dans `%TEMP%/jobdating-t20-verification-python`. Aucune dépendance du projet n'a été ajoutée.

Construction des variantes supplémentaires :

- **Longueur compressée :** flux de contenu 4 avec `/Length 7 0 R`, entier 7 dans un `/ObjStm` compressé ; entrée de références de type 2 pour cet entier. qpdf retrouve la longueur 11 et décompresse le contenu fictif `q Q`.
- **Mise à jour incrémentale :** départ de `FakePdf::classic()`, nouvelle version de l'objet de contenu 4, nouvelle section `xref` et trailer `/Prev` vers la table d'origine. qpdf lit le dernier contenu `q Q`.
- **Linéarisation valide :** départ de `FakePdf::classic()`, sauvegarde temporaire avec `linearize=True`, puis vérification indépendante de la linéarisation avant l'envoi.

Le test temporaire supplémentaire peut être rejoué tant que ces fichiers temporaires existent, depuis la racine du dépôt, après vérification de l'absence d'autre suite :

```powershell
$reviewTemp = Join-Path $env:TEMP 'jobdating-t20-verification'
docker compose run --rm -T --volume "${reviewTemp}:/review:ro" php php vendor/bin/phpunit --filter test_variantes_supplementaires_fictives /review/T20VerificationExtraTest.php
```

## Résultats communiqués, non rejoués, et limites

Résultats de Claude fournis par le prompt et confirmés dans `PROGRESS.md` : **Pint et PHPStan sans erreur ; suite backend complète : 597 réussis, 1 sauté, 3135 assertions ; frontend lint, 129 tests et build réussis**. Ces contrôles n'ont pas été rejoués pendant cette vérification. Son résultat communiqué de **42 tests CV réussis et 1 sauté** est corroboré par l'exécution locale ci-dessus.

Le test des exports fictifs Word, LibreOffice, Canva et navigateur reste sauté : aucun export dans `tests/Fixtures/cv`. Les PDF construits ici apportent une preuve pour les variantes demandées, sans remplacer ce corpus d'exports.

La couverture des fins d'envoi repose sur les tests existants et la lecture des `finally` ; aucune coupure réseau après validation de transaction ni panne réelle de disque pendant une écriture partielle n'a été provoquée. V1 est reproduit ; l'accumulation de verrous dans un futur worker reste un impact potentiel, pas un incident observé.

Aucun vrai CV ni fichier du dossier Téléchargements n'a été ouvert. Aucun fichier de code, configuration ou dépendance du dépôt n'a été modifié ; aucun commit ni push. Seul livrable ajouté : ce rapport.

## Verdict

**À fusionner après corrections mineures.**

Les corrections principales de reconnexion et de préparation sont validées par les tests locaux. Les variantes PDF demandées sont acceptées, y compris celles construites pour cette vérification. Corriger le chemin d'initialisation du disque V1 et le fixture V2 ; aucun défaut majeur confirmé dans le périmètre examiné.

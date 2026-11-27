# Prompt de revue Codex — plan de T-20 (JD-22) : téléversement, remplacement et retrait du CV

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du plan** de T-20, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-20.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN vrai CV ni aucun fichier du dossier Téléchargements.** PDF fictifs uniquement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-20.md` (version 1.0) et `docs/tasks/CURRENT_TASK.md`, sur la branche `feature/T-20-cv` (base `develop`, `2f8a9d8`), documents non commités.
- Code existant de référence : `app/Http/Controllers/LearnerController.php`, `app/Services/Learners/*`, `app/Http/Resources/LearnerResource.php`, `routes/api.php` (groupe apprenants), `app/Enums/Permission.php`, `Role.php`, `AuditAction.php`, `AuditCategory.php`, `app/Services/Companies/CompanyService.php` (logo), `app/Console/Commands/CleanupImports.php`, `tests/Concerns/ForksDatabaseConnections.php`, `src/pages/LearnersPage.tsx`, `docker/php/Dockerfile`.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Références

1. CDC V2 : CV-01, CV-04, section 7 (7.1, 7.2, 7.5, 7.6), 8.7, 11.6, ADM-03 (étape 6, RG-APR-03, RG-APR-05), matrice 3.2, TRA-01.
2. `docs/DECISIONS.md` : **26/11 « Retrait d'un CV (T-20) »** (retrait, 5 Mo) ; elle prime sur le CDC.
3. **Décisions 1 à 4 du plan : ne les remets pas en cause.** Les décisions 5 à 8 sont des **propositions** : donne ton avis.
4. Maquettes dans `C:\Users\Youcode\Desktop\jobdating\maquettes\` : `cme-01-cv-apprenants.png`, `admin-06-apprenants.png`.
5. `docs/PROGRESS.md` et `docs/ERREURS_A_CORRIGER.md` (ne pas re-signaler ses entrées).

## 3. Points à vérifier en priorité

- **Couverture** de CV-01, CV-04, RG-CV-05, RG-CV-06, du critère ADM-03 et des tests 11.6 (partie téléversement).
- **Contrôle du fichier** : type réel, signature, taille, limites PHP (`upload_max_filesize`, `post_max_size`, réponse 413), fichiers piégés (PDF avec JavaScript, PDF polyglotte, fichier énorme annoncé petit).
- **Stockage** : chiffrement (`Crypt::encryptString` sur 5 Mo : mémoire, taille), nommage, disque privé, suppression définitive, orphelins, rotation de clé.
- **Concurrence** : deux envois simultanés, envoi et retrait simultanés, suppression ou restauration de l'apprenant pendant l'envoi ; ordre des verrous par rapport à T-12, T-13 et T-14 ; fichier écrit hors transaction.
- **Droits** : chaque route pour les 5 rôles ; Direction (état visible, aucune action) ; entreprise et apprenant n'accèdent jamais à ces routes ; aucune fuite du chemin, de l'empreinte ou du nom d'origine.
- **Historique et audit** : contenu, ajout seul, auteur supprimé, cohérence avec T-22 (décision 8).
- **Frontend** : enchaînements « erreur → correction → nouvel essai », double clic, réponses tardives, changement de filtre pendant un envoi, retrait confirmé, compteur « Sans CV ».
- **Tests prévus** : cas importants manquants.
- **Découpage et risques** : la tâche tient-elle avant l'échéance du 25/12 ?
- Signale aussi tout autre défaut évident du plan, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-20.md`

Première ligne : `# Revue du plan T-20 — Téléversement, remplacement et retrait du CV`.

En-tête ; références et périmètre ; avis sur les décisions 5 à 8 ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, section du plan, problème, scénario ou référence, correction proposée, rare ou non) ; points vérifiés sans remarque ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

# Prompt de revue Codex — plan de T-14 (JD-19) : événements, participants et emplacements

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du plan** de T-14, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-14.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-14.md` (version 1.1) et `docs/tasks/CURRENT_TASK.md`, sur `develop` (`1b05242`), modifications de documents non commitées. La branche `feature/T-14-evenements` sera créée avant le code.
- Aucun code de T-14 n'est écrit. Code existant de référence : `app/Services/Learners/LearnerService.php`, `app/Services/Imports/LearnerImportRunner.php`, `app/Services/Companies/CompanyService.php`, `app/Http/Controllers/CompanyController.php`, `app/Contracts/ClassUsage.php`, `app/Services/AuditLogger.php`, `app/Enums/*`, `routes/api.php` ; frontend `src/AppRoutes.tsx`, `src/layout/AppLayout.tsx`, `src/pages/CompaniesPage.tsx`, `src/pages/LearnersPage.tsx`, `src/companies/*`.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Données personnelles :** n'utilise **aucun** fichier réel, notamment dans le dossier Téléchargements. Données fictives uniquement.

## 2. Références

1. CDC V2 : **ADM-05** (RG-EVT-01 à 04), **ADM-07** (RG-PAR-01 à 07), ADM-02 (RG-ENTR-03), ADM-04 (RG-IMP-06 et 07), ENT-01, APP-00, APP-01, matrice 3.2, modèle 8.2, section 8.8, TRA-01.
2. `docs/DECISIONS.md`, en particulier la ligne du **23/11/2026 « Événements et participants (T-14) »**. Elle prime sur le CDC.
3. **Décisions 1 à 9 du plan** : **ne les remets pas en cause** ; signale seulement une contradiction avec le CDC ou une décision écrite, ou une conséquence que le plan n'a pas traitée.
4. Maquettes dans `C:\Users\Youcode\Desktop\jobdating\maquettes\` : `admin-02`, `admin-03`, `admin-04`, `admin-05`, `cme-02`, `entreprise-ordi-01`, `apprenant-01`.
5. `docs/PROGRESS.md` : passages de relais de T-11, T-12 et T-13 vers T-14 ; `docs/ERREURS_A_CORRIGER.md` (E-01 à E-06 : ne pas les signaler).

## 3. Points à vérifier en priorité

- **Couverture** d'ADM-05 et ADM-07 : chaque étape, règle et critère d'acceptation est-il traité ou explicitement reporté ? Notamment « un apprenant qui active son compte le jour J apparaît immédiatement », et le retrait d'une entreprise qui disparaît de l'événement et de ses statistiques.
- **Événement courant** : unicité par colonne générée et index unique sur MySQL 8, désignation, retrait du statut, événement terminé ou archivé, suppression.
- **Dates** : fuseau `Africa/Casablanca`, RG-EVT-04 en création et en modification, statut autour de minuit.
- **Inscription automatique** : tous les chemins qui rendent un apprenant actif sont-ils couverts (création, import, réactivation unitaire, restauration, activation de compte) ? Cas d'un apprenant inchangé à l'import, supprimé, désactivé, retiré ; `removed_at` jamais effacé par erreur.
- **Concurrence** : verrou `event_locks` partagé ou exclusif ; ordre `event_locks` → classes → comptes → fiches face aux chemins **existants** de `LearnerService` et de `LearnerImportRunner` (y compris les reprises et `bulkDeactivate`) ; risques d'interblocage, durée du verrou pendant un import, alternative plus simple si elle existe.
- **Point d'extension `EventUsage`** : contrat suffisant pour T-30 ? Protection contre la création d'un passage pendant un retrait.
- **Création d'entreprise** : événement obligatoire dans la transaction, atomicité avec le logo (T-11), effets sur les tests et sur le compte démo ; entreprises existantes sans participation.
- **Droits et fuites** : chaque route pour les 5 rôles ; CME limitée aux emplacements ; `me/company-event` et `me/learner-event` strictement limités à leur propriétaire ; aucune CNIE, aucun identifiant de connexion, aucun contact ni donnée d'évaluation exposés à un apprenant.
- **Journal** : actions, absence de données personnelles inutiles, une seule ligne pour l'inscription en masse.
- **Frontend** : écrans par rôle, conformité aux maquettes, états de chargement et d'erreur, **enchaînements « erreur → correction → nouvel essai »**, réponses tardives, double clic, cas sans événement.
- **Découpage** : la tâche est large (décision 4). Le plan est-il réalisable tel quel, et y a-t-il un risque de déborder sur T-15, T-23, T-33 ou T-40 ?
- **Tests prévus** : cas manquants importants.
- Signale aussi tout autre défaut évident du plan, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-14.md`

Première ligne : `# Revue du plan T-14 — Événements, participants et emplacements`.

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, section du plan, problème, scénario ou référence, correction proposée) ; points vérifiés sans remarque ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

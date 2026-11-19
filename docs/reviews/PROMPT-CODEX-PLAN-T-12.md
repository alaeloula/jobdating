# Prompt de revue Codex — plan de T-12 (JD-17) : apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du plan** de T-12, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-12.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-12.md` (version 1) et `docs/tasks/CURRENT_TASK.md`, branche `feature/T-12-apprenants`, base `develop` (`d6cb56f`).
- Aucun code de T-12 n'est écrit. Le code existant sert de référence : `backend/app/Enums/Permission.php`, `Role.php`, `AuditAction.php`, `AuditCategory.php`, `app/Services/AuditLogger.php`, `app/Services/Accounts/StaffAccountService.php` (T-10), `app/Services/Companies/CompanyService.php` (T-11), `app/Services/Auth/SessionRevoker.php`, `app/Notifications/GeneratedPasswordNotification.php`, migrations des référentiels (`2026_11_16_170000_create_referentials_tables.php`), `frontend/src/AppRoutes.tsx`, `frontend/src/layout/AppLayout.tsx`.

## 2. Références

1. CDC V2 : **ADM-03** (scénario, données, RG-APR-01 à 07, critères d'acceptation), matrice 3.2, modèle 8.2 (`learners`, `learner_competence`), TRA-01, EXI-DATA-03, PC-15, PC-22, RG-AUTH-12 ; ADM-04 (import, pour la compatibilité avec T-13).
2. `docs/DECISIONS.md` : 14/11 (première connexion sans changement obligatoire ; réinitialisation par l'administrateur), 16/11 (compétences des apprenants sans niveau, nouvelle permission, CNIE masquée dans le journal, compétence désactivée), point ouvert COMP-01. Elles priment sur le CDC.
3. Décisions du responsable technique du 19/11 : tableau « Décisions » du plan (8 réponses). **Ne les remets pas en cause** ; signale seulement une contradiction avec le CDC ou une décision écrite.
4. Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-06-apprenants.png`, `admin-09-ajout-apprenant.png`.
5. `docs/ERREURS_A_CORRIGER.md` (E-01 à E-04 : ne pas les signaler).

## 3. Points à vérifier en priorité

- **Couverture d'ADM-03** : chaque étape, règle et critère d'acceptation est-il traité ou explicitement reporté (avec la tâche) ?
- **CNIE (RG-APR-06)** : masquage dans la liste, absence pour la CME et la Direction, consultation journalisée dans la fiche, recherche réservée à l'administrateur, absence dans les erreurs et le journal technique ; normalisation (H1) compatible avec l'import de T-13.
- **Droits** : l'entreprise possède déjà `learners.view` (prévu pour T-23) : la limitation par rôle prévue est-elle suffisante et cohérente avec la matrice ? Nouvelle permission `learners.competences.manage`.
- **Actions en masse** : sélection par identifiants ou par filtres, recomptage et 409, cohérence des filtres avec la liste, limite H2, journal (une ligne par compte et une synthèse), fermeture des sessions, transactions et durée d'exécution.
- **Création et réinitialisation** : unicité (supprimés compris), collisions au dernier moment, e-mail seulement après validation, mot de passe affiché une fois (`no-store`, hors cache), refus pour un compte désactivé.
- **Référentiels inactifs** : valeur déjà associée conservée, nouvelle valeur active exigée.
- **Modèle de données** : contraintes, index, suppression logique (fiche conservée), `learner_competence` sans niveau.
- **Frontend** : écran, sélection « tout le filtre », confirmation, effacement des messages d'erreur à la correction et enchaînements « erreur → correction → nouvel essai ».
- **Passages de relais** (T-13, T-14, T-20, T-23, T-40) et hypothèses H1 à H5 : ton avis sur chacune.
- **Tests prévus** : cas manquants importants.
- Signale aussi tout autre défaut évident du plan, même hors de cette liste.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-12.md`

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, section du plan, problème, scénario ou référence, correction proposée) ; avis sur les hypothèses H1 à H5 ; points vérifiés sans remarque ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

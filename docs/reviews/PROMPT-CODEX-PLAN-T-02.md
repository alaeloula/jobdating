# Prompt de validation du plan Codex — T-02 (JD-9) : référentiels

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Cette fois, il n'y a **pas de code à revoir** : tu valides le **plan** de la tâche T-02 **avant** le développement. **Tu ne modifies aucun fichier, tu ne commites rien, tu n'écris aucun code ni test.** Ton seul livrable est `docs/reviews/REVIEW-PLAN-T-02.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à examiner

- Branche `feature/T-02-referentiels` (créée depuis `develop`), non commitée : `git diff develop` et fichiers non suivis.
- Le plan : **`docs/tasks/PLAN-T-02.md`**.
- Les décisions ajoutées le **16/11/2026** dans `docs/DECISIONS.md` (compétences, secteurs, référentiels) et le point ouvert **COMP-01** ; les mises à jour de `docs/BACKLOG.md` (T-02, T-11, T-12, T-40).

## 2. Références

1. CDC V2 (`docs/reference/CAHIER_DES_CHARGES_V2.md`) : **ADM-09** (RG-REF-01 à 03), **8.2** (entités), **ENT-05** et **PC-23** (libellés validés), **ENT-03**, **RG-ENTR-02**, **RG-APP-01**, matrice **3.2**, **TRA-01**, **ADM-04** (import, classes inconnues).
2. `docs/DECISIONS.md` : les décisions du responsable technique **priment** sur le CDC. Celles du 16/11 sont **validées** : référentiel « Compétences » (contredit volontairement ENT-03), table des secteurs, aucune suppression, filière d'une classe figée dès qu'elle est utilisée, journalisation des référentiels, pas de données initiales pour les listes officielles. Ne les remets pas en cause ; signale seulement une incohérence entre elles ou avec le reste du CDC.
3. Maquette : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-12-referentiels.png`.
4. Code existant utile : permissions (`app/Enums/Permission.php`, `referentials.manage`), journal d'audit (`app/Services/AuditLogger.php`, `app/Enums/AuditAction.php`, `AuditCategory.php`), structure des routes et des tests.

## 3. Ce qu'il faut vérifier

- **Couverture** : le plan couvre-t-il tout ADM-09 et ses critères d'acceptation (« une filière ajoutée apparaît dans tous les formulaires et filtres sans intervention technique ») ? Manque-t-il un champ, une contrainte ou un comportement exigé par le CDC ou les décisions ?
- **Modèle de données** : tables, champs, unicités, clé classe → filière, champ `position`, nom `school_classes` (au lieu de `classes` du CDC 8.2) ; cohérence avec les tâches qui utiliseront ces tables (T-11, T-12, T-13, T-31, T-40, et la V2 : profils recherchés, type final du suivi).
- **Règles** : absence de suppression ; désactivation (« plus proposée en saisie mais reste affichée ») ; filière d'une classe modifiable seulement si non utilisée, alors que la table des apprenants n'existe pas encore (point d'extension prévu pour T-12) : l'approche est-elle saine ?
- **Droits** : gestion réservée à `referentials.manage` (administrateur, CME) ; lecture des valeurs actives ouverte à tout connecté : risque ou non ? Faut-il une nouvelle permission dès T-02 pour les compétences, ou seulement en T-12 comme prévu ?
- **Données initiales** : opportunités (PC-23) et étiquettes (ENT-05) ; seeder idempotent qui n'écrase pas une valeur modifiée ou désactivée à l'écran : faisable et cohérent ?
- **Journal d'audit** : nouvelle catégorie « Référentiels » et actions ; cohérence avec T-06 (service, filtres de l'écran du journal).
- **Tests prévus** : suffisants ? Cas manquants importants.
- **Hors périmètre** : est-il bien délimité, sans oublier une dépendance bloquante pour T-13 (import) ou T-31 (grille d'évaluation) ?
- **Décision « Compétences »** : incohérences avec le CDC ou entre décisions (RG-APP-01, RG-ENTR-08, matrice des droits, modèle `learner_competence`), conséquences sur T-11, T-12, T-40, et le point ouvert COMP-01.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-02.md`

1. En-tête : date, branche, fichiers examinés.
2. Constats numérotés, du plus important au moins important : **Bloquant / À corriger avant développement / Suggestion**, avec la référence (CDC, décision, plan), le problème, l'impact et la correction proposée **dans le plan** (pas de code).
3. Questions au responsable technique (points que les références ne tranchent pas).
4. Points du plan validés sans remarque.
5. **Verdict** : « Plan validé », « Plan validé après corrections mineures » ou « Plan à revoir ».

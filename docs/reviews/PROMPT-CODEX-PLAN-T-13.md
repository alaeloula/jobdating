# Prompt de revue Codex — plan de T-13 (JD-18) : import des apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du plan** de T-13, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-13.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-13.md` (version 1) et `docs/tasks/CURRENT_TASK.md`, branche `feature/T-13-import`, base `develop` (`81f9d02`).
- Aucun code de T-13 n'est écrit. Code existant de référence : `app/Services/Learners/LearnerService.php`, `LearnerFilters.php`, `LearnerClassUsage.php`, `app/Support/Cnie.php` (T-12) ; `app/Services/Referentials/ReferentialService.php` (T-02) ; `app/Notifications/GeneratedPasswordNotification.php` ; `app/Services/AuditLogger.php` ; `app/Enums/*` ; `routes/api.php` ; `config/queue.php` ; `docs/reference/ARCHITECTURE.md` (§ import, OpenSpout, file d'attente).

## 2. Références

1. CDC V2 : **ADM-04** (import des apprenants, RG-IMP-01 à 07, critères, exceptions), ADM-03, ADM-09, TRA-01, EXI-DATA-03, PC-22.
2. `docs/DECISIONS.md` : 14/11 (mot de passe généré, **fichier des mots de passe chiffré, téléchargeable plusieurs fois jusqu'à sa suppression**, pas de changement obligatoire à la première connexion), 16/11 (filière d'une classe, `ClassUsage`), 19/11 (« Apprenants (T-12) », CNIE). Elles priment sur le CDC.
3. **Décisions du 20/11** du tableau « Décisions » du plan (11 réponses). **Ne les remets pas en cause** ; signale seulement une contradiction avec le CDC ou une décision écrite.
4. Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-07-import-apprenants.png`, `admin-08-import-termine.png` (deux textes d'`admin-08` contredits par les décisions : voir le plan).
5. Section « Format observé du fichier réel » du plan. **Le fichier réel contient des données personnelles : il n'est pas dans le dépôt, ne le cherche pas et n'en demande pas.**
6. `docs/PROGRESS.md` : passages de relais de T-11 et T-12 vers T-13 ; `docs/ERREURS_A_CORRIGER.md` (E-01 à E-04 : ne pas les signaler).

## 3. Points à vérifier en priorité

- **Couverture d'ADM-04** (import des apprenants) : chaque étape, règle, critère d'acceptation et exception est-il traité ou explicitement reporté ? Notamment « un second import du même fichier ne crée aucun doublon et n'envoie aucun nouveau mot de passe » et « les colonnes ignorées ne sont enregistrées nulle part ».
- **Données personnelles** (EXI-DATA-03) : colonnes ignorées jamais conservées, journalisées ni renvoyées ; motifs de rejet sans CNIE ni e-mail ; `payload` chiffré effacé après traitement ; fichier des mots de passe (chiffrement réversible, téléchargement `no-store`, neutralisation des formules) ; risque des journaux techniques et des tâches échouées (`failed_jobs`).
- **Lecture** : détection CSV / XLSX, BOM, séparateur, titre en ligne 1 ou en-têtes en ligne 1, normalisation des en-têtes, limites (H1), fichiers malveillants (formules, très grands fichiers, encodages).
- **Correspondance des classes** : normalisation (casse, espaces), alias mémorisés (table `class_aliases`), classe inactive (H3), interaction avec `ClassUsage` et le changement de filière d'une classe.
- **Aperçu → confirmation** : empreinte, recomptage, 409 ; base modifiée entre l'aperçu et la confirmation, puis entre la confirmation et le traitement (re-vérification sous les verrous).
- **Traitement en file d'attente** : une seule transaction (tout ou rien) avec des centaines de comptes ; hachage des mots de passe avant la transaction ; **ordre de verrouillage** (toutes les classes, y compris les classes actuelles des apprenants connus, puis comptes et fiches) et réutilisation de `LearnerService::create` / `update` dans la transaction englobante (rappels `afterCommit`, reprises `ClassMovedException` et interblocages dans une transaction englobante) ; échec d'une tâche, nouvel essai, double traitement du même import ; e-mails seulement après la validation finale et seulement pour les actifs créés.
- **Mises à jour des apprenants connus** : champs mis à jour, réactivation / désactivation par la colonne Statut, compétences et mot de passe inchangés, liens annulés au changement d'e-mail, campus et promotion imposés à tous.
- **Journal** (RG-IMP-05) et **droits** (nouvelle permission `learners.import`).
- **Frontend** : étapes, aperçu effacé au changement, réponses tardives ignorées, 409, suivi de l'état du traitement, enchaînements « erreur → correction → nouvel essai ».
- **Dépendance OpenSpout** : version compatible PHP 8.4, licence, maintenance, risques.
- **Hypothèses H1 à H5** : ton avis sur chacune.
- **Tests prévus** : cas manquants importants.
- Signale aussi tout autre défaut évident du plan, même hors de cette liste.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-13.md`

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, section du plan, problème, scénario ou référence, correction proposée) ; avis sur H1 à H5 ; points vérifiés sans remarque ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

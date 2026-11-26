# Prompt de revue Codex — plan de T-64 (JD-44) : import des entreprises par fichier

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du plan** de T-64, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-64.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-64.md` (version 1.1) et `docs/tasks/CURRENT_TASK.md`, sur `develop` (`c3f0365`), documents non commités. La branche `feature/T-64-import-entreprises` sera créée avant le code.
- Code existant de référence : import des apprenants (`app/Services/Imports/*`, `app/Jobs/ProcessLearnerImport.php`, `app/Console/Commands/CleanupImports.php`, `app/Http/Controllers/LearnerImportController.php`, `src/pages/LearnerImportPage.tsx`, `src/learners/importApi.ts`) ; entreprises (`app/Services/Companies/*`, `CompanyController`, `src/pages/CompaniesPage.tsx`, `src/companies/*`) ; événements (`app/Services/Events/*`, `src/events/*`).
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Données personnelles : n'ouvre AUCUN fichier réel**, en particulier aucun classeur du dossier Téléchargements. Le classeur réel contient des noms de contacts. Sa structure utile est décrite dans le plan (§ 1 et § 3) ; utilise uniquement des classeurs fictifs.

## 2. Références

1. CDC V2 : **ADM-04 « Import des entreprises »** et exceptions communes, ADM-02 (RG-ENTR-01 à 07), ADM-07 (RG-PAR-01, 02, 07), TRA-01.
2. `docs/DECISIONS.md` : lignes du **25/11/2026** « Import des entreprises (T-64) » et « Reprise des entreprises abandonnée (T-16) » ; 14/11 (aucun e-mail aux entreprises) ; 20/11 (import des apprenants) ; 23/11 (T-14). Elles **priment sur le CDC**.
3. **Décisions 1 à 8 du plan : ne les remets pas en cause** (classeur tel quel, lignes « Oui », nom seul, doublon existant signalé seulement, nom simplifié, fiches comme l'import des apprenants, seconde feuille non utilisée, première occurrence créée). Signale seulement une contradiction ou une conséquence non traitée.
4. Maquettes dans `C:\Users\Youcode\Desktop\jobdating\maquettes\` : `admin-03`, `admin-07`, `admin-08`.
5. `docs/PROGRESS.md` (T-11, T-13, T-14) et `docs/ERREURS_A_CORRIGER.md` (E-01 à E-06 : ne pas les signaler).

## 3. Points à vérifier en priorité

- **Couverture** d'ADM-04 (import des entreprises) et de RG-ENTR-03 à 07 compte tenu des décisions.
- **Lecture du classeur** : feuille trouvée par nom simplifié, en-têtes en ligne 1, colonnes jamais lues au-delà de l'en-tête, lignes vides, limites, cellules non texte ou formules, « Oui » variantes, fichier modifié par l'équipe (cellules fusionnées, colonnes déplacées, feuille renommée).
- **Doublons** : nom simplifié (définition exacte, noms qui ne diffèrent que par la ponctuation, noms sans lettres), entreprises désactivées, doublons dans le fichier, collisions de noms d'utilisateur entre lignes et avec les comptes existants ou supprimés.
- **Concurrence** : création à la main ou autre import pendant le traitement ; événement archivé ou supprimé entre l'aperçu et le traitement ; ordre des verrous par rapport à T-11 et T-14 ; reprise sans doublon de journal ni de mot de passe.
- **Mots de passe et fiches** : chiffrement, impressions multiples, journal, suppression, fiche devenue caduque après une réinitialisation, « J'ai imprimé les fiches » en lot, aucun mot de passe dans le cache du navigateur ni dans les journaux.
- **Données personnelles** : liste blanche stricte (nom, nom d'utilisateur, numéro de ligne, motif) ; aucune autre valeur du classeur dans la base, le `payload`, le journal, les erreurs ou les réponses.
- **Droits** : chaque route pour les 5 rôles.
- **Frontend** : enchaînements « erreur → correction → nouvel essai », réponses tardives, double clic, changement d'événement pendant l'aperçu, impression en lot.
- **Tests prévus** : cas manquants importants.
- **Découpage et risques** : la tâche tient-elle avant l'échéance du 25/12 ? Réutilisation de T-13 sans dupliquer inutilement.
- Signale aussi tout autre défaut évident du plan, même hors de cette liste.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`).

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-64.md`

Première ligne : `# Revue du plan T-64 — Import des entreprises par fichier`.

En-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, section du plan, problème, scénario ou référence, correction proposée, rare ou non) ; points vérifiés sans remarque ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

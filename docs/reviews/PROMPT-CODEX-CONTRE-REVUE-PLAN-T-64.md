# Prompt de contre-revue Codex — plan de T-64 (JD-44) : import des entreprises

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-64.md` (« Plan à revoir » : 3 majeurs, 2 mineurs, 2 questions). Claude a corrigé le plan : tu fais une **contre-revue limitée**, **avant** l'écriture du code. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-64-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

**Données personnelles : n'ouvre AUCUN fichier réel**, en particulier aucun classeur du dossier Téléchargements. Classeurs fictifs uniquement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-64.md` (**version 1.2**) et `docs/tasks/CURRENT_TASK.md`, sur `develop` (`c3f0365`), non commités. Aucun code de T-64 n'existe.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Décisions à ne pas remettre en cause

Décisions 1 à 10 du plan. Les décisions **9** (événement supprimé après l'import) et **10** (entreprise désactivée exclue, retirée conservée avec avertissement) répondent à tes deux questions ; leur texte est au § 12 du plan et dans `docs/DECISIONS.md`. Signale seulement une contradiction ou une conséquence non traitée.

## 3. Ce qu'il faut vérifier

**A. Tes constats** : corrigé / partiel / non corrigé.

| # | Correction annoncée | Section |
|---|---|---|
| 1 | Clé `name_key` indexée non unique, verrou exclusif commun des noms pris en premier par l'import et par la création et le renommage à la main ; revalidation sous ce verrou ; essais à deux connexions | § 4, § 6, § 11 |
| 2 | Hachage d'origine conservé chiffré et comparé au hachage actuel ; re-contrôle sous verrous au marquage en lot | § 7, § 11 |
| 3 | Lecteur XML dédié en deux passages, frontière de décodage définie, sentinelles | § 3, § 11 |
| 4 | Clé : lettres et chiffres seulement, clé vide rejetée, première occurrence confirmée et valide, réservation des noms d'utilisateur du lot | § 4, § 5 |
| 5 | Emplacement actuel de l'événement de l'import sur les fiches | § 7 |

**B. Effets de bord**, en particulier :

- ordre des verrous : noms → événement → compte → entreprise, face à `CompanyService` (création, renommage, réinitialisation, impression) et à T-14 ; le marquage en lot respecte-t-il l'ordre entreprise → compte existant ?
- verrou exclusif des noms pendant un import de 1 000 lignes : attente des créations à la main, durée ;
- migration de `name_key` pour les entreprises existantes ; renommage par l'entreprise elle-même (`MyCompanyController` ne modifie pas le nom ?) ;
- comparaison du hachage : changement de coût bcrypt ou rehachage automatique à la connexion (`Hash::needsRehash`) rendant une fiche valide « caduque » ?
- lecteur XML : feuilles ou relations inhabituelles, chaînes riches (`<r>`), chaînes en ligne, protections ZIP, mémoire ;
- `event_id` en `nullOnDelete` et nom de l'événement conservé.

**C. Signale aussi tout défaut évident** du plan, même hors de cette liste et même s'il n'a pas été signalé dans ta première revue.

Indique pour chaque constat s'il est rare et sans impact sur les tâches suivantes (il pourrait alors être reporté dans `docs/ERREURS_A_CORRIGER.md`). Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-64-contre-revue.md`

Première ligne : `# Contre-revue du plan T-64 — Import des entreprises par fichier`.

En-tête ; périmètre ; tableau des constats rejoués ; effets de bord ; nouveaux constats (Bloquant / Majeur / Mineur / Suggestion, section, scénario, correction, rare ou non) ; limites ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

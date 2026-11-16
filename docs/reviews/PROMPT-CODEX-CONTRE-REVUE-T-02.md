# Prompt de contre-revue Codex — T-02 (JD-9) : référentiels

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-T-02-referentiels.md` (verdict « À fusionner après corrections mineures »). Claude a traité tes constats : tu fais une **contre-revue limitée à ces corrections**. **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou dans des tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-02-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-02-referentiels`, base `develop` (`799d49a`), **toujours non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- Référence des corrections : `docs/PROGRESS.md`, entrée « 2026-11-16 — T-02 », paragraphe « Revue du code par Codex… ».
- MySQL : `docker compose up -d mysql`. Tests sur `jobdating_test`, jamais SQLite.
- **Périmètre** : uniquement les corrections ci-dessous et leurs effets de bord. Le reste de T-02 a déjà été revu : ne refais pas la revue complète.

## 2. Ce qu'il faut vérifier

**A. Rejouer tes constats 1 et 2** avec les mêmes scénarios, et dire : corrigé / partiel / non corrigé.

| # | Correction annoncée | Fichiers |
|---|---|---|
| 1 | Règle d'unicité ajoutée aussi sur `type` (filtrée par le libellé effectif : reçu, sinon libellé actuel) ; message `type.unique` « Une étiquette de ce type porte déjà ce libellé. » | `app/Enums/ReferentialType.php` (`rules()`, cas `Etiquettes`), `app/Http/Controllers/ReferentialController.php` (`messages()`) |
| 2 | Règle `list` sur `ids` (un objet JSON donne 422) ; le service compte les positions 1, 2, 3… sans utiliser les clés reçues | `ReferentialController::order()`, `app/Services/Referentials/ReferentialService.php` (`reorder()`) |

**B. Vérifier que les corrections n'ouvrent pas de nouveau problème**, notamment :
- étiquettes : création (les deux champs reçus), modification du type seul, du libellé seul, des deux, renvoi de la valeur inchangée (pas de conflit avec elle-même) ; casse et accents (collation) ; type invalide : le message reste-t-il « Type d'étiquette inconnu. » et non celui d'unicité ?
- le changement de type d'une étiquette utilisée reste refusé (point d'extension `TagUsage`), et l'ordre des contrôles (validation, puis service) ne masque pas ce refus ;
- ordre : tableau vide, liste avec `null` ou chaînes, liste JSON valide, liste de listes ; aucun effet sur le contrôle « groupe complet, sans doublon ».

**C. Qualité des nouveaux tests** : échoueraient-ils sur l'ancien code ?
- `tests/Feature/Referentials/ReferentialManagementTest.php` : `test_changement_de_type_seul_vers_un_homonyme_refuse_proprement`, `test_l_ordre_refuse_un_objet_json`, `test_un_echec_du_journal_annule_la_modification_et_l_ordre` (journal remplacé par une version qui lève une exception ; 500 attendu, données et nombre de lignes du journal inchangés) ;
- `frontend/src/referentials/referentials.test.tsx` : `déplace une opportunité et envoie le nouvel ordre complet`.

## 3. Contrôles

**Ne relance pas la suite complète** (`php artisan test`, environ 22 minutes) : Claude vient de l'exécuter sur le même code. Utilise ses résultats ci-dessous et indique-les dans ton rapport comme **communiqués par Claude, non rejoués par toi**.

Résultats de Claude (16/11/2026, après les corrections) :
- `vendor/bin/pint --test` : réussi ;
- `vendor/bin/phpstan analyse` : aucune erreur ;
- `php artisan test tests/Feature/Referentials` : 32 tests réussis (229 assertions) ;
- `php artisan test` (suite complète) : **237 tests réussis, 935 assertions** (environ 23 min, code de sortie 0) ;
- frontend : `npm.cmd run lint` réussi, `npm.cmd run test` **27 tests réussis**, `npm.cmd run build` réussi (vérification TypeScript comprise).

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24.

**Tu restes libre d'exécuter tous les contrôles que tu juges utiles** (rejeu de tes scénarios, vérifications de la partie 2.B, bancs ciblés…). **Évite seulement de relancer ce que Claude a déjà exécuté** (liste ci-dessus).

La suite de Claude est **terminée** : la base `jobdating_test` est libre pour tes contrôles.

Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 4. Format du rapport `docs/reviews/REVIEW-T-02-contre-revue.md`

Même structure que `docs/reviews/REVIEW-T-06-contre-revue.md` : en-tête ; rejeu des constats 1 et 2 (scénario, résultat, statut) et suivi de la suggestion 3 ; nouveaux constats éventuels (sévérité, fichier et ligne, confirmé ou hypothétique, scénario, impact, correctif minimal) ; qualité des tests ; points vérifiés sans remarque ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

# Prompt de revue Codex — T-02 (JD-9) : référentiels

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as validé le **plan** de T-02 en deux passes (`docs/reviews/REVIEW-PLAN-T-02.md`, `REVIEW-PLAN-T-02-contre-revue.md`). Le code est maintenant écrit : tu fais la **revue du code**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt** (bancs de contrôle en mémoire ou dans des tables préfixées supprimées ensuite). Seul livrable : `docs/reviews/REVIEW-T-02-referentiels.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-02-referentiels`, base `develop` (`799d49a`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- MySQL : `docker compose up -d mysql`. Tests sur `jobdating_test`, jamais SQLite.

## 2. Références

1. **`docs/tasks/PLAN-T-02.md`, version 3** : contrat de référence (dont les 3 points de ta contre-revue intégrés après coup : homonymes du seeder, verrou de ligne et contrôles d'utilisation, contrat du champ de confirmation). La décision du responsable technique a été de **ne pas faire de troisième revue du plan** : vérifie ces 3 points **dans le code**.
2. `docs/DECISIONS.md`, décisions du 16/11 (compétences, secteurs, référentiels, précisions après ta revue du plan : filière d'une classe **corrigible avec confirmation**, compétence désactivée, type d'étiquette, code de filière définitif). Elles priment sur le CDC ; ne les remets pas en cause.
3. CDC V2 : ADM-09 (RG-REF-01 à 03), 8.2, ENT-05 / PC-23, PC-16, PC-35, matrice 3.2, TRA-01.
4. Maquette `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-12-referentiels.png` (onglet « Compétences » ajouté par décision).
5. `docs/PROGRESS.md`, entrée « 2026-11-16 — T-02 » ; `docs/tasks/CURRENT_TASK.md`.

Hors périmètre (ne pas signaler comme oubli) : formulaires et filtres consommateurs (T-11, T-12, T-13, T-31, T-40), compétences recherchées et compétences des apprenants, saisie en masse (COMP-01), tests de simultanéité sur MySQL (prévus avec T-12 / T-13 / T-31, quand les relations existeront).

## 3. Points à vérifier en priorité

- **Droits** : chaque opération de gestion (liste, ajout, modification, changement d'état, ordre) réservée à `referentials.manage` ; options lisibles par tout connecté ; 401 sans session ; aucune route de suppression ; identifiant appartenant au type de l'adresse.
- **Validation et unicité** : chaque type ; unicité sensible à la collation (casse, accents) ; étiquettes : type + libellé ; code de filière (format, définitif) ; filière d'une classe obligatoire et existante ; champs non modifiables (`seed_key`, `position`) jamais acceptés depuis la requête.
- **Confirmation de filière** : seul `true` booléen vaut confirmation ; absent / `false` → 409 avec `learners_count` recalculé ; autre valeur → 422 ; aucun champ modifié ni journal en cas de refus ; contrôle fait sous verrou `FOR UPDATE` dans la transaction de la mise à jour.
- **Type d'étiquette** : refusé si utilisée (point d'extension), sinon modifié et placé en fin de son nouveau groupe.
- **Ordre** : groupe complet exigé, doublons et identifiants étrangers refusés, atomicité (verrou + transaction), tri stable, journal.
- **Lecture** : options actives par défaut, `?inactives=1` avec état, pas d'e-mail de campus ni de `seed_key`.
- **Journal** : catégorie « Référentiels » et actions explicites ; libellé du sujet ; valeurs avant / après ; écriture dans la même transaction ; filtre de l'écran du journal (7 catégories).
- **Seeder** : clés fixes ; homonyme sans clé rattaché une seule fois sans modifier ses champs métier ni journaliser de création ; homonyme dans l'autre type d'étiquette sans effet ; relance après renommage / désactivation / réordonnancement sans réintroduction ni nouveau journal.
- **Points d'extension** `ClassUsage`, `TagUsage` : contrat documenté (obligation de branchement et de verrou pour T-12 / T-13 / T-31), résolution à chaque contrôle.
- **Frontend** : écran (8 onglets, étiquettes par type, ordre, code en lecture seule, confirmation), lien réservé à `referentials.manage`, garde de route administrateur / CME ; `ApiError.payload`.
- **Tests** : faux positifs éventuels, cas manquants importants.

## 4. Contrôles

**Ne relance pas la suite complète** (environ 20 minutes) : Claude vient de l'exécuter. Utilise ses résultats et cite-les comme **communiqués par Claude, non rejoués**. Tu restes libre d'exécuter tous les contrôles ciblés que tu juges utiles ; évite seulement de relancer ce qui figure ci-dessous.

Résultats de Claude (16/11/2026) :
- `vendor/bin/pint --test` : réussi ;
- `vendor/bin/phpstan analyse` : aucune erreur ;
- `php artisan test tests/Feature/Referentials` : 29 tests réussis (200 assertions) ;
- `php artisan test` (suite complète) : **234 tests réussis, 906 assertions** (environ 22 min, code de sortie 0) ; la suite de Claude est terminée, la base `jobdating_test` est libre ;
- frontend : `npm.cmd run lint` réussi, `npm.cmd run test` **26 tests réussis**, `npm.cmd run build` réussi (vérification TypeScript comprise) ;
- vérifié dans le navigateur avec le vrai backend (compte CME de démonstration) : 8 onglets, ajout d'une filière, formulaire vidé après l'ajout, opportunités initiales, réordonnancement.

Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-02-referentiels.md`

Même structure que `docs/reviews/REVIEW-T-06-journal-audit.md` : en-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal sans hors périmètre) ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

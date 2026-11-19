# Prompt de contre-revue Codex — plan de T-12 (JD-17) : apprenants

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-12.md` (« Plan à revoir », 4 constats majeurs, 2 mineurs, 3 questions). Claude a écrit la **version 2** du plan : tu fais une **contre-revue limitée**. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-12-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- `docs/tasks/PLAN-T-12.md`, **version 2** : changements marqués **[v2]**. Branche `feature/T-12-apprenants`, base `develop` (`d6cb56f`), non commitée. Aucun code de T-12 n'est écrit.
- **Périmètre** : uniquement les corrections [v2] et leurs effets de bord. Le reste du plan a déjà été revu.

## 2. Décisions du responsable technique (19/11), à ne pas remettre en cause

- Les 6 constats sont tous corrigés dans le plan.
- Réponses à tes 3 questions (décisions 9 à 11 du plan) :
  - **9** : CNIE en lettres et chiffres, 5 à 30 caractères, majuscules ; seuls les espaces sont retirés ; tout autre caractère est refusé avec un message clair ;
  - **10** : l'ajout d'une compétence en masse vise tous les apprenants **non supprimés** (actifs et désactivés) ; la désactivation en masse ne vise que les actifs ;
  - **11** : `ClassUsage` compte **toutes** les fiches de la classe, désactivées et supprimées comprises.

## 3. Ce qu'il faut vérifier


**A. Rejouer tes constats** : pour chacun, corrigé / partiel / non corrigé dans le plan, avec la section concernée.

| # | Correction annoncée | Section du plan |
|---|---|---|
| 1 | `LearnerClassUsage` branché à la place de `NoClassUsage` ; ordre de verrouillage unique **classes (id croissant) → compte → fiche**, pour `create` et le changement de classe ; tests 409 / confirmation / concurrence MySQL | §3, §4, Tests |
| 2 | Synthèses en masse sans recherche libre ni filtres bruts ; recherche par **`POST /learners/search`** (CNIE hors des adresses) ; tests « aucune CNIE en clair » (journal, erreurs, collisions en création et modification, synthèses) | §5, §6, §7, Tests |
| 3 | Liens de réinitialisation annulés quand l'e-mail **change effectivement**, sous le verrou du compte ; règle héritée par T-13 | §4 `update`, Passages de relais, Tests |
| 4 | Contrat unique de sélection : `ids` **ou** `filters`, éligibilité par action, `POST /learners/selection/count` → `{ selected, eligible }`, exécution avec `expected_eligible` sur l'ensemble verrouillé, 409 sans écriture ni journal, sélection vidée au changement de filtre, nouvelle confirmation explicite | §5, §9, Tests |
| 5 | Valeurs liées renvoyées avec l'état actif ; valeur actuelle inactive gardée dans le formulaire ; options historiques dans les filtres | §6, §9, Tests |
| 6 | Notification à deux motifs (création / réinitialisation) | §8, Tests |

**B. Effets de bord des corrections** :
- le passage de `GET /learners` à `POST /learners/search` : conformité REST du projet, protection CSRF (Sanctum, cookies), pagination, cache TanStack, droits par rôle ;
- l'ordre de verrouillage : risque d'interblocage avec `ReferentialService::update` (qui verrouille la classe) et avec les actions en masse (qui verrouillent les comptes) ;
- le contrat de sélection : cas limites (sélection vide, M = 0, ensemble qui grossit ou diminue entre le comptage et l'exécution, identifiants d'apprenants supprimés envoyés par la CME) ;
- les compléments de tests demandés dans ta revue (échec du journal au milieu d'une action en masse, suppression avec sessions et liens, état du compte relu sous son verrou, seeder sans référentiels) : sont-ils tous repris ?

**C. Hypothèses** H2 (2 000, mesure prévue) et H5 : ton avis sur leur nouvelle rédaction.

**D. Signale aussi tout autre défaut évident** que tu remarques dans la version 2, même hors de cette liste.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-12-contre-revue.md`

En-tête ; périmètre ; tableau des constats rejoués (corrigé / partiel / non corrigé, avec section) ; effets de bord ; nouveaux constats éventuels (Bloquant / Majeur / Mineur / Suggestion, section, problème, correction proposée) ; questions au responsable technique ; **verdict** (« Plan validé », « Plan validé après corrections mineures », « Plan à revoir »).

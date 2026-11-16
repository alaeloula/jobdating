# Prompt de contre-revue du plan Codex — T-02 (JD-9) : référentiels

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu as rendu `docs/reviews/REVIEW-PLAN-T-02.md` (verdict « Plan à revoir »). Claude a mis à jour le plan : tu fais la **contre-revue du plan**, toujours **avant tout développement**. **Tu ne modifies aucun fichier, tu ne commites rien, tu n'écris aucun code ni test.** Seul livrable : `docs/reviews/REVIEW-PLAN-T-02-contre-revue.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à examiner

- Branche `feature/T-02-referentiels`, non commitée : `git diff develop` et fichiers non suivis.
- **`docs/tasks/PLAN-T-02.md`, version 2.**
- `docs/DECISIONS.md` : décisions du 16/11 sur les compétences, les secteurs, les référentiels, et les **précisions ajoutées après ta revue** (voir partie 2).

Aucun test ni contrôle applicatif à exécuter : il n'y a pas de code. Tu peux lire le code existant (journal d'audit, permissions, seeders) pour vérifier la faisabilité.

## 2. Décisions du responsable technique prises après ta revue (16/11)

À appliquer, ne pas remettre en cause :
- **Filière d'une classe** (réponse à ta question 1) : la règle « interdite si la classe est utilisée » est **remplacée**. La filière reste **modifiable même si la classe est utilisée**, pour corriger une erreur d'affectation après l'import ; l'API refuse sans confirmation explicite (409 avec le nombre d'apprenants concernés) et accepte avec confirmation ; l'écran affiche « N apprenants changeront de filière » ; le changement est journalisé. La filière d'un apprenant est toujours lue via sa classe.
- **Compétence désactivée** (ta question 2) : ne compte plus pour les suggestions ; ses associations restent conservées.
- **Type d'une étiquette** (ta question 3) : modifiable tant qu'aucune évaluation ne l'utilise ; sinon refusé.
- **Code d'une filière** : définitif après création.

## 3. Ce qu'il faut vérifier

**A. Tes 5 constats** : pour chacun, dire s'il est traité, partiellement traité ou non traité dans le plan v2.

| # | Constat | Traitement annoncé dans le plan v2 |
|---|---|---|
| 1 | Collision `evaluation_tags` | Catalogue `evaluation_tags_ref`, association `evaluation_tags` réservée à T-31 ; table `classes` (modèle `SchoolClass`) conforme au CDC |
| 2 | Identité des valeurs initiales | Clé d'initialisation fixe (`seed_key`) ; création des clés manquantes seulement ; aucune mise à jour d'une valeur existante ; homonyme manuel non modifié ni dupliqué ; test de relance après renommage, désactivation, réordonnancement |
| 3 | Lecture des valeurs désactivées | Trois usages : options actives (saisie) ; valeur liée renvoyée même inactive par chaque ressource consommatrice ; `?inactives=1` pour les filtres historiques ; e-mail de campus retiré des options |
| 4 | Intégration au journal | Catégorie et actions explicites, libellé du sujet, transaction (dont réordonnancement), seeder journalisé seulement s'il crée, test des catégories 6 → 7 et du filtre |
| 5 | Invariants testés | Code de filière définitif ; ordre (groupe, doublons, atomicité, tri stable) ; filière de classe obligatoire et existante ; points d'extension simulables ; droits sur chaque opération ; identifiant d'un autre type refusé (404) |

**B. Les nouveautés du plan v2**, en particulier :
- le contrat de confirmation pour la filière d'une classe (409 sans confirmation, nombre d'apprenants, journalisation) : cohérent, testable, et sans risque d'appliquer le changement sans confirmation ?
- le point d'extension « nombre d'apprenants d'une classe » et « étiquette utilisée » : sains avant T-12 / T-31, et obligatoirement branchés ensuite ?
- le paramètre `?inactives=1` ouvert à tout connecté : acceptable ?
- les clés d'initialisation : faisabilité sans nouvelle dépendance, unicité, comportement en cas d'homonyme manuel.

**C. Cohérence** entre le plan v2, les décisions du 16/11 et le CDC (ADM-09, 8.2, ENT-05/PC-23, TRA-01, matrice 3.2), et nouveaux problèmes éventuels.

## 4. Format du rapport `docs/reviews/REVIEW-PLAN-T-02-contre-revue.md`

1. En-tête : date, branche, fichiers examinés.
2. Rejeu des 5 constats (traité / partiel / non traité, avec la référence dans le plan v2).
3. Nouveaux constats éventuels : **Bloquant / À corriger avant développement / Suggestion**, avec référence, problème, impact et correction proposée **dans le plan** (pas de code).
4. Questions au responsable technique, s'il en reste.
5. Points validés sans remarque.
6. **Verdict** : « Plan validé », « Plan validé après corrections mineures » ou « Plan à revoir ».

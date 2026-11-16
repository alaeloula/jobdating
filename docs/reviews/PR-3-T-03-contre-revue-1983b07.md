| Constat précédent | Statut | Preuve de contre-revue |
|---|---|---|
| 1. Plusieurs rôles attribuables avant sauvegarde | **Corrigé** | Sur MySQL 8, chaque appel à `assignRole()` ou `setRole()` avant sauvegarde lève désormais une `LogicException`. Après interception puis sauvegarde, aucun rôle différé n'est inséré. Le même scénario sur `bfc40c7` insère deux rôles. |
| 2. Contrôle du rôle unique non atomique | **Corrigé** | L'index unique refuse une seconde insertion directe : erreur MySQL 1062, premier rôle conservé. Deux connexions réelles confirment le verrou : la seconde attend sur le `SELECT ... FOR UPDATE` de `users` et expire avec 1205 lorsque le verrou est volontairement conservé. Sur `bfc40c7`, les affectations concurrentes aboutissent à deux rôles. |
| 3. Un `syncRoles()` refusé supprimait le rôle précédent | **Corrigé** | `syncRoles(['cme', 'direction'])` lève une `LogicException` en conservant `entreprise`. Sur `bfc40c7`, le même appel laisse la liste des rôles vide. |

# Contre-revue indépendante — PR #3 — JD-10 T-03

Date : 2026-11-12.

Tête examinée : `1983b07ad173f2be4df36e9b3dcbc6fbf88e07e6`.

Comparaisons réalisées après `git fetch origin` :

```text
git diff bfc40c7..origin/feature/T-03-roles-permissions
git diff origin/develop...origin/feature/T-03-roles-permissions
```

Références relues : AGENTS.md, CLAUDE.md, CDC V2 sections 3.2 et 3.3, dernière entrée de PROGRESS.md sur la branche cible, « Corrections après la revue indépendante ». Le trait Spatie 8.5.0, sa migration initiale et la gestion des transactions Laravel installée ont également été examinés.

Aucun code, fichier de configuration ou test du projet modifié ; aucun checkout, commit ni push. Seul ce rapport est créé, conformément à la préférence de l'utilisateur pour les rapports Markdown. Les scripts de vérification et le code de la branche cible ont été chargés en mémoire.

## Nouveau constat — suggestion de couverture du verrou

- **Sévérité : Suggestion**, facultative et non bloquante.
- **Fichier et ligne :** `backend/tests/Feature/Authorization/SingleRoleTest.php:131`, en particulier les lignes 138 à 143, au commit examiné.
- **Qualification : limite de couverture confirmée par lecture du test.** Aucune mutation du code n'a été appliquée pour cette observation.
- **Scénario :** le test insère séquentiellement une seconde association dans `model_has_roles`. Si le verrou de `User::lockForRoleChange()` était retiré tout en conservant l'index unique, ce test continuerait à recevoir une `QueryException`. Il ne vérifierait donc pas la disparition du verrou.
- **Impact :** la CI vérifie la garantie d'unicité en base, mais ne distingue pas la sérialisation applicative d'un conflit arrêté uniquement par l'index. Cela ne remet pas en cause la garantie du rôle unique ni le correctif actuel, dont le verrou a été vérifié localement sur MySQL.
- **Correctif minimal proposé :** ajouter, lorsque pertinent dans l'environnement de tests, un test MySQL utilisant deux connexions et vérifiant l'attente sur la ligne utilisateur. Ne pas présenter l'insertion séquentielle comme une preuve du verrou. Aucun correctif appliqué.

Aucun nouveau constat bloquant, majeur ou mineur identifié.

## Rejeu des trois scénarios précédents

### 1. Compte non enregistré

Scénarios rejoués séparément sur les deux commits :

```php
$user = new User([
    'name' => 'Review',
    'email' => 'review@example.test',
    'password' => 'review-only',
]);

foreach (['cme', 'administrateur'] as $role) {
    try {
        $user->assignRole($role);
    } catch (LogicException) {
        // Permet de vérifier aussi l'absence d'insertion différée au save().
    }
}

$user->save();
```

Même vérification avec `setRole(Role::Cme)` puis `setRole(Role::Administrateur)`.

| Version | Refus par scénario | Rôles après sauvegarde |
|---|---|---|
| `bfc40c7` | 0 | `administrateur`, `cme` |
| `1983b07` | 2 | Aucun |

Le refus intervient avant l'appel au trait : aucun gestionnaire d'insertion différée n'est enregistré. Références : `User.php:87`, `User.php:110` et `User.php:131`.

### 2. Concurrence

Un processus PHP parent et un processus PHP enfant utilisent des connexions MySQL distinctes et les mêmes tables de contrôle.

Premier scénario : le parent ouvre une transaction et attribue `cme` sans encore la valider. L'enfant tente d'attribuer `administrateur` au même utilisateur.

| Version | Résultat de l'enfant pendant la transaction du parent | État après validation du parent |
|---|---|---|
| `bfc40c7` | Attribution réussie | Deux rôles : `administrateur`, `cme` |
| `1983b07` | Attente puis erreur MySQL 1205, délai volontairement limité à une seconde | Un seul rôle : `cme` |

Après validation sur `1983b07`, une nouvelle tentative d'ajouter `administrateur` est refusée par `LogicException` ; `cme` est conservé.

Un second contrôle isole le verrou utilisateur de l'index unique :

1. Créer un utilisateur sans aucune association dans `model_has_roles`.
2. Verrouiller sa ligne dans une transaction sur la première connexion.
3. Appeler `assignRole('administrateur')` sur la seconde connexion.
4. Vérifier la requête qui expire.

Requête observée dans la `QueryException` de la seconde connexion :

```sql
select * from `cr37ff94_users`
where `cr37ff94_users`.`id` = ? limit 1 for update
```

Erreur MySQL : **1205**. Aucune ligne de rôle n'existait : l'attente porte donc sur `users`, pas sur une collision d'insertion dans le pivot. Après libération du verrou, l'attribution réussit. Le moteur de la table a été vérifié : **InnoDB**.

Ce contrôle prouve l'attente sur MySQL. Il ne prétend pas démontrer l'absence de tout interblocage dans de futurs traitements multicomptes.

Références : `User.php:114` et `User.php:143`, nouvelle migration ligne 18.

### 3. Remplacement refusé

```php
$user->setRole(Role::Entreprise);

try {
    $user->syncRoles(['cme', 'direction']);
} catch (LogicException) {
    // Vérifier ensuite les rôles relus en base.
}
```

- `bfc40c7` : exception, puis liste de rôles vide.
- `1983b07` : exception, puis rôle `entreprise` conservé.

La vérification de cardinalité est faite dans `User.php:89`, avant le détachement effectué par Spatie. Le remplacement valide est ensuite protégé par une transaction.

## Contrainte d'unicité et réversibilité

Fichier : `backend/database/migrations/2026_11_12_160000_add_single_role_constraint_to_model_has_roles.php`.

- L'index `model_has_roles_un_role_par_compte` porte sur les deux colonnes attendues : `model_id` et `model_type`. Son nom respecte la limite MySQL de 64 caractères.
- Le nom de table et la clé morphique proviennent de `config/permission.php`. Dans la configuration actuelle : `model_has_roles`, `model_id`, teams désactivées.
- La clé primaire Spatie `(role_id, model_id, model_type)` demeure intacte. Le nouvel index impose une règle plus restrictive sur le compte ; il n'entre pas en conflit avec cette clé primaire.
- `SHOW INDEX` sur MySQL confirme les deux colonnes de l'index unique, la clé primaire et l'index non unique initial.
- Une insertion directe avec un autre `role_id`, pour le même couple `(model_id, model_type)`, reçoit l'erreur **1062** ; le rôle initial reste présent.
- Le `down()` retire uniquement le nouvel index. Exécution vérifiée : après `down()`, deux rôles peuvent de nouveau être insérés ; après nettoyage de cette association de contrôle, `up()` peut être réexécuté avec succès.

Les opérations suivantes ont été rejouées avec la contrainte active sur MySQL :

| Opération | Résultat |
|---|---|
| `syncRoles()` vers le rôle déjà attribué | Réussite, une seule association |
| `removeRole()` | Réussite, association retirée |
| Suppression d'un rôle Spatie | Associations correspondantes retirées |
| Suppression d'un utilisateur | Associations correspondantes retirées |
| Remplacement valide du rôle | Réussite, vérifiée notamment pendant les 125 cellules |

Une base qui contiendrait déjà plusieurs rôles pour un même compte ferait échouer l'ajout de l'index. Le correctif ne choisit pas arbitrairement quel rôle conserver ; aucune reprise de telles données n'est effectuée par cette revue.

## Transactions, verrou et erreurs concurrentes

`syncRoles()` ouvre une transaction, appelle le trait Spatie, puis celui-ci rappelle la surcharge `assignRole()`, qui ouvre une transaction imbriquée. Avec la même connexion MySQL, Laravel utilise un savepoint ; la transaction interne ne valide pas la transaction externe. Le verrou de la même ligne par la même transaction ne crée pas un interblocage avec elle-même.

Contrôle local : ouvrir une transaction externe, remplacer `cme` par `direction`, constater que le niveau transactionnel revient à 1 après les méthodes, puis annuler la transaction externe. Le rôle relu est bien `cme`.

Le chemin normal des deux surcharges acquiert la ligne utilisateur avant de modifier le pivot. Aucun ordre de verrouillage inversé ni interblocage reproductible n'a été identifié dans ce diff. L'absence de reprise automatique après interblocage n'est pas considérée comme un défaut de T-03 : aucune route métier n'est ajoutée et aucun scénario concret du diff n'impose une telle reprise.

Une écriture qui contourne le protocole applicatif reste arrêtée par l'index et peut lever une `QueryException` ; une expiration d'attente peut également en lever une. C'est acceptable au périmètre actuel. Il n'est pas nécessaire de traduire ces exceptions maintenant, en l'absence de route ou de contrat d'erreur métier dans T-03. Lors d'une future exposition, distinguer la collision d'unicité des autres erreurs SQL plutôt que convertir indistinctement toutes les `QueryException` en `LogicException`.

Références : gestion Laravel installée dans `Illuminate/Database/Concerns/ManagesTransactions.php`, et [documentation MySQL sur les lectures avec verrou](https://dev.mysql.com/doc/refman/8.0/en/innodb-locking-reads.html).

## Factories et création des futurs comptes

Le refus avant sauvegarde est cohérent avec la correction retenue. Aucun usage existant du diff n'exige d'attribuer un rôle avant l'insertion du compte. Une factory `make()` suivie d'une attribution est désormais explicitement refusée ; `create()` puis attribution reste valide.

Ordre à utiliser dans les prochaines tâches : créer le compte, puis attribuer son rôle, idéalement au sein de la même transaction de création. Pour les factories, utiliser `afterCreating`, pas `afterMaking` :

```php
User::factory()
    ->afterCreating(fn (User $user) => $user->setRole(Role::Cme))
    ->create();
```

Il s'agit d'une indication pour les tâches suivantes, sans modification de factory dans cette revue.

## Tests de régression et risque de faux positif

Les cinq nouveaux tests ciblent effectivement des comportements incorrects de `bfc40c7` :

| Nouveau test | Pourquoi son attente n'est pas satisfaite sur `bfc40c7` |
|---|---|
| Refus de `setRole()` avant sauvegarde | Aucune `LogicException` n'est levée |
| Refus de `assignRole()` avant sauvegarde | Aucune `LogicException` n'est levée |
| Aucune accumulation avant sauvegarde | Deux rôles sont enregistrés au `save()` |
| Rôle conservé après `syncRoles()` refusé | Le rôle précédent est supprimé |
| Refus d'un second rôle par la base | La seconde insertion est acceptée sans le nouvel index |

Les comportements correspondants ont été rejoués sur les deux versions, avec les résultats ci-dessus. Cela ne constitue pas une exécution des cinq méthodes PHPUnit elles-mêmes : le code a été chargé en mémoire et des contrôles ciblés équivalents ont été exécutés.

Le test de conservation du rôle contient bien un échec explicite si aucune exception n'est levée. Le test d'accumulation complète les deux tests d'exception en vérifiant l'état réel après sauvegarde. Le test de contrainte utilise un rôle et un utilisateur existants ; lors du rejeu local, l'erreur obtenue sur la nouvelle version est précisément 1062. La suggestion de couverture du verrou est détaillée plus haut.

## Non-régression et environnement de validation

Contrôles locaux exécutés sur **PHP 8.5.12, MySQL 8.0.46, InnoDB et Spatie Permission 8.5.0**. **Aucun résultat SQLite n'est utilisé pour conclure sur les verrous.** PHP 8.4 exact n'a pas été testé localement.

La branche de travail reste `develop`. Les classes des deux commits ont été chargées depuis Git en mémoire. Les migrations et opérations ont utilisé des tables isolées dans `jobdating_test`, via des préfixes de connexion dédiés : `cr71fdf9_`, `crb5a2e2_` et `cr37ff94_`. Toutes ces tables ont été supprimées après contrôle ; la recherche finale a retourné zéro table restante pour chacun des préfixes. Les tables applicatives n'ont pas été modifiées.

Résultats complémentaires :

- **125 cellules** : concordance entre le CDC 3.2, la matrice littérale du test, `Role::permissions()` et les permissions effectives des utilisateurs sur MySQL ; aucun écart.
- Seeder relancé : **5 rôles, 25 permissions**, associations inchangées.
- `DatabaseSeeder` : **aucun compte créé**.
- `currentRole()` et `User::role()` : fonctionnement vérifié, sans conflit.
- Les corrections n'ajoutent aucune dépendance et ne modifient pas les éléments de périmètre précédemment validés.

La suite PHPUnit complète, Pint, Larastan, le lint et le build frontend n'ont pas été relancés localement sur la branche cible. Résultats CI fournis par l'utilisateur pour `1983b07`, non revérifiés dans GitHub pendant cette contre-revue : **142 tests, 150 assertions sur MySQL 8 ; Pint OK ; Larastan niveau 6 sans erreur ; lint et build frontend OK**.

## Verdict final

**À fusionner.**

Les trois constats majeurs sont corrigés. La suggestion de couverture du verrou est facultative et ne conditionne pas la fusion.

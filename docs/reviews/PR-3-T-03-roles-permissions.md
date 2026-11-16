# Revue indépendante — PR #3 — JD-10 T-03

Date : 2026-11-12.

Objet : rôles, permissions et matrice des droits.

Diff examiné : `origin/develop...origin/feature/T-03-roles-permissions`.

- Base `origin/develop` : `1845df724417bf49df4735c6b5ddb7853656451a`.
- Tête examinée : `bfc40c737f69484db721b4c92fdd18f03c2f7429`.

Aucun code applicatif modifié, aucun commit ni push. Ce rapport est enregistré à la demande de l'utilisateur après la revue.

## Références et périmètre

Références examinées : AGENTS.md, CLAUDE.md, CDC V2 sections 3.1, 3.2, 3.3 et EXI-SEC-11, ARCHITECTURE sections 1, 5 et 11, DECISIONS.md et dernière entrée de PROGRESS.md sur la branche cible.

Périmètre validé respecté : cinq rôles, vingt-cinq permissions, permissions attribuées explicitement à l'administrateur sans super-admin, policies de périmètre différées aux ressources, APP-02 prévu en T-40, aucune route ni interface métier attendue.

## 1. Plusieurs rôles attribuables avant sauvegarde

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Models/User.php:83`, lignes 83 à 90 de la tête examinée.
- **Qualification : constat confirmé et reproduit localement.**

### Scénario reproductible

```php
$user = User::factory()->make();
$user->assignRole('cme');
$user->assignRole('administrateur');
$user->save();
```

Résultat observé : `["administrateur", "cme"]`.

Pour un modèle non sauvegardé, `$current` reste vide à chaque appel. Spatie diffère chaque insertion jusqu'à l'événement `saved` : les deux affectations passent la vérification, puis sont enregistrées.

Le problème touche également le remplacement :

```php
$user = User::factory()->make();
$user->setRole(Role::Cme);
$user->setRole(Role::Direction);
$user->save();
```

Résultat observé : `["cme", "direction"]`.

### Impact

Violation de RG-ACC-06 et cumul des permissions, potentiellement administrateur. Les tests actuels utilisent des utilisateurs déjà persistés et ne détectent pas ce cas.

### Correctif minimal proposé

Refuser explicitement l'affectation sur un modèle non persisté, ou gérer un unique rôle en attente avec une sémantique de remplacement pour `setRole()`. Ajouter les deux tests de régression. Aucun correctif appliqué.

## 2. Contrôle du rôle unique non atomique

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Models/User.php:84`, lignes 84 à 90 de la tête examinée.
- **Qualification : défaut confirmé par simulation locale d'un entrelacement ; concurrence MySQL réelle non exécutée.**

### Scénario reproductible

Sur un utilisateur persisté sans rôle :

1. L'opération A vérifie les rôles actuels : aucun.
2. Avant son insertion, l'opération B attribue `administrateur`.
3. L'opération A poursuit l'attribution de `cme`.

Résultat reproduit : `["administrateur", "cme"]`.

La reproduction utilise un écouteur de requête pour exécuter la seconde affectation après la lecture des rôles par la première opération et avant son insertion. Elle démontre la fenêtre entre contrôle et écriture ; elle n'est pas un test de deux connexions MySQL concurrentes.

### Impact

Deux opérations concurrentes peuvent contourner RG-ACC-06. La vérification suivie de l'insertion laisse une fenêtre pendant laquelle l'état peut changer.

### Correctif minimal proposé

Sérialiser les affectations par une transaction avec verrou sur la ligne utilisateur, couvrant la lecture et l'écriture des rôles. Appliquer cette protection aux opérations de remplacement également. Ajouter un test d'entrelacement. Aucun correctif appliqué.

## 3. Un syncRoles() refusé supprime le rôle précédent

- **Sévérité : Majeur.**
- **Fichier et ligne :** `backend/app/Models/User.php:81`, surcharge `assignRole()`, en interaction avec `HasRoles::syncRoles()` de Spatie 8.5.0.
- **Qualification : constat confirmé et reproduit localement.**

### Scénario reproductible

```php
$user->setRole(Role::Entreprise);

try {
    $user->syncRoles(['cme', 'direction']);
} catch (LogicException) {
    // Affectation refusée.
}

$user->refresh()->getRoleNames(); // []
```

Spatie détache les rôles existants avant d'appeler la surcharge `assignRole()`. La vérification de cardinalité arrive après la suppression.

### Impact

Une opération refusée laisse le compte sans son rôle initial et lui retire ses droits. Aucun test actuel ne vérifie la conservation du rôle après cet échec.

### Correctif minimal proposé

Valider la cardinalité des rôles demandés avant tout détachement dans `syncRoles()`, puis effectuer le remplacement atomiquement. Ajouter une assertion vérifiant que le rôle initial reste présent après le refus. Aucun correctif appliqué.

## Autres points vérifiés

- Les 125 cellules correspondent au CDC 3.2 : aucun écart dans `Role::permissions()` ni dans la matrice littérale des tests. Les droits limités au périmètre sont interprétés comme autorisés à ce stade, conformément au périmètre validé.
- La matrice de test est indépendante de `Role::permissions()`.
- Les tableaux, objets Spatie Role, enums et identifiants contenant deux rôles sont correctement refusés lors d'un appel unique sur un utilisateur persisté.
- Le remplacement valide d'un rôle sur un modèle persisté fonctionne.
- `currentRole()` ne masque pas le scope Spatie : `User::role()` fonctionne.
- Seeder relancé : cinq rôles, vingt-cinq permissions et associations inchangées. Aucun compte créé, conformément à EXI-SEC-11.
- La suppression d'une permission obsolète retire aussi ses associations aux rôles et utilisateurs. Elle est volontaire et limitée au guard `web`, cohérent avec la configuration actuelle. Les invalidations du cache encadrent l'exécution normale.
- Aucune dépendance ajoutée au diff. Les exclusions de périmètre validées ne sont pas relevées comme manques.

## Validation et limites

Les reproductions ciblées ont été exécutées sous **PHP 8.5.12, Spatie 8.5.0 et SQLite en mémoire**, avec le code de la PR chargé en mémoire. Elles confirment les trois constats ci-dessus. La comparaison automatisée du CDC, des permissions du code et de la matrice des tests a vérifié 25 lignes et 125 cellules, sans écart.

La suite complète, Pint, Larastan et les contrôles frontend n'ont pas été relancés sur la branche cible : le répertoire est sur `develop`, et aucun checkout ni fichier temporaire n'a été créé pendant la revue. Aucun contrôle local sur MySQL 8 ou PHP 8.4 exact n'a été effectué.

Résultats CI communiqués par l'utilisateur, non revérifiés indépendamment dans GitHub :

- 137 tests réussis, 145 assertions, sur MySQL 8.
- Pint : OK.
- Larastan niveau 6 : aucune erreur.
- Build et lint frontend : OK.

Source primaire consultée pour le comportement du trait : [Spatie HasRoles, version 8.5.0](https://github.com/spatie/laravel-permission/blob/8.5.0/src/Traits/HasRoles.php). Le trait installé localement a également été examiné.

## Verdict

**À corriger avant fusion.**

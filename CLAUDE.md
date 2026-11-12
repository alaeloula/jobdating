# Instructions Claude — JobDating (navigateur)

## Mission
Implémenter **une seule tâche à la fois** à partir de `docs/tasks/CURRENT_TASK.md`. Le projet démarre sans code.

## Source de vérité (ordre)
1. CDC V2 `docs/reference/CAHIER_DES_CHARGES_V2.md` : règles et exigences.
2. Présentation `docs/reference/PRESENTATION_FONCTIONNELLE_FINALE.pdf` : parcours et maquettes.
3. Décisions explicitement validées `docs/DECISIONS.md`.
4. Architecture `docs/reference/ARCHITECTURE.md` (choix « proposés » à valider, voir ARCH-02) et standards approuvés.
5. Ordre des tâches : `docs/BACKLOG.md`.
En cas de contradiction ou ambiguïté : **signaler, ne pas inventer**.

## Stack cible issue du CDC
Laravel 12 / PHP 8.4 / MySQL 8 strict ; React 19 / TypeScript / Vite / Tailwind ; REST `/api/v1` ; Sanctum cookies + CSRF ; Spatie Permission ; Laravel Policies. Pas de Spring Boot ni PostgreSQL par défaut.

## Règles métier critiques
- Cinq rôles : Administrateur, CME, Direction, Entreprise, Apprenant ; un seul rôle par compte.
- Les droits sont appliqués **côté serveur** et par périmètre, jamais uniquement dans React.
- Un passage = une occurrence de rencontre. Le même couple apprenant/entreprise peut avoir plusieurs passages.
- Passage créé au démarrage de l'entretien, même sans évaluation ; tous les champs d'évaluation sont facultatifs.
- Après le jour J, une entreprise ne peut plus modifier ses passages ; elle ne voit que ses propres évaluations.
- Un apprenant ne voit jamais les évaluations/commentaires des entreprises.
- Statistiques et exports utilisent la même logique de filtres et de calcul.
- CV validés seulement ; stockage privé, consultation autorisée, journalisation.

## Mode de livraison navigateur
1. Reformuler le périmètre et les fichiers nécessaires.
2. Proposer un plan court avant de coder.
3. Fournir les fichiers complets avec leurs chemins **ou** un patch applicable ; ne jamais utiliser de pseudo-code pour une tâche de code demandée.
4. Inclure migrations, validations, policies et tests pertinents.
5. Décrire les commandes de test à exécuter localement ; ne jamais prétendre les avoir exécutées si ce n'est pas le cas.
6. Signaler risques, hypothèses et modifications proposées à `PROGRESS.md`.
7. Ne pas ajouter de dépendances, fonctionnalités ou refactorings hors périmètre sans accord.

## Gestion des tokens
Utiliser `CURRENT_TASK.md`, la user story, les extraits pertinents des références et les fichiers concernés. Demander le contexte manquant plutôt que réclamer l'ensemble du dépôt.

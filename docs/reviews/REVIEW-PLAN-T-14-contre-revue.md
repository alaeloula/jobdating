# Contre-revue du plan T-14 — Événements, participants et emplacements

- **Date :** 23/11/2026.
- **Documents :** `docs/tasks/PLAN-T-14.md`, version 1.3 ; `docs/tasks/CURRENT_TASK.md` ; décisions du 23/11 dans `docs/DECISIONS.md`.
- **État examiné :** branche `develop`, HEAD `1b05242254e94e4843dd414d22de80621cec6f29`, documents non commités. La branche de développement T-14 reste à créer.
- **Revue précédente conservée :** `docs/reviews/REVIEW-PLAN-T-14.md`.
- **Livrable unique :** ce rapport. Aucun code ni autre document modifié ; aucun commit ni push.

## Périmètre

Contre-revue limitée aux cinq constats précédents, aux décisions nouvelles 10 à 14 et à leurs effets de bord. Lecture du prompt, d’`AGENTS.md`, de `CLAUDE.md`, du plan v1.3, de la tâche courante, du rapport initial et du diff documentaire avec `develop`.

Les services existants `LearnerService`, `LearnerImportRunner`, `CompanyService`, le contrôleur entreprises, le journal d’audit, les migrations apprenants, les tests entreprises et les contrats frontend de la fiche ont servi de références. Les règles du CDC concernées et les décisions écrites ont été rapprochées du plan. Les décisions 1 à 14 sont appliquées, sans demander le retour du sélecteur entreprise ou de son historique.

Exclusions respectées : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`. Aucun fichier réel ni fichier du dossier Téléchargements utilisé. Les erreurs E-01 à E-06 ne sont pas rouvertes.

## Rejeu des cinq constats

Les résultats portent sur le **traitement dans le plan**, pas sur une implémentation exécutée.

| # | Statut | Preuve dans le plan v1.3 |
|---|---|---|
| 1 — Inscription massive et verrous existants | **Partiel, précision mineure restante** | §6, lignes 110–122 : verrou exclusif avant toute lecture, abandon de l’`INSERT … SELECT`, lecture simple puis paquets, transaction rejouée trois fois. §10, ligne 179 : essais avec création, import, `update`, `bulkAddCompetence`, `bulkDeactivate`. L’ordre des identifiants insérés reste à fixer pour les verrous de clés étrangères ; voir R1. |
| 2 — Choix de l’événement entreprise | **Corrigé par décision métier** | Décision 10 ; §5, lignes 95–96 ; §7, ligne 141 ; §9, ligne 157 ; §10, ligne 172. Le courant est le seul événement consultable par l’entreprise, sans paramètre permettant d’en choisir un autre. La participation destinée à la fiche est explicitement définie. Les états futur non courant, sans participation et sans courant sont couverts. |
| 3 — Transaction entreprise, participation et logo | **Corrigé** | §5, ligne 94 : transaction propriétaire dans `CompanyService::create`, sans transaction englobante ; événement verrouillé et éligibilité relue avant les écritures ; nettoyage du nouveau logo en cas d’erreur. §10, lignes 170–171 : échecs après création de la fiche et concurrence avec archivage/suppression. |
| 4 — Contrat `EventUsage` et relais T-30 | **Corrigé** | §4, ligne 77 : comptages par événement et couple événement/participant, chaque occurrence comptée même sans évaluation. §6, lignes 126–127 : retrait/suppression/date sous verrou exclusif ; création de passage sous verrou partagé avec relecture après acquisition. §10, ligne 174 : distinction E1/E2. §14, ligne 212 : relais obligatoire à documenter dans `PROGRESS.md`. |
| 5 — Démonstration locale | **Corrigé** | §5, ligne 89 : même inscription idempotente pour l’apprenant démo, retrait conservé ; entreprise démo ajoutée par l’administrateur. §10, ligne 176 : relance du seeder testée ; §11, ligne 188 : seeder inclus dans les fichiers concernés. |

Les deux questions précédentes sont tranchées : courant uniquement pour l’entreprise ; état d’impression porté par la version des identifiants, toutes éditions confondues. Aucun état d’impression par participation n’est demandé.

## Effets de bord vérifiés

### Lecture cohérente, reprises et verrous

Le §6 exige désormais de prendre le verrou exclusif **avant toute lecture de la transaction**. Sous `REPEATABLE READ`, la première lecture cohérente fixe l’instantané ; une lecture verrouillée du sémaphore n’est pas cette lecture cohérente. La lecture simple effectuée ensuite peut donc inclure les créations/réactivations validées avant l’obtention du verrou. Cette conclusion suppose bien la transaction entière décrite, et non un savepoint dans une transaction dont l’instantané aurait déjà été établi. Chaque reprise doit recommencer cette séquence avec un nouvel instantané ; un essai avec un instantané antérieur ne validerait pas le contrat. [MySQL — lectures cohérentes](https://dev.mysql.com/doc/refman/8.0/en/innodb-consistent-read.html).

L’ordre ajouté `event_locks → événement → classes → comptes → fiches` est compatible avec les chemins appelants identifiés, à condition d’acquérir les nouveaux verrous **avant** les verrous existants, et non dans un appel d’inscription placé en fin de transaction. Le plan l’exige au §6. L’import doit répéter cette acquisition à chaque reprise ; sa lecture préparatoire des classes hors transaction n’établit pas l’instantané de la transaction d’écriture. Le hachage reste hors de cette transaction.

Les opérations qui ne rendent personne actif peuvent conserver leur ordre actuel. `bulkDeactivate` verrouille les comptes, sans demander le sémaphore ni l’événement. Une désactivation après la lecture massive peut laisser une participation conservée mais invisible : c’est cohérent avec le filtre sur le compte actif, et la réactivation sera sérialisée par le sémaphore.

L’insertion de participations ne verrouille pas automatiquement `users` en remontant toute la chaîne des clés étrangères. Elle peut cependant prendre des verrous partagés sur les lignes `learners` directement référencées. Ce point laisse le résidu R1 ci-dessous.

### Création d’entreprise, logo et fiche

La transaction propriétaire annoncée réunit les écritures et leurs audits, sous le verrou de l’événement. Archivage et suppression utilisent ce même verrou ; les tests annoncés pourront ainsi vérifier qu’une création refusée ne laisse rien derrière elle. Le logo reste préparé avant la transaction, puis supprimé si l’opération échoue. Aucun nouvel étage de transaction englobante n’est requis : la limite connue des deux niveaux englobants n’est donc pas aggravée par le parcours retenu.

Le `event_id` facultatif de réinitialisation est un contexte **administratif**, distinct de la route personnelle de l’entreprise. Il doit appartenir à la participation de l’entreprise visée ; le plan annonce le refus 422 et le test interentreprises. Sans paramètre, le repli est explicitement défini. Cette validation doit précéder l’effet de réinitialisation : un refus de contexte ne doit pas modifier le mot de passe ou sa version.

L’ajout de ce contexte ne demande ni stockage supplémentaire du mot de passe, ni récupération d’un ancien secret. La réponse existante est déjà `no-store, private` et le journal exclut les mots de passe. Ces propriétés doivent être conservées pendant l’adaptation ; aucun défaut nouveau n’est établi par le plan. Le choix d’impression global est cohérent avec la décision 11.

### Année scolaire et nom complet

Le calcul prévu est cohérent avec les bornes suivantes, à utiliser comme attentes des futurs tests :

| Date de l’événement | Année scolaire attendue |
|---|---|
| 31/08/2026 | `2025/2026` |
| 01/09/2026 | `2026/2027` |
| 31/08/2027 | `2026/2027` |
| 01/09/2027 | `2027/2028` |

Le calcul serveur depuis une date, sa mise à jour lorsque cette date change, l’index unique incluant les archivés et la traduction d’une collision concurrente en 422 sont annoncés aux §3–4. Le nom complet centralisé doit alimenter les réponses et affichages, y compris options, encadré et fiche ; l’aperçu du formulaire et le refus d’un suffixe d’année déjà saisi évitent le doublon de suffixe.

Le changement d’année ne dispense pas de RG-EVT-04 ni du refus de modifier une date ayant des passages. Les tests annoncés couvrent les bornes et l’unicité ; la revue du code devra vérifier aussi la collision au dernier moment, à la création et lors d’un changement de date, avec un message 422 lié à l’année.

Pour T-16, l’écriture d’éditions historiques reste une voie distincte du formulaire courant. Le risque de plusieurs événements historiques dans une même année est explicitement déclaré au §13 : il nécessitera un arbitrage avant leur reprise. Ce point ne justifie ni de retirer l’unicité validée, ni d’examiner maintenant des données réelles.

### Vue entreprise et tâches suivantes

Les cas demandés ont un résultat défini : participation uniquement à un événement futur non courant → état vide explicatif ; retrait du statut courant → état vide ; ancien événement toujours courant → reste visible tant qu’il demeure courant (§13, ligne 204). Le choix diffère volontairement du CDC et n’est pas rouvert.

Le §14 transmet la limitation au courant aux écrans et exports **de l’entreprise** pour T-23, T-30 et T-33. L’administration et ses statistiques conservent les données historiques. Les futurs contrôles serveur devront appliquer les deux conditions ensemble : événement courant et participation de l’entreprise, y compris pour les accès directs aux ressources. Le plan pose cette règle commune ; aucun compteur, export ou passage supplémentaire n’est à réaliser dans T-14.

## Constat résiduel

### R1. Mineur — Préciser l’ordre des insertions pour les verrous de clés étrangères

**Référence :** `docs/tasks/PLAN-T-14.md:118`, §6, et affirmation d’absence de cycle ligne 120 ; référence existante : `backend/app/Services/Learners/LearnerService.php:454`.

**Nature :** omission confirmée dans le plan ; risque d’interblocage hypothétique, non reproduit. Il s’agit du résidu du constat 1, pas d’un sixième défaut métier.

**Problème :** abandonner l’`INSERT … SELECT` supprime les verrous de lecture concernés, mais pas les verrous pris pour vérifier les clés étrangères lors des insertions. MySQL peut prendre un verrou partagé sur chaque fiche apprenant référencée. L’absence de verrou sur `users` ne suffit donc pas à affirmer l’absence de cycle avec une opération qui verrouille plusieurs fiches. [MySQL — verrous et clés étrangères](https://dev.mysql.com/doc/refman/8.0/en/innodb-locks-set.html).

**Scénario à tester après implémentation :** deux apprenants L10 et L20 sont sélectionnés. Si l’insertion massive les traite dans l’ordre L20 puis L10, elle peut conserver un verrou partagé sur L20. Simultanément, `bulkAddCompetence`, dont la requête ordonne les fiches par identifiant, peut détenir le verrou exclusif de L10 puis attendre L20. L’insertion attend ensuite L10 : un cycle reste possible. L’entrelacement et l’ordre physique devront être vérifiés avec les requêtes réellement retenues ; aucun ordre des identifiants ou des paquets n’est actuellement spécifié dans le plan.

**Impact :** reprise inutile d’une opération concurrente ; après épuisement des trois tentatives prévues, refus 409 et nouvel essai manuel. L’atomicité et l’absence de doublon dans l’audit sont déjà couvertes par la reprise complète annoncée. Cela justifie une précision mineure, pas le rétablissement de la gravité initiale.

**Correction minimale dans le plan :** imposer les identifiants de fiches en ordre croissant, conserver cet ordre dans tous les paquets et dans les valeurs insérées, puis vérifier les acquisitions de verrous avec les requêtes MySQL retenues. Corriger la phrase « donc aucun cycle » en indiquant que les verrous de clés étrangères restent possibles et que la reprise demeure nécessaire. Le test `bulkAddCompetence` contre désignation doit organiser un chevauchement réel sur les mêmes fiches et contrôler les effets après reprise, pas seulement lancer deux appels qui finissent successivement. La recommandation d’un ordre constant est cohérente avec [MySQL — prévention et reprise des interblocages](https://dev.mysql.com/doc/refman/8.0/en/innodb-deadlocks-handling.html).

**Fréquence et suites :** concurrence ponctuelle, fréquence non mesurée. Ce n’est pas une fuite ni une corruption établie. Le protocole concerne cependant les opérations en masse existantes et servira de référence ultérieure ; ne pas le classer comme défaut reproduit sans impact sur les tâches suivantes dans `ERREURS_A_CORRIGER.md`. La correction documentaire est courte et peut précéder le code.

Aucun autre constat nouveau confirmé dans le périmètre de cette contre-revue. Aucune nouvelle décision métier bloquante à demander au responsable technique.

## Limites et validation

Contrôles effectués : lecture du plan v1.3 et des changements documentaires, rapprochement des cinq constats et des décisions, inspection statique ciblée des services et contrats existants, consultation de la documentation officielle MySQL sur les instantanés, les verrous de clés étrangères et les interblocages.

**Aucun test applicatif, lint, build, migration ou banc de concurrence exécuté.** Aucun code T-14 n’existe ; les cas décrits et les résultats attendus ne sont pas des résultats de tests locaux. Aucun résultat d’une ancienne suite ne prouve la correction future de T-14. La revue du code devra disposer de contrôles locaux communiqués, conformément à `AGENTS.md`, et vérifier les verrous avec MySQL, pas avec SQLite.

Seul ce rapport est créé. Le rapport initial et les documents de travail restent conservés.

## Verdict

**Plan validé après corrections mineures.**

Les décisions et les corrections structurantes sont cohérentes. Compléter le §6 sur l’ordre des identifiants et des paquets, les verrous indirects de clés étrangères et le chevauchement du test de concurrence. Aucune autre modification métier n’est demandée avant développement.

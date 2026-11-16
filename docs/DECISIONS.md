# Décisions et points ouverts

## Décisions métier

Les décisions métier validées sont dans l'**annexe C du cahier des charges** (`reference/CAHIER_DES_CHARGES_V2.md`). Elles sont déjà intégrées dans le corps du document. Ne pas les recopier ici : l'annexe C fait foi.

### Décisions validées après le CDC V2, à reporter dans le CDC

Ces décisions s'écartent du CDC V2. Elles priment sur les passages cités jusqu'à la mise à jour du CDC.

| Date | Décision | Écart avec le CDC V2 | Source |
|---|---|---|---|
| 14/11/2026 | **Première connexion de l'apprenant** : le changement du mot de passe généré n'est pas obligatoire. L'apprenant accède directement à son espace ; il peut changer son mot de passe depuis son profil (AUTH-03). | RG-AUTH-13, AUTH-01 (exceptions), AUTH-02, ADM-03 (« changement demandé à la connexion suivante »), ADM-04, profil Apprenant (3.1) | Responsable technique |
| 14/11/2026 | **Mot de passe généré** : envoyé par e-mail **et** affiché à l'administrateur **une seule fois**, à la création ou à la réinitialisation. Stocké haché, il ne peut pas être relu ensuite. Pour un import, l'affichage est remplacé par le fichier des mots de passe (décision suivante). | ADM-03 (création manuelle : e-mail seulement) | Responsable technique |
| 14/11/2026 | **Réinitialisation par l'administrateur pour tous les comptes** : apprenant, entreprise, CME, Direction et administrateur, y compris son propre compte. Nouveau mot de passe généré, affiché une fois à l'administrateur et envoyé en clair par e-mail (sauf entreprise, voir ci-dessous). L'ancien mot de passe est refusé, toutes les sessions du compte sont fermées (RG-AUTH-12), l'action est journalisée. La création des comptes CME, Direction et administrateur reste faite par lien d'activation (AUTH-02). L'envoi du mot de passe en clair par e-mail est un risque accepté. | AUTH-02 (« aucun mot de passe connu d'un tiers »), RG-AUTH-08, AUTH-03 (acteurs) | Responsable technique |
| 14/11/2026 | **Fichier des mots de passe d'un import d'apprenants** : format Excel (Prénom, Nom, E-mail, Classe, Mot de passe). Téléchargeable **plusieurs fois** jusqu'à sa suppression manuelle par l'administrateur ; aucune suppression automatique. Jusque-là, les mots de passe sont conservés chiffrés sur le serveur, jamais en clair. Chaque téléchargement et la suppression sont journalisés. | ADM-04 étape 5, RG-IMP-05 et critère d'acceptation (« une seule fois ») | Responsable technique |
| 14/11/2026 | **Entreprises** : aucun e-mail ni lien d'activation, y compris à l'import. Les identifiants sont remis manuellement (fiche imprimable). | ADM-04, import des entreprises (« reçoivent leur lien d'activation ») ; confirme RG-ENTR-06 | Responsable technique |
| 16/11/2026 | **Compte entreprise partagé** : plusieurs sessions simultanées sont autorisées avec le même compte ; une connexion ne ferme pas les autres sessions. Seules la déconnexion (session concernée), la désactivation du compte et la limite de 12 h y mettent fin. | Précise PC-05 et RG-AUTH-12 (fermeture des autres sessions réservée au changement ou à la réinitialisation du mot de passe) | Responsable technique |

## Décisions techniques

| Date | Décision | Source |
|---|---|---|
| 12/11/2026 | Organisation du dépôt : `backend/` (Laravel) et `frontend/` (React), documentation dans `docs/` | Initialisation, ARCHITECTURE 3 |
| 12/11/2026 | Fuseau horaire `Africa/Casablanca` : la fin du jour de l'événement est minuit, heure du Maroc | RG-PAS-10 |
| 12/11/2026 | Sessions en base de données, durée 720 minutes (12 h). Le pilote `database` permet de fermer toutes les sessions d'un utilisateur | RG-AUTH-02, RG-AUTH-12, RG-ACC-05 |
| 12/11/2026 | File d'attente et cache en base de données (pas de Redis à exploiter) | ARCHITECTURE 1.2 |
| 12/11/2026 | Tests backend sur MySQL (`jobdating_test`), pas sur SQLite, pour respecter le mode strict et les calculs des statistiques | CDC 9.4 |
| 12/11/2026 | Locale `fr` ; traductions françaises des messages de validation à ajouter | CDC 6.3 |
| 12/11/2026 | TypeScript en mode strict | CDC 9.2 |
| 12/11/2026 | En développement, Vite redirige `/api`, `/sanctum` et `/up` vers Laravel (même origine, condition des cookies Sanctum) | CDC 9.5, PC-36 |
| 12/11/2026 | Un seul dépôt GitHub (`alaeloula/jobdating`) avec `backend/` et `frontend/` ; workflow `main` (production) / `develop` (intégration, branche par défaut) / `feature/T-xx-nom` ; `main` et `develop` protégées (pull request obligatoire, pas de push direct ni de force push) | Choix du responsable technique |
| 12/11/2026 | Développement local : Herd pour PHP 8.4 ; Docker (`docker-compose.yml`) pour MySQL 8 (port 3307) et Mailpit | Choix du responsable technique |
| 12/11/2026 | Production : conteneurs Docker sur le serveur YouCode / 1337 qui héberge l'intranet, pour isoler JobDating des autres applications ; Dockerfiles à produire avec T-01 | Choix du responsable technique, détails en ARCH-01 |
| 12/11/2026 | Lint frontend avec oxlint (installé à l'initialisation) au lieu d'ESLint + Prettier proposés dans ARCHITECTURE 1.2 | Initialisation |

## Points ouverts

| ID | Point | Bloque | À qui |
|---|---|---|---|
| PC-26 | API de la plateforme CME : documentation, environnement de test, identifiant commun, authentification | CV-02 uniquement | CME, TECH |
| PC-43 | Modèle de l'export Excel de l'équipe CME | Mise en forme de l'export uniquement | PO, CME |
| ARCH-01 | Serveur YouCode / 1337 (celui de l'intranet) : (1) Docker et Compose disponibles ? (2) adresse de l'application, par ex. `jobdating.youcode.ma` (même domaine parent requis par Sanctum) ; (3) proxy et HTTPS devant les applications, WebSockets pour Reverb ; (4) MySQL existant (version ?) ou conteneur dédié ; (5) sauvegardes et stockage privé des CV ; (6) mode de déploiement (SSH, GitHub, administrateur) | Mise en ligne T-01 | TECH, administrateurs du serveur |
| ARCH-02 | Validation des choix « proposés » de ARCHITECTURE 1.2, dont PHPUnit (installé) ou Pest (proposé) | Tâches de développement | TECH |
| REP-01 | Rapprochement des apprenants de l'ancienne application, qui n'a pas de CNIE (proposition : par e-mail professionnel) | Reprise des passages | PO, TECH |
| REP-02 | Anciennes rencontres `scheduled`, `in_progress`, `cancelled` : reprises ou ignorées | Reprise | PO |
| REP-03 | Champ de notes historiques sur 5 à ajouter au modèle `evaluations` (CDC 8.8) | Modèle de données | TECH |
| REP-04 | Calendrier : entreprises reprises avant l'événement, passages historiques après | Planning | PO, TECH |
| ENV-01 | PHP 8.4 exact et MySQL 8 à valider en local (PHP 8.5 utilisé à l'initialisation) | Tests | TECH |

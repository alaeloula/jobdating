# Prompt de revue Codex — T-06 (JD-13) : journal d'audit

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating (étape 3 : US → développement Claude → **revue** → corrections → validation). **Tu ne modifies aucun fichier de code, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier de test dans le dépôt.** Ton seul livrable est `docs/reviews/REVIEW-T-06-journal-audit.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `feature/T-06-journal-audit`, base `develop` (`71968df`). **Travail non commité** : examine `git diff develop` **et** les fichiers non suivis (`git status --short`).
- Exclusions (fichiers locaux) : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.

## 2. Référence fonctionnelle

Pas de fichier `US-T-06.md`. Références, dans cet ordre :
1. `docs/tasks/CURRENT_TASK.md` (périmètre validé, hors périmètre) ;
2. CDC V2 : **TRA-01**, **EXI-DATA-02** (conservation 1 an), **EXI-LOG-01/02**, **3.2** (journal réservé à l'administrateur), **10.1** ;
3. `docs/DECISIONS.md`, décisions du **16/11/2026** sur le journal : connexions journalisées (réussites, refus avec motif, blocage une fois par blocage, déconnexions) ; écran inclus, **export reporté à T-61** ; **purge limitée au journal d'audit** ; double protection code + déclencheurs MySQL ; option `--log-bin-trust-function-creators=1` du conteneur MySQL ;
4. `docs/PROGRESS.md`, entrée « 2026-11-16 — T-06 » ;
5. maquette `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-13-journal-audit.png` (filtre « Connexions » ajouté, bouton « Exporter » volontairement absent).

Ne signale pas comme oubli : la journalisation des passages, évaluations, CV, imports et exports (branchée dans leurs tâches), le bouton d'export, l'exécution du planificateur sur le serveur (T-01).

## 3. Points à vérifier en priorité

**Intégrité du journal**
- Peut-on modifier ou supprimer une ligne par un chemin quelconque : modèle (`update`, `delete`, `save` après modification, `forceDelete`, `upsert`, `insertOrIgnore`…), requête directe, `truncate`, migration `down()` ? Les déclencheurs couvrent-ils tous les cas (UPDATE, DELETE) ? Un `TRUNCATE` les contourne-t-il, et est-ce un risque réel ?
- La tolérance d'un jour du déclencheur de suppression (`NOW() - INTERVAL 1 YEAR + INTERVAL 1 DAY`) et l'écart de fuseau PHP (Africa/Casablanca) / MySQL : la purge peut-elle supprimer une ligne de moins d'un an, ou échouer sur une ligne de plus d'un an ?
- La purge (`audit:purge`, `AuditLogger::purge()`) ne touche-t-elle vraiment **que** `audit_logs` ?
- Absence de clé étrangère vers `users` : conséquences (comptes supprimés définitivement, cohérence `user_id`) ?

**Contenu journalisé**
- Aucun mot de passe, jeton ou secret ne peut-il entrer dans `old_values` / `new_values` (clés imbriquées, casse, variantes comme `pwd`, `passwd`, `mot_de_passe`) ? La CNIE est-elle masquée dans tous les cas (valeur non textuelle, clé imbriquée) ?
- `UserAuditObserver` : événements manquants ou en double (création + rôle, `saveQuietly`, `forceFill`, mises à jour de masse `User::query()->update()` qui contournent les observateurs) ? Le changement de mot de passe est-il bien noté sans valeur ?
- Rôles : `User::syncRoles()` / `assignRole()` journalisent-ils exactement une ligne par changement réel (drapeau `replacingRoles`) ? Comportement en cas d'exception au milieu de la transaction (la ligne d'audit est-elle annulée avec le changement) ?
- Connexions : motif et identifiant saisi notés pour chaque refus ; le blocage 429 n'est noté qu'une fois par blocage (`Cache::add`) : contournable, ou au contraire risque de remplir le journal (attaque sur de nombreux identifiants) ?
- Auteur et IP : corrects pour une requête HTTP, une commande, un seeder ? Comportement derrière un proxy (à signaler pour T-01, pas comme bug de T-06).

**API et droits**
- `GET /api/v1/audit-logs` et `/audit-logs/filters` réservés à `audit.view` (administrateur) ; 401 sans session, 403 pour les autres rôles.
- Validation des filtres (catégorie, auteur, dates, élément, pagination bornée à 100) ; filtres combinés ; tri stable ; performance des index.
- Aucune donnée excessive renvoyée (le journal peut contenir des identifiants saisis : acceptable pour l'administrateur ?).

**Frontend**
- Écran accessible seulement à l'administrateur ; lien visible seulement avec `audit.view` ; filtres, détail avant / après, pagination ; affichage des dates (fuseau Africa/Casablanca) ; accessibilité (boutons, `aria-pressed`, `aria-expanded`).

**Tests**
- Les tests échoueraient-ils sur un code fautif (faux positifs) ? Cas manquants importants (cite-les, sans les écrire).

## 4. Contrôles à exécuter

MySQL Docker démarré **avec l'option du nouveau `docker-compose.yml`** (`docker compose up -d mysql`), tests sur `jobdating_test`, jamais SQLite.

```powershell
# backend/
vendor/bin/pint --test
vendor/bin/phpstan analyse
php artisan test

# frontend/
npm.cmd run lint
npm.cmd run test
npm.cmd run build
```

Résultats obtenus par Claude le 16/11/2026, à confirmer ou infirmer : Pint OK, PHPStan sans erreur, tests du journal (23) et de l'authentification (36) réussis ; suite backend complète : résultat communiqué par le responsable technique ; frontend : lint OK, 20 tests, compilation OK. Vérifié dans le navigateur avec le vrai backend : lien « Journal d'audit » pour l'administrateur, connexion réussie journalisée, détail avec IP, filtre par catégorie. Environnement : PHP 8.5.12 (ENV-01 ouvert), MySQL 8.0, Node 24. Si tu ne peux pas exécuter un contrôle, dis-le ; ne prétends jamais l'avoir exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-T-06-journal-audit.md`

Même structure que `docs/reviews/REVIEW-T-04-connexion.md` : en-tête ; références et périmètre ; constats numérotés du plus grave au moins grave (sévérité Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal sans hors périmètre) ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites (contrôles réellement exécutés) ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

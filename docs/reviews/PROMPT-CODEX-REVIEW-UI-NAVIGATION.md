# Prompt de revue Codex — Navigation de l'interface (`chore/ui-navigation`)

À copier tel quel dans Codex, lancé à la racine du dépôt `C:\Users\Youcode\Desktop\JobDating Starter Pack`.

---

Tu es l'**auditeur technique indépendant** du projet JobDating. Tu fais la **revue du code** d'un changement d'interface. **Tu ne modifies aucun fichier, tu ne commites rien, tu ne pousses rien, tu ne crées aucun fichier dans le dépôt.** Seul livrable : `docs/reviews/REVIEW-UI-NAVIGATION.md`. Applique `AGENTS.md` et `CLAUDE.md`. Réponds en français, simplement.

## 1. Ce qui est à revoir

- Branche `chore/ui-navigation`, base `develop` (`552c236`), **non commitée** : `git diff develop` **et** fichiers non suivis.
- Exclusions : `.claude/`, `.vscode/settings.json`, `mysql.session.sql`, `backend/.env`.
- **Frontend seulement** : aucun changement de backend, de droits ni d'API.
- Fichiers : `frontend/src/layout/AppLayout.tsx`, `frontend/src/layout/icons.tsx` (nouveau), `frontend/src/layout/navigation.test.tsx` (nouveau), `frontend/src/pages/CompaniesPage.tsx` (colonne de droite), tests adaptés (`accounts`, `auth`, `companies`, `passwords`), `docs/PROGRESS.md` (entrée « Navigation de l'interface »).

## 2. Références et décisions du responsable technique (19/11)

- Entrée « 2026-11-19 — Navigation de l'interface » de `docs/PROGRESS.md` : décisions, hypothèses et hors périmètre. **Ne remets pas en cause** : suivre les maquettes plutôt que des menus groupés ; seulement les écrans déjà développés ; icônes en SVG sans dépendance ; libellés des maquettes ; apprenant avec onglets en bas sur toutes les tailles ; « Mon profil » et déconnexion de l'apprenant dans un menu du compte.
- Maquettes : `C:\Users\Youcode\Desktop\jobdating\maquettes\admin-01-tableau-de-bord.png`, `entreprise-01-accueil.png`, `entreprise-ordi-01-accueil.png`, `apprenant-01-ma-journee.png`.
- CDC V2 : matrice des droits 3.2, AUTH-01 (accueil par rôle). Les droits restent appliqués **côté serveur** ; la navigation ne fait que guider.

## 3. Points à vérifier

- **Liens par rôle** : pour les 5 rôles, chaque lien correspond à une route existante de `AppRoutes.tsx` accessible à ce rôle (aucun lien vers une page refusée, aucun écran accessible sans lien), et aux permissions réelles de `backend/app/Enums/Role.php`.
- **Mobile** : le menu ☰ (ouverture, fermeture par un lien, « Fermer le menu », Échap, clic à côté) ; la barre fermée ne doit être ni visible, ni atteignable au clavier, ni lue par un lecteur d'écran (`max-md:invisible`) ; aucun défilement horizontal à 375 px.
- **Entreprise** : une seule navigation (barre latérale sur ordinateur, onglets sur mobile) ; deux boutons « Se déconnecter » (un visible par taille d'écran) : risque pour l'accessibilité ou les tests ?
- **Apprenant** : menu du compte (`aria-expanded`, `aria-controls`, Échap, fermeture au changement de page).
- **Déconnexion** : message d'échec et état « Déconnexion… » conservés dans les trois mises en page ; messages `flash` toujours affichés.
- **États d'erreur et enchaînements** : vérifie les enchaînements « action → erreur → l'utilisateur corrige → nouvel essai » (par exemple déconnexion qui échoue puis nouvel essai ; menu ouvert puis changement de page) : l'état affiché revient-il bien à la normale ?
- **Accessibilité** : rôles et noms accessibles, focus visible, contraste, lien actif (`aria-current`), icônes décoratives masquées.
- **`CompaniesPage`** : la colonne de droite n'apparaît qu'avec un formulaire ou une fiche ; aucune régression de T-11 (fiche de connexion, formulaires).
- **Tests** : faux positifs (CSS ignoré par le DOM simulé), cas manquants importants.
- **Signale aussi tout défaut évident** que tu remarques dans les fichiers modifiés, même hors de cette liste.

## 4. Contrôles

Résultats de Claude (19/11/2026), **à citer comme communiqués, non rejoués** :
- frontend : lint réussi, **70 tests réussis** (dont 10 de navigation), build réussi ;
- navigateur (administrateur de démonstration) : barre latérale à 1 280 px, menu ☰ à 766 px ; espaces entreprise et apprenant **non vérifiés dans le navigateur** ;
- backend non modifié : suite non relancée.

Tu es libre d'exécuter les contrôles ciblés que tu juges utiles (`npm run lint`, `npx vitest run`, `npm run build` dans `frontend/`). Ne prétends jamais avoir exécuté un contrôle que tu n'as pas exécuté.

## 5. Format du rapport `docs/reviews/REVIEW-UI-NAVIGATION.md`

En-tête ; périmètre ; constats numérotés du plus grave au moins grave (Bloquant / Majeur / Mineur / Suggestion, fichier et ligne, bug confirmé ou risque hypothétique, scénario reproductible, impact, correctif minimal ; indique si un constat est rare et sans impact sur les tâches suivantes) ; points vérifiés sans remarque ; questions au responsable technique ; validation et limites ; **verdict** (« À fusionner », « À fusionner après corrections mineures », « À corriger avant fusion »).

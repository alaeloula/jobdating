-- Exécuté une seule fois, à la création du volume MySQL (développement local uniquement).
-- Tests de concurrence (T-14, contre-revue Codex R1) : ils vérifient qu'une connexion attend
-- réellement un verrou détenu par une autre. Lecture seule de deux tables techniques de MySQL,
-- sans accès aux données de l'application. La CI se connecte en root et n'en a pas besoin.
-- Volume déjà créé : appliquer une fois ces deux lignes en root (voir docs/PROGRESS.md, T-14).
GRANT SELECT ON performance_schema.data_lock_waits TO 'jobdating'@'%';
GRANT SELECT ON performance_schema.threads TO 'jobdating'@'%';

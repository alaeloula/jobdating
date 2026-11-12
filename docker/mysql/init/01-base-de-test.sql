-- Exécuté une seule fois, à la création du volume MySQL.
-- Base utilisée par phpunit.xml (tests sur MySQL, docs/DECISIONS.md).
CREATE DATABASE IF NOT EXISTS jobdating_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON jobdating_test.* TO 'jobdating'@'%';
FLUSH PRIVILEGES;

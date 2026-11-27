<?php

namespace App\Services\Cv;

/**
 * Contrôle de STRUCTURE d'un PDF, sans dépendance (RG-CV-05 ; plan T-20 v1.3, § 4, décision 9 révisée
 * le 27/11).
 *
 * 1. Reconnaissance : signature `%PDF-` en tête.
 * 2. Structure : `%%EOF` et `startxref` en fin de fichier, table `xref` ou flux de références, au
 *    moins un objet ; une page lisible en clair, SAUF si le fichier range ses objets dans des flux
 *    compressés (`/ObjStm`) : la page n'y est pas lisible et n'est pas exigée.
 *
 * Le contenu actif (JavaScript, lancement de programme…) n'est PAS recherché : une recherche « faite
 * main » restait contournable et refusait des PDF valides (contre-revue Codex T-20, N1 et N2). Les CV
 * ne sont déposés que par l'administrateur et l'équipe CME ; la protection est assurée à la
 * consultation (T-21 : PDF servi isolé, jamais exécuté par JobDating). Aucune garantie antivirus.
 */
final class PdfInspector
{
    private const WINDOW = 1024;

    /** Motif du refus, ou null si le fichier est accepté. Le motif sert aux tests, jamais à l'utilisateur. */
    public static function problem(string $bytes): ?string
    {
        if (strlen($bytes) < 16) {
            return 'trop_court';
        }
        if (preg_match('/%PDF-(1\.[0-7]|2\.0)/', substr($bytes, 0, self::WINDOW)) !== 1) {
            return 'signature';
        }
        $tail = substr($bytes, -self::WINDOW);
        if (! str_contains($tail, '%%EOF')) {
            return 'fin_de_fichier';
        }
        if (preg_match('/startxref\s+\d+/', $tail) !== 1) {
            return 'startxref';
        }
        if (preg_match('/(?:^|[\s>])xref\s/', $bytes) !== 1 && preg_match('#/Type\s*/XRef(?![A-Za-z0-9])#', $bytes) !== 1) {
            return 'references';
        }
        if (preg_match('/\d+\s+\d+\s+obj(?![A-Za-z])/', $bytes) !== 1 || ! str_contains($bytes, 'endobj')) {
            return 'objets';
        }
        $compressed = preg_match('#/ObjStm(?![A-Za-z0-9])#', $bytes) === 1;
        if (! $compressed
            && preg_match('#/Type\s*/Page(?![A-Za-z0-9])#', $bytes) !== 1
            && preg_match('#/Count\s+[1-9]#', $bytes) !== 1) {
            return 'page';
        }

        return null;
    }
}

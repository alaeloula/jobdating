<?php

namespace App\Services\Imports;

use App\Support\PrivateTemp;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateIntervalCell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Options as XlsxOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Lecture du fichier exporté de YouCode School Admin (ADM-04, plan T-13 v2, §1), en CSV ou XLSX.
 *
 * Seules les colonnes UTILES sont copiées ; les colonnes ignorées (ID, nom complet, e-mail personnel,
 * ville, date de naissance) ne sont lues dans aucune structure conservée (EXI-DATA-03).
 */
final class YouCodeFileReader
{
    public const MAX_ROWS = 1000;

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public const MAX_SHARED_STRINGS = 50000;

    /** Colonnes utiles : clé interne => en-tête du fichier. */
    public const COLUMNS = [
        'first_name' => 'Prénom',
        'last_name' => 'Nom',
        'email' => 'E-mail Professionnel',
        'class' => 'Classe',
        'cnie' => 'CNIE',
        'status' => 'Statut',
        'phone' => 'Téléphone',
    ];

    public const REQUIRED = ['first_name', 'last_name', 'email', 'class', 'cnie', 'status'];

    /** Colonnes reconnues et ignorées : jamais copiées. */
    public const IGNORED = ['ID', 'Nom Complet', 'E-mail Personnel', 'Ville', 'Date de Naissance'];

    /** Valeur marquant une cellule non admise (formule, décimal, date…) dans une colonne utile. */
    public const INVALID = "\u{0}invalid";

    /**
     * @return list<array{line: int, values: array<string, string|null>}>
     */
    public function read(string $path, string $originalName): array
    {
        if (filesize($path) > self::MAX_BYTES) {
            throw new ImportFileException('Le fichier dépasse 5 Mo.');
        }
        $extension = Str::lower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (! in_array($extension, ['csv', 'xlsx'], true)) {
            throw new ImportFileException('Format non pris en charge : déposez un fichier CSV ou Excel (.xlsx).');
        }
        if ($extension === 'xlsx') {
            $this->checkXlsx($path);
        }

        return PrivateTemp::run(function (string $temp) use ($path, $extension): array {
            try {
                $reader = $extension === 'csv'
                    // Lignes vides CONSERVÉES par le lecteur (ignorées ensuite) : le numéro de ligne
                    // du rapport est celui du fichier (revue Codex T-13, constat 9).
                    ? new CsvReader(new CsvOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $this->delimiter($path)))
                    : new XlsxReader(new XlsxOptions(SHOULD_PRESERVE_EMPTY_ROWS: true, tempFolder: $temp));
                $reader->open($path);
            } catch (Throwable) {
                throw new ImportFileException('Fichier illisible : vérifiez qu’il s’agit bien de l’export de YouCode School Admin.');
            }

            try {
                return $this->rows($reader);
            } catch (ImportFileException $e) {
                throw $e;
            } catch (Throwable) {
                throw new ImportFileException('Fichier illisible : vérifiez qu’il s’agit bien de l’export de YouCode School Admin.');
            } finally {
                $reader->close();
            }
        });
    }

    /**
     * @return list<array{line: int, values: array<string, string|null>}>
     */
    private function rows(CsvReader|XlsxReader $reader): array
    {
        $columns = null;
        $firstLine = [];
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $line = 0;
            foreach ($sheet->getRowIterator() as $row) {
                $line++;
                $cells = $row->cells;
                if ($columns === null) {
                    // En-têtes en ligne 1, ou en ligne 2 après une ligne de titre.
                    $columns = $this->headers($cells);
                    if ($columns === null && $line === 1) {
                        $firstLine = $cells;

                        continue;
                    }
                    if ($columns === null) {
                        // Ni la ligne 1 ni la ligne 2 ne sont des en-têtes complets : on signale les
                        // colonnes manquantes de la ligne la plus proche des en-têtes attendus.
                        $missing = [$this->missing($firstLine), $this->missing($cells)];
                        usort($missing, fn (array $a, array $b) => count($a) <=> count($b));
                        throw new ImportFileException('En-têtes manquants : '.implode(', ', $missing[0]).'.');
                    }

                    continue;
                }

                $values = [];
                $empty = true;
                foreach ($columns as $key => $index) {
                    $value = isset($cells[$index]) ? $this->text($cells[$index]) : null;
                    if ($value !== null && $value !== '') {
                        $empty = false;
                    }
                    $values[$key] = $value;
                }
                if ($empty) {
                    continue;
                }
                if (count($out) >= self::MAX_ROWS) {
                    throw new ImportFileException('Le fichier contient plus de '.self::MAX_ROWS.' lignes : découpez-le.');
                }
                $out[] = ['line' => $line, 'values' => $values];
            }
            break; // Première feuille seulement.
        }

        if ($columns === null) {
            throw new ImportFileException('Le fichier est vide ou ne contient pas les en-têtes attendus.');
        }
        if ($out === []) {
            throw new ImportFileException('Le fichier ne contient aucune ligne d’apprenant.');
        }

        return $out;
    }

    /**
     * Position des colonnes utiles, ou null si la ligne n'est pas une ligne d'en-têtes complète.
     *
     * @param  list<Cell>  $cells
     * @return array<string, int>|null
     */
    private function headers(array $cells): ?array
    {
        $found = [];
        foreach ($cells as $index => $cell) {
            $name = self::normalizeHeader((string) (is_scalar($cell->getValue()) ? $cell->getValue() : ''));
            foreach (self::COLUMNS as $key => $label) {
                if ($name === self::normalizeHeader($label) && ! isset($found[$key])) {
                    $found[$key] = $index;
                }
            }
        }
        foreach (self::REQUIRED as $key) {
            if (! isset($found[$key])) {
                return null;
            }
        }

        return $found;
    }

    /**
     * @param  list<Cell>  $cells
     * @return list<string>
     */
    private function missing(array $cells): array
    {
        $present = array_map(fn (Cell $c) => self::normalizeHeader((string) (is_scalar($c->getValue()) ? $c->getValue() : '')), $cells);
        $missing = [];
        foreach (self::REQUIRED as $key) {
            if (! in_array(self::normalizeHeader(self::COLUMNS[$key]), $present, true)) {
                $missing[] = self::COLUMNS[$key];
            }
        }

        return $missing;
    }

    /** Valeur texte d'une cellule utile ; INVALID pour une formule, un décimal ou une date. */
    private function text(Cell $cell): ?string
    {
        if ($cell instanceof FormulaCell || $cell instanceof DateTimeCell || $cell instanceof DateIntervalCell) {
            return self::INVALID;
        }
        $value = $cell->getValue();
        if ($cell instanceof NumericCell) {
            if (is_int($value)) {
                return (string) $value;
            }
            if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
                return number_format($value, 0, '', '');
            }

            return self::INVALID;
        }
        if ($value === null) {
            return null;
        }
        if (! is_scalar($value)) {
            return self::INVALID;
        }

        return trim((string) $value);
    }

    public static function normalizeHeader(string $value): string
    {
        $value = preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;

        return Str::of(Str::ascii(trim($value)))->lower()->replaceMatches('/\s+/', ' ')->toString();
    }

    /**
     * Séparateur détecté sur la ligne d'en-têtes, en ligne 1 OU 2 (titre éventuel) : la ligne qui
     * contient le plus de séparateurs décide (revue Codex T-13, constat 5).
     */
    private function delimiter(string $path): string
    {
        $handle = fopen($path, 'r');
        $lines = [];
        if ($handle !== false) {
            for ($i = 0; $i < 2 && ($line = fgets($handle, 65536)) !== false; $i++) {
                $lines[] = $line;
            }
            fclose($handle);
        }
        $best = ['sep' => ',', 'count' => -1];
        foreach ($lines as $line) {
            foreach ([',', ';'] as $sep) {
                $count = substr_count($line, $sep);
                if ($count > $best['count']) {
                    $best = ['sep' => $sep, 'count' => $count];
                }
            }
        }

        return $best['sep'];
    }

    /** Bornes vérifiées AVANT la lecture des lignes (bombe ZIP, chaînes partagées). */
    private function checkXlsx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ImportFileException('Fichier Excel illisible.');
        }
        try {
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat === false ? 0 : (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new ImportFileException('Le contenu du fichier Excel est trop volumineux.');
                }
            }
            $hasShared = $zip->locateName('xl/sharedStrings.xml') !== false;
        } finally {
            $zip->close();
        }

        if ($hasShared && $this->countSharedStrings($path) > self::MAX_SHARED_STRINGS) {
            throw new ImportFileException('Le fichier Excel contient trop de textes différents.');
        }
    }

    /**
     * Nombre RÉEL de chaînes partagées, compté en lecture XML progressive et arrêté dès la
     * 50 001e : un compteur déclaré dans le fichier n'est jamais cru (revue Codex T-13, constat 2).
     */
    private function countSharedStrings(string $path): int
    {
        $xml = new XMLReader;
        $count = 0;
        // XML mal formé : refus du fichier, sans erreur 500 ni détail XML (contre-revue T-13, N3).
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (! $xml->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET)) {
                throw new ImportFileException('Fichier Excel illisible.');
            }
            while ($xml->read()) {
                if ($xml->nodeType === XMLReader::ELEMENT && $xml->localName === 'si' && ++$count > self::MAX_SHARED_STRINGS) {
                    return $count;
                }
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level === LIBXML_ERR_FATAL) {
                    throw new ImportFileException('Fichier Excel illisible.');
                }
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ImportFileException('Fichier Excel illisible.');
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        return $count;
    }
}

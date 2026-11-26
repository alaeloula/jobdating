<?php

namespace App\Services\Imports;

use App\Support\CompanyNameKey;
use DOMElement;
use DOMNode;
use Illuminate\Support\Str;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Lecture SÉLECTIVE du classeur de suivi de l'équipe CME (plan T-64 v1.3, § 3 ; décisions 1 à 3 et 7
 * du 25/11). Lecteur XML dédié, sans OpenSpout, qui décoderait toutes les chaînes du classeur.
 *
 * Seuls sont décodés : les noms des feuilles, les cellules de la ligne 1 de la feuille
 * « Entreprises Invitées », et les cellules des colonnes « Entreprises » et « Confirmation par mail ».
 * Aucune autre valeur (contacts, autres feuilles, colonne de mot de passe) n'est décodée, copiée ni
 * écrite. Les messages d'erreur ne contiennent jamais de valeur du classeur.
 */
final class CompanyWorkbookReader
{
    public const SHEET = 'Entreprises Invitées';

    public const NAME_HEADER = 'Entreprises';

    public const CONFIRMATION_HEADER = 'Confirmation par mail';

    public const MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_UNCOMPRESSED_BYTES = 50 * 1024 * 1024;

    public const MAX_ENTRIES = 5000;

    public const MAX_SHARED_STRINGS = 50000;

    public const MAX_ROWS = 1000;

    /** Cellule refusée (formule, nombre, date, booléen, erreur) : la ligne sera rejetée. */
    public const INVALID = "\u{0}invalid";

    private const SHARED_STRINGS_TYPE = '/sharedStrings';

    private const STOP = 0;

    private const READ = 1;

    private const MOVED = 2;

    /**
     * Numéros des chaînes partagées décodées lors de la dernière lecture : contrôle, par les tests, de
     * la frontière de décodage (sentinelles jamais décodées).
     *
     * @var list<int>
     */
    public array $decodedSharedStrings = [];

    /**
     * @return list<array{line: int, name: string|null, confirmation: string|null}>
     */
    public function read(string $path, string $originalName): array
    {
        $this->decodedSharedStrings = [];
        if (Str::lower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new ImportFileException('Format non pris en charge : déposez le classeur Excel (.xlsx).');
        }
        if ((int) filesize($path) > self::MAX_BYTES) {
            throw new ImportFileException('Le fichier dépasse 5 Mo.');
        }

        $parts = $this->parts($path);
        $sheet = $parts['sheet'];
        $shared = $parts['shared'];

        // 1. En-têtes (ligne 1), résolus AVANT les données.
        $headerCells = $this->headerRow($path, $sheet);
        $headerTexts = $this->resolve($path, $shared, $headerCells);
        $columns = $this->columns($headerTexts);

        // 2. Données : seules les deux colonnes utiles sont examinées.
        [$rows, $needed] = $this->dataRows($path, $sheet, $columns);
        $strings = $this->sharedStrings($path, $shared, $needed);

        $out = [];
        foreach ($rows as $line => $cells) {
            $name = $this->value($cells['name'] ?? null, $strings);
            $confirmation = $this->value($cells['confirmation'] ?? null, $strings);
            if (($name === null || trim($name) === '') && ($confirmation === null || trim($confirmation) === '')) {
                continue;
            }
            if (count($out) >= self::MAX_ROWS) {
                throw new ImportFileException('Le classeur contient plus de '.self::MAX_ROWS.' lignes d’entreprises : découpez-le.');
            }
            $out[] = ['line' => $line, 'name' => $name, 'confirmation' => $confirmation];
        }
        if ($out === []) {
            throw new ImportFileException('La feuille « '.self::SHEET.' » ne contient aucune entreprise.');
        }

        return $out;
    }

    // ------------------------------------------------------------------ Parties du classeur

    /**
     * Contrôles de l'archive, puis chemins de la feuille ciblée et des chaînes partagées, résolus par
     * les relations du classeur (jamais de cible externe ni de chemin hors de `xl/`).
     *
     * @return array{sheet: string, shared: string|null}
     */
    private function parts(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new ImportFileException('Classeur Excel illisible.');
        }
        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new ImportFileException('Le classeur Excel contient trop d’éléments.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat === false ? 0 : (int) $stat['size'];
                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new ImportFileException('Le contenu du classeur Excel est trop volumineux.');
                }
            }
            $workbook = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if (! is_string($workbook) || ! is_string($rels)) {
                throw new ImportFileException('Classeur Excel illisible.');
            }

            $relations = [];
            foreach ($this->elements($rels, 'Relationship') as $rel) {
                $relations[$rel->getAttribute('Id')] = [
                    'target' => $rel->getAttribute('Target'),
                    'external' => Str::lower($rel->getAttribute('TargetMode')) === 'external',
                    'type' => $rel->getAttribute('Type'),
                ];
            }

            $matches = [];
            foreach ($this->elements($workbook, 'sheet') as $sheet) {
                if (CompanyNameKey::normalize($sheet->getAttribute('name')) === CompanyNameKey::normalize(self::SHEET)) {
                    $matches[] = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                }
            }
            if ($matches === []) {
                throw new ImportFileException('Feuille « '.self::SHEET.' » introuvable dans le classeur.');
            }
            if (count($matches) > 1) {
                throw new ImportFileException('Plusieurs feuilles portent le nom « '.self::SHEET.' » : gardez-en une seule.');
            }

            $sheetPath = $this->target($relations[$matches[0]] ?? null);
            if ($sheetPath === null || $zip->locateName($sheetPath) === false) {
                throw new ImportFileException('Classeur Excel illisible.');
            }

            $shared = null;
            foreach ($relations as $relation) {
                if (str_ends_with($relation['type'], self::SHARED_STRINGS_TYPE)) {
                    $shared = $this->target($relation);
                    if ($shared === null || $zip->locateName($shared) === false) {
                        throw new ImportFileException('Classeur Excel illisible.');
                    }
                }
            }

            return ['sheet' => $sheetPath, 'shared' => $shared];
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  array{target: string, external: bool, type: string}|null  $relation
     */
    private function target(?array $relation): ?string
    {
        if ($relation === null || $relation['external'] || $relation['target'] === '') {
            return null;
        }
        $raw = str_replace('\\', '/', $relation['target']);
        $path = str_starts_with($raw, '/') ? ltrim($raw, '/') : 'xl/'.$raw;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }
        $normalized = implode('/', $segments);

        return str_starts_with($normalized, 'xl/') ? $normalized : null;
    }

    /**
     * Petites parties XML (classeur, relations) : uniquement des métadonnées.
     *
     * @return list<DOMElement>
     */
    private function elements(string $xml, string $localName): array
    {
        $previous = libxml_use_internal_errors(true);
        try {
            $doc = new \DOMDocument;
            if (! $doc->loadXML($xml, LIBXML_NONET)) {
                throw new ImportFileException('Classeur Excel illisible.');
            }
            $out = [];
            foreach ($doc->getElementsByTagName('*') as $node) {
                if ($node->localName === $localName) {
                    $out[] = $node;
                }
            }

            return $out;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    // ------------------------------------------------------------------ Feuille

    /**
     * Cellules de la ligne 1 seulement (en-têtes).
     *
     * @return array<int, array{shared?: int, text?: string, invalid?: bool}>
     */
    private function headerRow(string $path, string $sheet): array
    {
        $cells = [];
        $this->stream($path, $sheet, function (XMLReader $xml) use (&$cells): int {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                return self::READ;
            }
            $line = (int) $xml->getAttribute('r');
            if ($line === 0 || $line === 1) {
                $cells = $this->rowCells($xml, null);
            }

            return self::STOP; // Ligne 1 absente : en-têtes manquants.
        });

        return $cells;
    }

    /**
     * @param  array<int, string|null>  $texts  texte de chaque en-tête par colonne
     * @return array{name: int, confirmation: int}
     */
    private function columns(array $texts): array
    {
        $found = ['name' => [], 'confirmation' => []];
        foreach ($texts as $column => $text) {
            $key = CompanyNameKey::normalize((string) $text);
            if ($key === CompanyNameKey::normalize(self::NAME_HEADER)) {
                $found['name'][] = $column;
            } elseif ($key === CompanyNameKey::normalize(self::CONFIRMATION_HEADER)) {
                $found['confirmation'][] = $column;
            }
        }
        foreach (['name' => self::NAME_HEADER, 'confirmation' => self::CONFIRMATION_HEADER] as $key => $label) {
            if ($found[$key] === []) {
                throw new ImportFileException('Colonne « '.$label.' » introuvable en ligne 1 de la feuille « '.self::SHEET.' ».');
            }
            if (count($found[$key]) > 1) {
                throw new ImportFileException('La colonne « '.$label.' » apparaît plusieurs fois : gardez-en une seule.');
            }
        }

        return ['name' => $found['name'][0], 'confirmation' => $found['confirmation'][0]];
    }

    /**
     * Lignes 2 et suivantes : pour chaque ligne, les cellules des deux colonnes utiles seulement.
     *
     * @param  array{name: int, confirmation: int}  $columns
     * @return array{0: array<int, array<string, array{shared?: int, text?: string, invalid?: bool}>>, 1: array<int, true>}
     */
    private function dataRows(string $path, string $sheet, array $columns): array
    {
        $wanted = [$columns['name'] => 'name', $columns['confirmation'] => 'confirmation'];
        $rows = [];
        $needed = [];
        $counter = 0;
        $this->stream($path, $sheet, function (XMLReader $xml) use (&$rows, &$needed, &$counter, $wanted): int {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'row') {
                return self::READ;
            }
            $line = (int) $xml->getAttribute('r');
            $counter = $line > 0 ? $line : $counter + 1;
            if ($counter === 1) {
                $this->rowCells($xml, []); // en-têtes déjà lus : aucune cellule décodée

                return self::READ;
            }
            $cells = [];
            foreach ($this->rowCells($xml, array_keys($wanted)) as $column => $cell) {
                $cells[$wanted[$column]] = $cell;
                if (isset($cell['shared'])) {
                    $needed[$cell['shared']] = true;
                }
            }
            if ($cells !== []) {
                $rows[$counter] = $cells;
            }

            return self::READ;
        });

        return [$rows, $needed];
    }

    /**
     * Cellules d'une ligne (curseur sur `<row>`). Les cellules hors de `$only` sont SAUTÉES sans être
     * décodées. Une cellule fusionnée ne propage rien : seule sa propre valeur compte.
     *
     * @param  list<int>|null  $only
     * @return array<int, array{shared?: int, text?: string, invalid?: bool}>
     */
    private function rowCells(XMLReader $xml, ?array $only): array
    {
        $cells = [];
        if ($xml->isEmptyElement) {
            return $cells;
        }
        $depth = $xml->depth;
        $position = 0;
        $more = $xml->read();
        while ($more) {
            if ($xml->nodeType === XMLReader::END_ELEMENT && $xml->depth === $depth) {
                break;
            }
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'c') {
                $more = $xml->read();

                continue;
            }
            $ref = (string) $xml->getAttribute('r');
            $column = $ref !== '' ? $this->columnIndex($ref) : $position + 1;
            $position = $column;
            if ($only === null || in_array($column, $only, true)) {
                $cell = $this->cell($xml);
                if ($cell !== null) {
                    $cells[$column] = $cell;
                }
            }
            // Cellule suivante, sans entrer dans le contenu de celle-ci (jamais décodé si non utile).
            $more = $xml->next();
        }

        return $cells;
    }

    /**
     * Une cellule utile (curseur sur `<c>`) : numéro de chaîne partagée, texte en ligne, ou refus.
     *
     * @return array{shared?: int, text?: string, invalid?: bool}|null null pour une cellule vide
     */
    private function cell(XMLReader $xml): ?array
    {
        $type = (string) $xml->getAttribute('t');
        $node = $xml->expand();
        if (! $node instanceof DOMElement) {
            return null;
        }
        $formula = false;
        $value = null;
        $inline = null;
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            match ($child->localName) {
                'f' => $formula = true,
                'v' => $value = $child->textContent,
                'is' => $inline = $this->text($child),
                default => null,
            };
        }

        if ($formula) {
            return ['invalid' => true];
        }
        if ($type === 'inlineStr') {
            return $inline === null || $inline === '' ? null : ['text' => $inline];
        }
        if ($value === null || $value === '') {
            return null;
        }
        if ($type === 's' && ctype_digit($value)) {
            return ['shared' => (int) $value];
        }

        return ['invalid' => true]; // nombre, date, booléen, erreur
    }

    // ------------------------------------------------------------------ Chaînes partagées

    /**
     * @param  array<int, array{shared?: int, text?: string, invalid?: bool}>  $cells
     * @return array<int, string|null>
     */
    private function resolve(string $path, ?string $shared, array $cells): array
    {
        $needed = [];
        foreach ($cells as $cell) {
            if (isset($cell['shared'])) {
                $needed[$cell['shared']] = true;
            }
        }
        $strings = $this->sharedStrings($path, $shared, $needed);
        $texts = [];
        foreach ($cells as $column => $cell) {
            $value = $this->value($cell, $strings);
            $texts[$column] = $value === self::INVALID ? null : $value;
        }

        return $texts;
    }

    /**
     * Décode SEULEMENT les chaînes dont le numéro est demandé ; les autres sont sautées.
     *
     * @param  array<int, true>  $needed
     * @return array<int, string>
     */
    private function sharedStrings(string $path, ?string $shared, array $needed): array
    {
        if ($needed === []) {
            return [];
        }
        if ($shared === null) {
            throw new ImportFileException('Classeur Excel illisible.');
        }
        $found = [];
        $index = -1;
        $this->stream($path, $shared, function (XMLReader $xml) use (&$found, &$index, $needed): int {
            if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si') {
                return self::READ;
            }
            $index++;
            if ($index >= self::MAX_SHARED_STRINGS) {
                throw new ImportFileException('Le classeur Excel contient trop de textes différents.');
            }
            if (isset($needed[$index])) {
                $node = $xml->expand();
                $found[$index] = $node instanceof DOMNode ? $this->text($node) : '';
                $this->decodedSharedStrings[] = $index;
                if (count($found) === count($needed)) {
                    return self::STOP;
                }
            }

            // Chaîne suivante, sans décoder le contenu de celle-ci.
            return $xml->next() ? self::MOVED : self::STOP;
        });
        foreach (array_keys($needed) as $wanted) {
            if (! array_key_exists($wanted, $found)) {
                throw new ImportFileException('Classeur Excel illisible.');
            }
        }

        return $found;
    }

    /**
     * Texte d'une chaîne (simple ou riche) : concaténation des `<t>`, sans les annotations phonétiques.
     */
    private function text(DOMNode $node): string
    {
        $out = '';
        foreach ($node->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }
            if ($child->localName === 't') {
                $out .= $child->textContent;
            } elseif ($child->localName === 'r') {
                $out .= $this->text($child);
            }
        }

        return $out;
    }

    /**
     * @param  array{shared?: int, text?: string, invalid?: bool}|null  $cell
     * @param  array<int, string>  $strings
     */
    private function value(?array $cell, array $strings): ?string
    {
        return match (true) {
            $cell === null => null,
            isset($cell['invalid']) => self::INVALID,
            isset($cell['text']) => $cell['text'],
            isset($cell['shared']) => $strings[$cell['shared']] ?? null,
            default => null,
        };
    }

    // ------------------------------------------------------------------ Outils

    private function columnIndex(string $ref): int
    {
        $letters = (string) preg_replace('/[^A-Z]/', '', strtoupper($ref));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index;
    }

    /**
     * Lecture en flux d'une partie. `$visit` renvoie STOP (arrêter), READ (lire le nœud suivant) ou
     * MOVED (le curseur a déjà avancé par `next()`). XML mal formé : refus du fichier, sans détail.
     *
     * @param  callable(XMLReader): int  $visit
     */
    private function stream(string $path, string $entry, callable $visit): void
    {
        $xml = new XMLReader;
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            if (! $xml->open('zip://'.$path.'#'.$entry, null, LIBXML_NONET)) {
                throw new ImportFileException('Classeur Excel illisible.');
            }
            $more = $xml->read();
            while ($more) {
                $step = $visit($xml);
                if ($step === self::STOP) {
                    break;
                }
                $more = $step === self::MOVED ? true : $xml->read();
            }
            foreach (libxml_get_errors() as $error) {
                if ($error->level === LIBXML_ERR_FATAL) {
                    throw new ImportFileException('Classeur Excel illisible.');
                }
            }
        } catch (ImportFileException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ImportFileException('Classeur Excel illisible.');
        } finally {
            $xml->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}

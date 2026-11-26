<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Classeur XLSX FICTIF construit pièce par pièce pour les tests de T-64 (aucun fichier réel). Permet
 * des chaînes partagées, des chaînes en ligne, du texte riche, des formules, des nombres et des
 * chaînes partagées inutilisées (sentinelles).
 *
 * Valeur d'une cellule : string (chaîne partagée) | ['inline' => string] | ['rich' => list<string>]
 * | ['formula' => string, 'value' => string] | ['number' => int|float] | null (cellule absente).
 */
final class FakeWorkbook
{
    /** @var list<string> chaînes partagées, dans l'ordre (simples) */
    private array $shared = [];

    /** @var array<int, list<string>> fragments des chaînes riches, par numéro */
    private array $rich = [];

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  nom => lignes (numéro de ligne => cellules)
     * @param  list<string>  $unused  chaînes partagées ajoutées sans être utilisées par aucune cellule
     */
    public function __construct(private readonly array $sheets, private readonly array $unused = []) {}

    /** Numéro de chaîne partagée d'un texte (après construction). */
    public function indexOf(string $text): ?int
    {
        $index = array_search($text, $this->shared, true);

        return $index === false ? null : (int) $index;
    }

    /** Classeur construit UNE fois : des fichiers identiques octet pour octet (même empreinte). */
    private ?string $built = null;

    public function file(string $name = 'suivi.xlsx'): UploadedFile
    {
        if ($this->built === null) {
            $this->built = (string) tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
            $this->write($this->built);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'wb').'.xlsx';
        copy($this->built, $path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    public function write(string $path): void
    {
        $this->shared = [];
        $this->rich = [];
        $sheetXml = [];
        foreach ($this->sheets as $name => $rows) {
            $sheetXml[] = $this->sheet($rows);
        }
        foreach ($this->unused as $text) {
            $this->shared[] = $text;
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $sheets = '';
        $rels = '';
        $i = 0;
        foreach (array_keys($this->sheets) as $name) {
            $i++;
            $sheets .= '<sheet name="'.htmlspecialchars((string) $name, ENT_XML1).'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', $sheetXml[$i - 1]);
        }
        $rels .= '<Relationship Id="rIdS" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'.$sheets.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>');

        $si = '';
        foreach ($this->shared as $index => $text) {
            $si .= isset($this->rich[$index])
                ? '<si>'.implode('', array_map(fn (string $part) => '<r><rPr><b/></rPr><t xml:space="preserve">'.htmlspecialchars($part, ENT_XML1).'</t></r>', $this->rich[$index])).'<rPh sb="0" eb="1"><t>ANNOTATION</t></rPh></si>'
                : '<si><t xml:space="preserve">'.htmlspecialchars($text, ENT_XML1).'</t></si>';
        }
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'.count($this->shared).'">'.$si.'</sst>');
        $zip->close();
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function sheet(array $rows): string
    {
        $xml = '';
        foreach ($rows as $line => $cells) {
            $xml .= '<row r="'.$line.'">';
            foreach ($cells as $column => $value) {
                if ($value === null) {
                    continue;
                }
                $ref = $this->letters($column + 1).$line;
                $xml .= match (true) {
                    is_string($value) => '<c r="'.$ref.'" t="s"><v>'.$this->share($value).'</v></c>',
                    isset($value['inline']) => '<c r="'.$ref.'" t="inlineStr"><is><t>'.htmlspecialchars($value['inline'], ENT_XML1).'</t></is></c>',
                    isset($value['rich']) => '<c r="'.$ref.'" t="s"><v>'.$this->shareRich($value['rich']).'</v></c>',
                    isset($value['formula']) => '<c r="'.$ref.'" t="str"><f>'.htmlspecialchars($value['formula'], ENT_XML1).'</f><v>'.htmlspecialchars($value['value'], ENT_XML1).'</v></c>',
                    isset($value['number']) => '<c r="'.$ref.'"><v>'.$value['number'].'</v></c>',
                    default => '',
                };
            }
            $xml .= '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xml.'</sheetData><mergeCells count="1"><mergeCell ref="D2:D3"/></mergeCells></worksheet>';
    }

    private function share(string $text): int
    {
        $index = array_search($text, $this->shared, true);
        if ($index !== false && ! isset($this->rich[(int) $index])) {
            return (int) $index;
        }
        $this->shared[] = $text;

        return count($this->shared) - 1;
    }

    /** @param list<string> $parts */
    private function shareRich(array $parts): int
    {
        $this->shared[] = implode('', $parts);
        $this->rich[count($this->shared) - 1] = $parts;

        return count($this->shared) - 1;
    }

    private function letters(int $column): string
    {
        $out = '';
        while ($column > 0) {
            $mod = ($column - 1) % 26;
            $out = chr(65 + $mod).$out;
            $column = intdiv($column - 1, 26);
        }

        return $out;
    }
}

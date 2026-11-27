<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;

/**
 * PDF FICTIFS construits par les tests (plan T-20 v1.2, § 8) : aucune donnée réelle. Les décalages
 * de la table des références sont calculés ; le contenu reste minimal.
 */
final class FakePdf
{
    /** PDF classique d'une page (table `xref` en clair). */
    public static function classic(string $text = 'CV fictif', string $catalogExtra = '', string $pageExtra = '', string $padding = ''): string
    {
        $content = 'BT /F1 12 Tf 72 760 Td ('.$text.') Tj ET';

        return self::build([
            [1, '<< /Type /Catalog /Pages 2 0 R '.$catalogExtra.'>>'],
            [2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'],
            [3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> '.$pageExtra.'>>'],
            [4, self::stream($content.$padding)],
            [5, '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'],
        ]);
    }

    /**
     * MARQUEUR de linéarisation seulement : dictionnaire `/Linearized` en premier objet, `/L` égal à la
     * taille réelle, mais sans tables d'indices valides. Ce n'est PAS un vrai PDF linéarisé : celui-ci est
     * dans `tests/Fixtures/cv/fictif-linearise.pdf` (vérification Codex T-20, V2).
     */
    public static function linearized(): string
    {
        $size = 0;
        for ($i = 0; $i < 10; $i++) {
            $pdf = self::build([
                [6, '<< /Linearized 1 /L '.$size.' /H [0 0] /O 3 /E 0 /N 1 /T 0 >>'],
                [1, '<< /Type /Catalog /Pages 2 0 R >>'],
                [2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'],
                [3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>'],
                [4, self::stream('BT 72 760 Td (CV fictif) Tj ET')],
            ], size: 7);
            if (strlen($pdf) === $size) {
                return $pdf;
            }
            $size = strlen($pdf);
        }

        throw new \RuntimeException('Taille du PDF linéarisé instable.');
    }

    /** Longueur du flux de contenu donnée par un objet indirect (`/Length 5 0 R`), comme LibreOffice. */
    public static function indirectLength(): string
    {
        $content = 'BT 72 760 Td (CV fictif) Tj ET';

        return self::build([
            [1, '<< /Type /Catalog /Pages 2 0 R >>'],
            [2, '<< /Type /Pages /Kids [3 0 R] /Count 1 >>'],
            [3, '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>'],
            [4, "<< /Length 5 0 R >>\nstream\n".$content."\nendstream"],
            [5, (string) strlen($content)],
        ]);
    }

    /**
     * PDF 1.5 à objets compressés : catalogue, arbre des pages et page dans un flux `/ObjStm`, table
     * des références en flux `/Type /XRef`. Aucun marqueur de page n'est lisible en clair.
     */
    public static function compressedObjects(): string
    {
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R >>',
        ];
        $header = '';
        $body = '';
        foreach ($objects as $number => $object) {
            $header .= $number.' '.strlen($body).' ';
            $body .= $object."\n";
        }
        $packed = (string) gzcompress($header.$body);

        $pdf = "%PDF-1.5\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        $offsets[4] = strlen($pdf);
        $pdf .= "4 0 obj\n".self::stream((string) gzcompress('BT 72 760 Td (CV fictif) Tj ET'), '/Filter /FlateDecode')."\nendobj\n";
        $offsets[5] = strlen($pdf);
        $pdf .= "5 0 obj\n<< /Type /ObjStm /N 3 /First ".strlen($header).' /Filter /FlateDecode /Length '.strlen($packed)." >>\nstream\n".$packed."\nendstream\nendobj\n";
        $offsets[6] = strlen($pdf);
        $rows = pack('CNn', 0, 0, 65535);
        foreach ([1, 2, 3] as $index => $number) {
            $rows .= pack('CNn', 2, 5, $index);
        }
        foreach ([4, 5, 6] as $number) {
            $rows .= pack('CNn', 1, $offsets[$number], 0);
        }
        $xref = (string) gzcompress($rows);
        $pdf .= "6 0 obj\n<< /Type /XRef /Size 7 /W [1 4 2] /Root 1 0 R /Filter /FlateDecode /Length ".strlen($xref)." >>\nstream\n".$xref."\nendstream\nendobj\n";

        return $pdf."startxref\n".$offsets[6]."\n%%EOF\n";
    }

    /** PDF de exactement `$bytes` octets (remplissage dans le flux de contenu). */
    public static function ofSize(int $bytes): string
    {
        $base = strlen(self::classic(padding: ''));
        // Le nombre de chiffres de /Length et des décalages varie : on ajuste jusqu'à la taille exacte.
        $padding = max(0, $bytes - $base - 40);
        for ($i = 0; $i < 100; $i++) {
            $pdf = self::classic(padding: "\n%".str_repeat('x', $padding));
            $diff = $bytes - strlen($pdf);
            if ($diff === 0) {
                return $pdf;
            }
            $padding += $diff;
        }

        throw new \RuntimeException('Taille impossible à atteindre.');
    }

    public static function upload(string $bytes, string $name = 'cv-fictif.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /**
     * @param  list<array{0: int, 1: string}>  $objects  numéro et contenu, dans l'ordre du fichier
     */
    private static function build(array $objects, ?int $size = null): string
    {
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objects as [$number, $body]) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number." 0 obj\n".$body."\nendobj\n";
        }
        $size ??= max(array_keys($offsets)) + 1;
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".$size."\n0000000000 65535 f \n";
        for ($n = 1; $n < $size; $n++) {
            $pdf .= isset($offsets[$n]) ? sprintf("%010d 00000 n \n", $offsets[$n]) : "0000000000 65535 f \n";
        }

        return $pdf."trailer\n<< /Size ".$size." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF\n";
    }

    private static function stream(string $data, string $extra = ''): string
    {
        return '<< /Length '.strlen($data).($extra !== '' ? ' '.$extra : '')." >>\nstream\n".$data."\nendstream";
    }
}

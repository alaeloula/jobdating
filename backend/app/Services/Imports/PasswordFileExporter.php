<?php

namespace App\Services\Imports;

use App\Models\LearnerImport;
use App\Support\PrivateTemp;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Fichier des mots de passe d'un import (décisions du 14/11 et 11 du 20/11) : Excel EN CLAIR pour
 * l'administrateur, généré à la demande depuis la copie chiffrée ; fichiers intermédiaires dans un
 * dossier temporaire privé supprimé aussitôt. Valeurs neutralisées contre les formules.
 */
class PasswordFileExporter
{
    public const HEADERS = ['Prénom', 'Nom', 'E-mail', 'Classe', 'Mot de passe'];

    public function bytes(LearnerImport $import): string
    {
        /** @var list<array{first_name: string, last_name: string, email: string, class: string, password: string}> $rows */
        $rows = $import->passwords ?? [];

        return PrivateTemp::run(function (string $temp) use ($rows): string {
            $path = $temp.DIRECTORY_SEPARATOR.'mots-de-passe.xlsx';
            $writer = new Writer(new Options(tempFolder: $temp));
            $writer->openToFile($path);
            try {
                $writer->addRow(Row::fromValues(self::HEADERS));
                foreach ($rows as $r) {
                    $writer->addRow(Row::fromValues(array_map(
                        fn (string $v) => self::neutralize($v),
                        [$r['first_name'], $r['last_name'], $r['email'], $r['class'], $r['password']],
                    )));
                }
            } finally {
                $writer->close();
            }

            return (string) file_get_contents($path);
        });
    }

    /** Une cellule commençant par =, +, - ou @ est préfixée d'une apostrophe (pas de formule). */
    public static function neutralize(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}

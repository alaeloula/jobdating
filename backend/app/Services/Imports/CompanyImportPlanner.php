<?php

namespace App\Services\Imports;

use App\Models\Company;
use App\Models\Event;
use App\Services\Companies\UsernameSuggester;
use App\Support\CompanyNameKey;

/**
 * Aperçu d'un import d'entreprises (plan T-64 v1.3, § 5 ; décisions 2, 4, 5 et 8 du 25/11) : une
 * décision par ligne, dans l'ordre du fichier, et une empreinte déterministe de ces décisions.
 */
final class CompanyImportPlanner
{
    public const CREATE = 'create';

    public const EXISTING = 'existing';

    public const DUPLICATE = 'duplicate';

    public const REJECT = 'reject';

    public const UNCONFIRMED = 'unconfirmed';

    public const MAX_NAME = 150;

    /**
     * @param  list<array{line: int, name: string|null, confirmation: string|null}>  $rows
     * @return array{rows: list<array{line: int, decision: string, reason: string|null, name: string|null, key: string|null, username: string|null}>, counts: array{create: int, existing: int, duplicate: int, reject: int, unconfirmed: int}, signaled: list<array{line: int, reason: string, name: string|null}>, fingerprint: string}
     */
    public function plan(array $rows, string $fileHash, Event $event): array
    {
        // 1. Lignes confirmées et noms valides (sans réservation).
        $prepared = [];
        foreach ($rows as $row) {
            $prepared[] = $this->classify($row);
        }

        // 2. Entreprises existantes (désactivées comprises), par clé.
        $keys = array_values(array_unique(array_filter(array_column($prepared, 'key'))));
        $existing = array_flip(Company::query()->whereIn('name_key', $keys)->pluck('name_key')->all());

        // 3. Doublons et noms d'utilisateur, dans l'ordre du fichier.
        $seen = [];
        $reserved = [];
        foreach ($prepared as &$r) {
            if ($r['decision'] !== null) {
                continue;
            }
            if (isset($existing[$r['key']])) {
                $r['decision'] = self::EXISTING;
                $r['reason'] = 'Entreprise déjà existante : ajoutez-la depuis l’événement';
            } elseif (isset($seen[$r['key']])) {
                $r['decision'] = self::DUPLICATE;
                $r['reason'] = 'Doublon dans le fichier (ligne '.$seen[$r['key']].')';
            } else {
                // Seule une ligne confirmée et valide réserve le nom (décision 8).
                $seen[$r['key']] = $r['line'];
                $r['decision'] = self::CREATE;
                $r['username'] = UsernameSuggester::suggest((string) $r['name'], $reserved);
                $reserved[] = $r['username'];
            }
        }
        unset($r);

        $counts = ['create' => 0, 'existing' => 0, 'duplicate' => 0, 'reject' => 0, 'unconfirmed' => 0];
        $signaled = [];
        foreach ($prepared as $r) {
            $counts[$r['decision']]++;
            if (in_array($r['decision'], [self::EXISTING, self::DUPLICATE, self::REJECT], true)) {
                $signaled[] = ['line' => $r['line'], 'reason' => (string) $r['reason'], 'name' => $r['decision'] === self::REJECT ? null : $r['name']];
            }
        }

        return [
            'rows' => $prepared,
            'counts' => $counts,
            'signaled' => $signaled,
            'fingerprint' => self::fingerprint($prepared, $fileHash, $event->id),
        ];
    }

    /**
     * @param  list<array{line: int, decision: string, reason: string|null, name: string|null, key: string|null, username: string|null}>  $rows
     */
    public static function fingerprint(array $rows, string $fileHash, int $eventId): string
    {
        $lines = array_map(fn (array $r) => [$r['line'], $r['decision'], $r['reason'], $r['key'], $r['username']], $rows);

        return hash('sha256', (string) json_encode([$fileHash, $eventId, $lines]));
    }

    /**
     * « Oui » sans casse ni espaces (décision 2 du 25/11) : la ponctuation n'est PAS retirée, « Oui ? »
     * n'est pas une confirmation (revue Codex T-64, constat 2).
     */
    public static function confirmed(string $value): bool
    {
        return mb_strtolower((string) preg_replace('/[\s\x{00A0}]+/u', '', $value)) === 'oui';
    }

    /**
     * @param  array{line: int, name: string|null, confirmation: string|null}  $row
     * @return array{line: int, decision: string|null, reason: string|null, name: string|null, key: string|null, username: string|null}
     */
    private function classify(array $row): array
    {
        $out = ['line' => $row['line'], 'decision' => null, 'reason' => null, 'name' => null, 'key' => null, 'username' => null];
        $reject = function (string $reason) use (&$out): array {
            $out['decision'] = self::REJECT;
            $out['reason'] = $reason;

            return $out;
        };

        if ($row['confirmation'] === CompanyWorkbookReader::INVALID) {
            return $reject('Valeur non admise dans « '.CompanyWorkbookReader::CONFIRMATION_HEADER.' »');
        }
        if (! self::confirmed((string) $row['confirmation'])) {
            $out['decision'] = self::UNCONFIRMED;

            return $out;
        }
        if ($row['name'] === CompanyWorkbookReader::INVALID) {
            return $reject('Valeur non admise dans « '.CompanyWorkbookReader::NAME_HEADER.' »');
        }

        $name = trim((string) preg_replace('/\s+/u', ' ', (string) $row['name']));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            return $reject('Nom manquant ou trop long');
        }
        $key = CompanyNameKey::of($name);
        if ($key === null) {
            return $reject('Nom sans lettre ni chiffre');
        }
        $out['name'] = $name;
        $out['key'] = $key;

        return $out;
    }
}

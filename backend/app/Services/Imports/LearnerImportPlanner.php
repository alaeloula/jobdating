<?php

namespace App\Services\Imports;

use App\Models\Learner;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Promotion;
use App\Models\Referentials\SchoolClass;
use App\Models\User;
use App\Support\Cnie;
use Illuminate\Support\Str;

/**
 * Analyse d'un fichier d'import (ADM-04, plan T-13 v2.2, §2) : une décision par ligne (créer, mettre à
 * jour, inchangée, rejet) et une empreinte déterministe de ces décisions (constat 3). La classe de
 * chaque apprenant vient de la colonne « Classe » de sa ligne (décision 2, v2.2).
 *
 * Les motifs de rejet ne contiennent JAMAIS de CNIE ni d'e-mail.
 */
final class LearnerImportPlanner
{
    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const UNCHANGED = 'unchanged';

    public const REJECT = 'reject';

    /**
     * @param  list<array{line: int, values: array<string, string|null>}>  $rows
     * @return array{rows: list<array<string, mixed>>, counts: array{create: int, update: int, unchanged: int, reject: int}, classes: list<array{class: string, create: int, update: int, unchanged: int}>, rejections: list<array{line: int, reason: string}>, fingerprint: string}
     */
    public function plan(array $rows, string $fileHash, Campus $campus, Promotion $promotion): array
    {
        $classes = self::activeClasses();
        $planned = [];

        // 1. Contrôles propres à chaque ligne.
        foreach ($rows as $row) {
            $v = $row['values'];
            $r = [
                'line' => $row['line'],
                'cnie' => Cnie::normalize((string) ($v['cnie'] ?? '')),
                'first_name' => (string) ($v['first_name'] ?? ''),
                'last_name' => (string) ($v['last_name'] ?? ''),
                'email' => (string) ($v['email'] ?? ''),
                'phone' => ($v['phone'] ?? '') === '' ? null : (string) $v['phone'],
                'active' => self::isActive((string) ($v['status'] ?? '')),
                'decision' => null,
                'reason' => null,
                'learner_id' => null,
                'user_id' => null,
                'class_id' => null,
                'class' => null,
            ];
            $classKey = self::normalizeClass((string) ($v['class'] ?? ''));
            $class = $classes[$classKey] ?? null;
            if ($class instanceof SchoolClass) {
                $r['class_id'] = $class->id;
                $r['class'] = $class->name;
            }
            $r['reason'] = $this->lineReason($v, $r, $classKey, $classes);
            $planned[] = $r;
        }

        // 2. Doublons dans le fichier : les DEUX lignes sont rejetées (RG-IMP-02, H2).
        $this->rejectDuplicates($planned, 'cnie', 'CNIE en double');
        $this->rejectDuplicates($planned, 'email', 'E-mail en double', lower: true);

        // 3. Comparaison avec la base.
        $valid = array_filter($planned, fn (array $r) => $r['reason'] === null);
        $learners = Learner::query()->with(['user' => fn ($q) => $q->withTrashed()])
            ->whereIn('cnie', array_column($valid, 'cnie'))->get()->keyBy('cnie');
        $users = User::withTrashed()->whereIn('email', array_column($valid, 'email'))->get()
            ->keyBy(fn (User $u) => Str::lower((string) $u->email));

        foreach ($planned as &$r) {
            if ($r['reason'] !== null) {
                $r['decision'] = self::REJECT;

                continue;
            }
            $learner = $learners->get($r['cnie']);
            $owner = $users->get(Str::lower($r['email']));

            if ($learner !== null && $learner->user->trashed()) {
                $r['reason'] = 'Fiche supprimée : restaurez-la d’abord';
            } elseif ($owner !== null && ($learner === null || $owner->getKey() !== $learner->user_id)) {
                $r['reason'] = 'E-mail déjà utilisé';
            }
            if ($r['reason'] !== null) {
                $r['decision'] = self::REJECT;

                continue;
            }

            if ($learner === null) {
                $r['decision'] = self::CREATE;

                continue;
            }
            $r['learner_id'] = $learner->id;
            $r['user_id'] = $learner->user_id;
            $user = $learner->user;
            $same = $user->first_name === $r['first_name'] && $user->last_name === $r['last_name']
                && $user->email === $r['email'] && $user->phone === $r['phone']
                && $learner->campus_id === $campus->id && $learner->promotion_id === $promotion->id
                && $learner->class_id === $r['class_id'] && $user->isDeactivated() === ! $r['active'];
            $r['decision'] = $same ? self::UNCHANGED : self::UPDATE;
        }
        unset($r);

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'reject' => 0];
        /** @var array<string, array{class: string, create: int, update: int, unchanged: int}> $byClass */
        $byClass = [];
        $rejections = [];
        foreach ($planned as $r) {
            $counts[$r['decision']]++;
            if ($r['decision'] === self::REJECT) {
                $rejections[] = ['line' => $r['line'], 'reason' => (string) $r['reason']];

                continue;
            }
            // Comptes par classe : lignes valides seulement (v2.2).
            $name = (string) $r['class'];
            $entry = $byClass[$name] ?? ['class' => $name, 'create' => 0, 'update' => 0, 'unchanged' => 0];
            match ($r['decision']) {
                self::CREATE => $entry['create']++,
                self::UPDATE => $entry['update']++,
                default => $entry['unchanged']++,
            };
            $byClass[$name] = $entry;
        }
        ksort($byClass, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'rows' => $planned,
            'counts' => $counts,
            'classes' => array_values($byClass),
            'rejections' => $rejections,
            'fingerprint' => self::fingerprint($planned, $fileHash, $campus->id, $promotion->id),
        ];
    }

    /**
     * Empreinte déterministe : fichier, choix et, pour chaque ligne, numéro, décision, motif, classe
     * retenue et cibles. Opaque (hachage), jamais stockée en clair.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public static function fingerprint(array $rows, string $fileHash, int $campusId, int $promotionId): string
    {
        $lines = array_map(fn (array $r) => [$r['line'], $r['decision'], $r['reason'], $r['class_id'], $r['learner_id'], $r['user_id']], $rows);

        return hash('sha256', (string) json_encode([$fileHash, $campusId, $promotionId, $lines]));
    }

    /**
     * Classes ACTIVES indexées par nom normalisé (sans casse ni espaces) ; `false` quand deux classes
     * actives ont le même nom normalisé (ambiguïté : ligne rejetée).
     *
     * @return array<string, SchoolClass|false>
     */
    public static function activeClasses(): array
    {
        $map = [];
        foreach (SchoolClass::query()->where('active', true)->orderBy('id')->get() as $class) {
            $key = self::normalizeClass($class->name);
            $map[$key] = array_key_exists($key, $map) ? false : $class;
        }

        return $map;
    }

    public static function normalizeClass(string $value): string
    {
        return mb_strtolower((string) preg_replace('/\s+/u', '', $value));
    }

    public static function isActive(string $status): bool
    {
        return mb_strtolower(trim($status)) === 'actif';
    }

    /**
     * @param  array<string, string|null>  $v
     * @param  array<string, mixed>  $r
     * @param  array<string, SchoolClass|false>  $classes
     */
    private function lineReason(array $v, array $r, string $classKey, array $classes): ?string
    {
        foreach ($v as $value) {
            if ($value === YouCodeFileReader::INVALID) {
                return 'Valeur non admise';
            }
        }
        if (trim((string) ($v['cnie'] ?? '')) === '') {
            return 'CNIE manquante';
        }
        if (! Cnie::isValid($r['cnie'])) {
            return 'CNIE invalide';
        }
        if ($r['first_name'] === '' || $r['last_name'] === '' || mb_strlen($r['first_name']) > 100 || mb_strlen($r['last_name']) > 100) {
            return 'Prénom ou nom manquant ou trop long';
        }
        if ($r['email'] === '' || mb_strlen($r['email']) > 255 || filter_var($r['email'], FILTER_VALIDATE_EMAIL) === false) {
            return 'E-mail invalide';
        }
        if ($r['phone'] !== null && mb_strlen($r['phone']) > 30) {
            return 'Téléphone trop long';
        }
        if ($classKey === '') {
            return 'Classe manquante';
        }
        if (! array_key_exists($classKey, $classes)) {
            return 'Classe inconnue ou désactivée : vérifiez les référentiels';
        }
        if ($classes[$classKey] === false) {
            return 'Classe ambiguë dans les référentiels';
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function rejectDuplicates(array &$rows, string $field, string $reason, bool $lower = false): void
    {
        // Revue Codex T-13, constat 1 : TOUTES les lignes portant la même identité sont comptées,
        // même celles déjà rejetées pour un autre motif ; aucune occurrence ne reste importable.
        $seen = [];
        foreach ($rows as $i => $r) {
            $key = $lower ? Str::lower(trim((string) $r[$field])) : (string) $r[$field];
            if ($key === '' || ($field === 'cnie' && ! Cnie::isValid($key))) {
                continue;
            }
            $seen[$key][] = $i;
        }
        foreach ($seen as $indexes) {
            if (count($indexes) > 1) {
                foreach ($indexes as $i) {
                    $rows[$i]['reason'] ??= $reason;
                }
            }
        }
    }
}

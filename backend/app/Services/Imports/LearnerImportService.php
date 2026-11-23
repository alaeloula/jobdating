<?php

namespace App\Services\Imports;

use App\Enums\AuditAction;
use App\Jobs\ProcessLearnerImport;
use App\Models\LearnerImport;
use App\Models\Referentials\Campus;
use App\Models\Referentials\Promotion;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Aperçu et confirmation d'un import (ADM-04, plan T-13 v2.1, §2 et §3). Le fichier n'est jamais
 * conservé : il est relu à la confirmation (décision 6).
 */
class LearnerImportService
{
    public const QUEUE_CONNECTION = 'database-imports';

    public const QUEUE = 'imports';

    public function __construct(
        private readonly YouCodeFileReader $reader,
        private readonly LearnerImportPlanner $planner,
        private readonly AuditLogger $audit,
        private readonly LearnerImportRunner $runner,
    ) {}

    /**
     * @return array{campus: Campus, promotion: Promotion, plan: array{rows: list<array<string, mixed>>, counts: array{create: int, update: int, unchanged: int, reject: int}, classes: list<array{class: string, create: int, update: int, unchanged: int}>, rejections: list<array{line: int, reason: string}>, fingerprint: string}}
     */
    public function preview(UploadedFile $file, int $campusId, int $promotionId): array
    {
        [$campus, $promotion] = $this->references($campusId, $promotionId);
        $rows = $this->reader->read((string) $file->getRealPath(), $file->getClientOriginalName());
        $hash = (string) hash_file('sha256', (string) $file->getRealPath());

        return [
            'campus' => $campus,
            'promotion' => $promotion,
            'plan' => $this->planner->plan($rows, $hash, $campus, $promotion),
        ];
    }

    /**
     * Confirmation idempotente : même clé et même empreinte → même import ; même clé et autre
     * empreinte → refus (plan v2.1). Empreinte différente de l'aperçu → 409, rien n'est écrit.
     */
    public function confirm(UploadedFile $file, int $campusId, int $promotionId, string $fingerprint, string $key, User $admin): LearnerImport
    {
        $existing = LearnerImport::query()->where('confirmation_key', $key)->first();
        if ($existing !== null) {
            return $this->sameRequest($existing, $fingerprint);
        }

        $preview = $this->preview($file, $campusId, $promotionId);
        $plan = $preview['plan'];
        if (! hash_equals($plan['fingerprint'], $fingerprint)) {
            throw new ImportChangedException;
        }
        if ($plan['counts']['create'] + $plan['counts']['update'] === 0) {
            throw ValidationException::withMessages(['file' => 'Aucun apprenant à créer ni à mettre à jour : rien à importer.']);
        }

        // Lignes valides, inchangées comprises : elles sont revérifiées sous les verrous au traitement
        // (revue Codex T-13, constat 7), sans être réécrites.
        $payload = array_values(array_filter($plan['rows'], fn (array $r) => $r['decision'] !== LearnerImportPlanner::REJECT));
        $payload = array_map(fn (array $r) => array_intersect_key($r, array_flip([
            'line', 'decision', 'cnie', 'first_name', 'last_name', 'email', 'phone', 'active', 'learner_id', 'user_id', 'class_id',
        ])), $payload);

        try {
            return DB::transaction(function () use ($preview, $plan, $payload, $file, $fingerprint, $key, $admin): LearnerImport {
                $import = new LearnerImport;
                $import->forceFill([
                    'user_id' => $admin->id,
                    'campus_id' => $preview['campus']->id,
                    'promotion_id' => $preview['promotion']->id,
                    'filename' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'confirmation_key' => $key,
                    'fingerprint' => $fingerprint,
                    'status' => LearnerImport::PENDING,
                    'created_count' => $plan['counts']['create'],
                    'updated_count' => $plan['counts']['update'],
                    'unchanged_count' => $plan['counts']['unchanged'],
                    'rejected_count' => $plan['counts']['reject'],
                    'class_counts' => $plan['classes'],
                    'rejections' => $plan['rejections'],
                    'payload' => ['rows' => $payload],
                ])->save();

                $this->audit->record(AuditAction::LearnerImportConfirmed, $import, new: [
                    'fichier' => $import->filename,
                    'campus' => $preview['campus']->displayLabel(),
                    'promotion' => $preview['promotion']->displayLabel(),
                    'classes' => implode(', ', array_column($plan['classes'], 'class')),
                    'a_creer' => $plan['counts']['create'],
                    'a_mettre_a_jour' => $plan['counts']['update'],
                    'inchangees' => $plan['counts']['unchanged'],
                    'rejetees' => $plan['counts']['reject'],
                ], actor: $admin);

                // Mise en file APRÈS la validation : la tâche ne transporte que l'identifiant.
                DB::afterCommit(function () use ($import): void {
                    try {
                        ProcessLearnerImport::dispatch($import->id)->onConnection(self::QUEUE_CONNECTION)->onQueue(self::QUEUE);
                    } catch (Throwable $e) {
                        Log::error('Mise en file de l’import impossible', ['import' => $import->id, 'exception' => $e::class]);
                        $this->runner->markFailed($import->id, LearnerImportRunner::TECHNICAL_ERROR);
                    }
                });

                return $import;
            });
        } catch (UniqueConstraintViolationException) {
            // Deux confirmations simultanées avec la même clé.
            return $this->sameRequest(LearnerImport::query()->where('confirmation_key', $key)->firstOrFail(), $fingerprint);
        }
    }

    private function sameRequest(LearnerImport $import, string $fingerprint): LearnerImport
    {
        if (! hash_equals($import->fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['confirmation_key' => 'Cette confirmation a déjà servi à un autre import.']);
        }

        return $import;
    }

    /**
     * @return array{0: Campus, 1: Promotion}
     */
    private function references(int $campusId, int $promotionId): array
    {
        $campus = Campus::query()->whereKey($campusId)->where('active', true)->first();
        $promotion = Promotion::query()->whereKey($promotionId)->where('active', true)->first();
        $errors = array_filter([
            'campus_id' => $campus === null ? 'Ce campus n’est pas proposé.' : null,
            'promotion_id' => $promotion === null ? 'Cette promotion n’est pas proposée.' : null,
        ]);
        if ($errors !== [] || $campus === null || $promotion === null) {
            throw ValidationException::withMessages($errors);
        }

        return [$campus, $promotion];
    }
}

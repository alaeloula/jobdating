<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Service unique d'écriture du journal d'audit (TRA-01).
 *
 * Enregistre l'auteur, l'action, l'élément concerné, les valeurs avant et après, l'adresse IP
 * et la date. Ne conserve jamais de mot de passe ni de jeton ; la CNIE est masquée.
 */
class AuditLogger
{
    /**
     * Clés jamais enregistrées : fragments recherchés dans le nom de clé normalisé
     * (minuscules, sans accents, sans espaces, tirets ni soulignés), à toute profondeur.
     */
    private const SECRET_KEY_PARTS = ['password', 'passwd', 'pwd', 'motdepasse', 'token', 'secret', 'apikey', 'credential'];

    /** Clés secrètes courtes, reconnues seulement en entier (pour ne pas exclure « passage »…). */
    private const SECRET_KEYS = ['pass', 'mdp', 'pin', 'otp'];

    /** Auteur affiché pour une action lancée hors requête web (commande, seeder, planificateur). */
    public const SYSTEM_LABEL = 'Système';

    /** Clés masquées (seuls les 2 derniers caractères restent visibles). */
    private const MASKED_KEYS = ['cnie'];

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        AuditAction $action,
        ?Model $subject = null,
        array $old = [],
        array $new = [],
        ?User $actor = null,
    ): AuditLog {
        $actor ??= $this->currentUser();
        // Hors requête web (commande, seeder, planificateur) : auteur « Système », pas d'adresse IP.
        $web = request()->route() !== null;

        return AuditLog::query()->create([
            'user_id' => $actor?->getKey(),
            'actor_label' => match (true) {
                $actor !== null => self::userLabel($actor),
                ! $web => self::SYSTEM_LABEL,
                default => null,
            },
            'action' => $action,
            'category' => $action->category(),
            'subject_type' => $subject !== null ? Str::snake(class_basename($subject)) : null,
            'subject_id' => $subject?->getKey(),
            'subject_label' => $subject instanceof User ? self::userLabel($subject) : null,
            'old_values' => $old === [] ? null : $this->sanitize($old),
            'new_values' => $new === [] ? null : $this->sanitize($new),
            'ip' => $web ? request()->ip() : null,
            'created_at' => now(),
        ]);
    }

    /**
     * Purge annuelle (EXI-DATA-02) : supprime UNIQUEMENT les lignes du journal d'audit
     * de plus d'un an et un jour. Aucune autre table n'est touchée.
     *
     * Le jour supplémentaire garantit une conservation d'au moins un an et évite qu'un écart
     * de fuseau entre PHP et MySQL fasse refuser la purge par le déclencheur (seuil exact d'un an).
     */
    public function purge(): int
    {
        return AuditLog::query()->where('created_at', '<', now()->subYear()->subDay())->toBase()->delete();
    }

    /**
     * Libellé lisible d'un compte : « Prénom Nom (identifiant) ».
     */
    public static function userLabel(User $user): string
    {
        $identifier = $user->username ?? $user->email ?? '#'.$user->getKey();

        return trim($user->first_name.' '.$user->last_name).' ('.$identifier.')';
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $lower = Str::lower((string) $key);
            $normalized = (string) preg_replace('/[^a-z0-9]/', '', Str::lower(Str::ascii((string) $key)));

            if (Str::contains($normalized, self::SECRET_KEY_PARTS) || in_array($normalized, self::SECRET_KEYS, true)) {
                continue;
            }

            if (in_array($lower, self::MASKED_KEYS, true) && is_string($value) && $value !== '') {
                $clean[$key] = str_repeat('*', max(0, mb_strlen($value) - 2)).mb_substr($value, -2);

                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }

    private function currentUser(): ?User
    {
        $user = Auth::guard('web')->user();

        return $user instanceof User ? $user : null;
    }
}

<?php

namespace App\Services\Auth;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fermeture des sessions d'un compte (RG-AUTH-12), pilote de session `database`.
 *
 * Première protection : suppression des lignes de la table `sessions`. Seconde protection :
 * l'empreinte du mot de passe gardée dans chaque session, vérifiée à chaque requête par le
 * middleware AuthenticateSession de Sanctum ; une session réécrite après coup par une requête
 * en cours garde l'ancienne empreinte et est donc refusée à la requête suivante.
 */
class SessionRevoker
{
    /** Ferme toutes les sessions du compte (réinitialisation). */
    public function closeAll(User $user): void
    {
        DB::table((string) config('session.table', 'sessions'))->where('user_id', $user->getKey())->delete();
        $this->rotateRememberToken($user);
    }

    /** Ferme toutes les sessions du compte sauf la session courante (changement depuis le profil). */
    public function closeOthers(User $user, string $currentSessionId): void
    {
        DB::table((string) config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $this->rotateRememberToken($user);
    }

    /**
     * Après un changement de mot de passe depuis le profil : ferme les autres sessions (RG-AUTH-12),
     * SAUF pour un compte entreprise (décision du 17/11/2026) dont les autres sessions restent
     * ouvertes avec l'empreinte du nouveau mot de passe.
     *
     * @param  string  $previousPasswordHash  hachage du mot de passe AVANT le changement (en mémoire seulement)
     * @return bool vrai si les autres sessions ont été fermées
     */
    public function afterPasswordChange(User $user, string $currentSessionId, #[\SensitiveParameter] string $previousPasswordHash): bool
    {
        if ($user->currentRole() === Role::Entreprise) {
            $this->refreshOtherFingerprints($user, $currentSessionId, $previousPasswordHash);

            return false;
        }

        $this->closeOthers($user, $currentSessionId);

        return true;
    }

    /**
     * Met à jour l'empreinte du mot de passe (clé « password_hash_web », vérifiée par le middleware
     * AuthenticateSession de Sanctum) dans les autres sessions du compte entreprise.
     *
     * Seules les sessions encore VALABLES (empreinte du mot de passe en vigueur juste avant le
     * changement) sont conservées. Une session à l'empreinte périmée — par exemple réécrite après
     * une réinitialisation — ou illisible est SUPPRIMÉE : elle ne doit jamais redevenir valable
     * (contre-revue Codex T-05, constat 6).
     */
    private function refreshOtherFingerprints(User $user, string $currentSessionId, #[\SensitiveParameter] string $previousPasswordHash): void
    {
        $key = 'password_hash_web';
        $guard = Auth::guard('web');
        $fingerprint = $guard->hashPasswordForCookie($user->getAuthPassword());
        // Formats acceptés par Sanctum : empreinte HMAC (actuelle) ou hachage brut (ancien format).
        $previous = [$guard->hashPasswordForCookie($previousPasswordHash), $previousPasswordHash];

        $table = (string) config('session.table', 'sessions');
        $rows = DB::table($table)
            ->where('user_id', $user->getKey())
            ->where('id', '!=', $currentSessionId)
            ->lockForUpdate()
            ->get(['id', 'payload']);

        foreach ($rows as $row) {
            $data = @unserialize(base64_decode((string) $row->payload), ['allowed_classes' => false]);
            $stored = is_array($data) ? ($data[$key] ?? null) : null;

            if (! is_array($data) || ! is_string($stored)
                || ! (hash_equals($previous[0], $stored) || hash_equals($previous[1], $stored))) {
                DB::table($table)->where('id', $row->id)->delete();

                continue;
            }

            $data[$key] = $fingerprint;
            DB::table($table)->where('id', $row->id)->update(['payload' => base64_encode(serialize($data))]);
        }
    }

    private function rotateRememberToken(User $user): void
    {
        $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
    }
}

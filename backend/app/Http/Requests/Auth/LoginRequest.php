<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Connexion par e-mail ou nom d'utilisateur (AUTH-01).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'identifier.required' => 'Saisissez votre e-mail ou votre nom d\'utilisateur.',
            'identifier.*' => 'E-mail ou nom d\'utilisateur invalide.',
            'password.required' => 'Saisissez votre mot de passe.',
            'password.*' => 'Mot de passe invalide.',
        ];
    }

    public function identifier(): string
    {
        return trim($this->string('identifier')->toString());
    }
}

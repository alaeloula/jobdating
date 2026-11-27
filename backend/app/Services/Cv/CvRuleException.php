<?php

namespace App\Services\Cv;

use RuntimeException;

/** Refus métier d'une opération sur un CV (plan T-20 v1.2, § 5 et § 6). */
final class CvRuleException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode, public readonly int $status)
    {
        parent::__construct($message);
    }

    public static function learnerDeleted(): self
    {
        return new self('Apprenant supprimé : restaurez-le d’abord.', 'learner_deleted', 409);
    }

    public static function noCv(): self
    {
        return new self('Cet apprenant n’a pas de CV.', 'no_cv', 409);
    }

    /** Interblocage ou attente de verrou trop longue : aucune reprise automatique (contre-revue, N1). */
    public static function retry(): self
    {
        return new self('Opération concurrente sur ce CV : réessayez.', 'retry', 409);
    }

    public static function notSaved(): self
    {
        return new self('Le CV n’a pas pu être enregistré, réessayez.', 'cv_not_saved', 500);
    }
}

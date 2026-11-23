<?php

namespace App\Services\Imports;

use RuntimeException;

/**
 * Une ligne confirmée n'est plus valide au moment du traitement (CNIE ou e-mail pris entre-temps,
 * fiche supprimée, référentiel désactivé) : l'import échoue sans rien écrire. Usage interne.
 */
class ImportConflictException extends RuntimeException {}

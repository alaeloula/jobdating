<?php

namespace App\Contracts;

/**
 * Élément dont le libellé lisible est recopié dans le journal d'audit (subject_label).
 */
interface HasAuditLabel
{
    public function auditLabel(): string;
}

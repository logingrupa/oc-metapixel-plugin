<?php

namespace Logingrupa\Metapixel\Classes\Adapter;

/**
 * Marker subinterface declaring the PK-based subject hydration contract used
 * by the hybrid AJAX path (ThemeAjaxHandler offer-switch endpoint). Adapters
 * that need to be resolvable from a `subject_type` alias + integer PK opt in
 * by implementing this interface instead of the base EventSubjectAdapter.
 *
 * Adapters MUST re-enforce the subject's domain guards inside loadSubject —
 * active scope, soft-delete scope, site-match — bypass-via-AJAX is exploitable
 * otherwise. Return null when subject is missing, inactive, soft-deleted, or
 * fails site-match.
 *
 * Keeping this contract on a subinterface (NOT the base EventSubjectAdapter)
 * preserves the 10 invariants enforced by Phase 2's
 * EventSubjectAdapterContractTestCase.
 */
interface SupportsHybridAjax extends EventSubjectAdapter
{
    /**
     * Hydrate the subject from PK + arbitrary context. Return null when
     * subject is missing, inactive, soft-deleted, or fails site-match.
     * Adapters MUST re-enforce subject's domain guards inside this method
     * — bypass-via-AJAX is exploitable otherwise.
     *
     * @param  array<string, mixed>  $arContext
     */
    public function loadSubject(int $iSubjectId, array $arContext): ?object;
}

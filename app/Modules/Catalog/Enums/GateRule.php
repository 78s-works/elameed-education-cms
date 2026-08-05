<?php

namespace App\Modules\Catalog\Enums;

/**
 * Per-part progression gate (VD change set §7, LP-13). Supersedes the blanket
 * doc-11 "submitted-not-passed" rule — each quiz/homework part now declares how
 * it gates the next part:
 *
 *   must_submit — an attempt exists (attempted only).
 *   must_pass   — best attempt score ≥ threshold (pass_mode/pass_value on the
 *                 backing exam), OR a teacher pass-override row exists (LP-D3).
 */
enum GateRule: string
{
    case MustPass = 'must_pass';
    case MustSubmit = 'must_submit';
}

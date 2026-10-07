<?php

namespace App\Support;

use App\Models\Role;

/**
 * Single source of truth for "who can create which role" and the per-role
 * account-shape rules from the Blueprint (referral code ownership, mandatory
 * agent linkage). Both UserManagementService (enforcement) and UserPolicy
 * (authorization gate) read from here so the rule is never defined twice.
 */
class HierarchyRules
{
    /** @var array<string, list<string>> creator role slug => roles it may create */
    public const ALLOWED_CREATIONS = [
        'super_admin' => ['agen'],
        'agen' => ['korsal', 'sales', 'admin', 'keuangan', 'kurir', 'gudang', 'sales-kurir-sub', 'koordinator-kurir'],
        'korsal' => ['sales', 'sales-kurir-sub'],
        // A1-02 — the Human-approved Agen → Koordinator Kurir → Kurir leg: a
        // Koordinator-Kurir manages ONLY eligible Kurir within its own branch.
        'koordinator-kurir' => ['kurir'],
    ];

    /** Roles that own a referral_code. */
    public const ROLES_WITH_REFERRAL_CODE = ['agen', 'korsal', 'sales', 'sales-kurir-sub'];

    /** Explicit prefixes preserve semantic uniqueness when role names change. */
    public const REFERRAL_PREFIXES = [
        'agen' => 'AG-',
        'korsal' => 'KO-',
        'sales' => 'SA-',
        // R-01: only NEW Sales-Kurir-Sub codes get SS-. Historical SA-*/SK-* codes are never rewritten
        // (Sales -> Sales-Kurir-Sub conversion also keeps the user's existing code).
        'sales-kurir-sub' => 'SS-',
    ];

    /** Roles that must always have a non-null agent_id ("wajib terhubung ke agen"). */
    public const ROLES_REQUIRING_AGENT_LINK = ['korsal', 'sales', 'konsumen', 'admin', 'keuangan', 'kurir', 'gudang', 'sales-kurir-sub', 'koordinator-kurir'];

    public static function canCreate(string $creatorRoleSlug, string $targetRoleSlug): bool
    {
        return in_array(Role::canonicalSlug($targetRoleSlug), self::ALLOWED_CREATIONS[Role::canonicalSlug($creatorRoleSlug)] ?? [], true);
    }

    public static function ownsReferralCode(string $roleSlug): bool
    {
        return in_array(Role::canonicalSlug($roleSlug), self::ROLES_WITH_REFERRAL_CODE, true);
    }

    public static function requiresAgentLink(string $roleSlug): bool
    {
        return in_array(Role::canonicalSlug($roleSlug), self::ROLES_REQUIRING_AGENT_LINK, true);
    }
}

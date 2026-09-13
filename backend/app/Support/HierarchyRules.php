<?php

namespace App\Support;

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
        'agen' => ['korsal', 'sales', 'admin', 'keuangan', 'kurir'],
        'korsal' => ['sales'],
    ];

    /** Roles that own a referral_code (Blueprint: agen/korsal/sales do, konsumen/admin/keuangan/kurir don't). */
    public const ROLES_WITH_REFERRAL_CODE = ['agen', 'korsal', 'sales'];

    /** Roles that must always have a non-null agent_id ("wajib terhubung ke agen"). */
    public const ROLES_REQUIRING_AGENT_LINK = ['korsal', 'sales', 'konsumen', 'admin', 'keuangan', 'kurir'];

    public static function canCreate(string $creatorRoleSlug, string $targetRoleSlug): bool
    {
        return in_array($targetRoleSlug, self::ALLOWED_CREATIONS[$creatorRoleSlug] ?? [], true);
    }

    public static function ownsReferralCode(string $roleSlug): bool
    {
        return in_array($roleSlug, self::ROLES_WITH_REFERRAL_CODE, true);
    }

    public static function requiresAgentLink(string $roleSlug): bool
    {
        return in_array($roleSlug, self::ROLES_REQUIRING_AGENT_LINK, true);
    }
}

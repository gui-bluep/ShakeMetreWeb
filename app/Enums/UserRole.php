<?php

namespace App\Enums;

use Illuminate\Support\Facades\Log;

/**
 * Mirrors the FileMaker privilege sets ShakeDesign accounts carry (ZUSR::PrivilegeSet).
 *
 * The mapping is deliberately closed and defaults to the least privilege: an unrecognised
 * privilege set becomes `readonly`, never `admin`. A new privilege set appearing in
 * ShakeDesign must therefore be granted here explicitly rather than inheriting write access
 * by accident.
 *
 * That default is right, but on its own it is silent: an account whose privilege set is
 * merely spelled differently than expected lands on readonly with no trace, and the symptom
 * surfaces far away as "every field is greyed out and I cannot type". So the default now also
 * logs a warning naming the value it did not recognise - the safe behaviour is unchanged, it
 * is just no longer invisible.
 */
enum UserRole: string
{
    case Admin = 'admin';

    case User = 'user';

    case ReadOnly = 'readonly';

    /**
     * ShakeDesign's three custom privilege sets are Admin, Manager and User, and all three
     * are meant to write. `Administrateur` is listed alongside `Admin` because that is the
     * spelling the live API_ZUSR layout actually returns - confirmed against real data, not
     * inferred - so both are honoured rather than betting on which one appears.
     *
     * Manager maps to User rather than Admin deliberately. Both write, which is the stated
     * requirement, and today `canWrite()` is the only thing any role gates - so the two are
     * behaviourally identical. Mapping it to the lesser of them means that if an admin-only
     * power is ever added, Manager does not silently acquire it.
     *
     * The bracketed names are FileMaker's own built-in sets. `[Data Entry Only]` is
     * deliberately absent: it would plausibly map to User, but mapping it on that reasoning
     * would be granting write access by inference. Likewise no French spelling of Manager or
     * User is guessed at here - if `Gestionnaire` or `Utilisateur` turns out to be what the
     * layout returns, the warning logged below names it and it gets added then.
     */
    public static function fromPrivilegeSet(?string $privilegeSet): self
    {
        $normalized = trim((string) $privilegeSet);

        return match ($normalized) {
            '[Full Access]' => self::Admin,
            'Admin' => self::Admin,
            'Administrateur' => self::Admin,
            'Manager' => self::User,
            'User' => self::User,
            '[Read-Only Access]' => self::ReadOnly,
            default => self::unrecognised($normalized),
        };
    }

    /** Whether this role may change anything at all. */
    public function canWrite(): bool
    {
        return $this !== self::ReadOnly;
    }

    /**
     * Least privilege, but loudly: whoever sees an account unexpectedly read-only needs the
     * unrecognised value itself to fix the mapping, and an empty value (the field missing
     * from the API layout entirely) has to be distinguishable from a genuinely unknown one.
     */
    private static function unrecognised(string $privilegeSet): self
    {
        Log::warning('ShakeDesign privilege set not recognised; defaulting to readonly.', [
            'privilege_set' => $privilegeSet === '' ? '(empty or absent from the API_ZUSR layout)' : $privilegeSet,
        ]);

        return self::ReadOnly;
    }
}

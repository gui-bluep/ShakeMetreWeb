<?php

namespace App\Enums;

/**
 * Mirrors the FileMaker privilege sets ShakeDesign accounts carry (ZUSR::PrivilegeSet).
 *
 * The mapping is deliberately closed and defaults to the least privilege: an unrecognised
 * privilege set becomes `readonly`, never `admin`. A new privilege set appearing in
 * ShakeDesign must therefore be granted here explicitly rather than inheriting write access
 * by accident.
 */
enum UserRole: string
{
    case Admin = 'admin';

    case User = 'user';

    case ReadOnly = 'readonly';

    /**
     * The bracketed names are FileMaker's own built-in privilege sets; the bare ones are
     * ShakeDesign's custom sets.
     *
     * `[Data Entry Only]` is FileMaker's remaining built-in set and is deliberately absent: it
     * would plausibly map to User, but mapping it on that reasoning would be granting write
     * access by inference, which is the one thing this mapping refuses to do. It currently
     * lands on ReadOnly like anything unrecognised, and needs an explicit decision to change.
     */
    public static function fromPrivilegeSet(?string $privilegeSet): self
    {
        return match (trim((string) $privilegeSet)) {
            '[Full Access]' => self::Admin,
            'Admin' => self::Admin,
            'User' => self::User,
            '[Read-Only Access]' => self::ReadOnly,
            default => self::ReadOnly,
        };
    }

    /** Whether this role may change anything at all. */
    public function canWrite(): bool
    {
        return $this !== self::ReadOnly;
    }
}

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

    public static function fromPrivilegeSet(?string $privilegeSet): self
    {
        return match (trim((string) $privilegeSet)) {
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

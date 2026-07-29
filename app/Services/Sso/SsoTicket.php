<?php

namespace App\Services\Sso;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One-time login tickets for users arriving from ShakeDesign.
 *
 * The token is a bearer secret in a URL, so it is built to be worth as little as possible:
 * 32 random bytes, valid for a minute, and consumed on first use. It carries no identity of
 * its own - only a cache key pointing at a user id - so a leaked token grants nothing once
 * redeemed or expired, and reveals nothing about who it belonged to.
 */
class SsoTicket
{
    /** Long enough that guessing is hopeless; the URL is not meant to be typed. */
    private const TOKEN_BYTES = 32;

    /** Just enough to survive a redirect between the two applications. */
    public const TTL_SECONDS = 60;

    private const CACHE_PREFIX = 'sso:ticket:';

    /**
     * @return string the opaque token to hand back
     */
    public function issueFor(string $userId): string
    {
        $token = Str::random(self::TOKEN_BYTES * 2); // hex-ish length, 64 chars

        Cache::put(self::CACHE_PREFIX.$token, $userId, self::TTL_SECONDS);

        return $token;
    }

    /**
     * Redeems a token, returning the user id it stood for.
     *
     * `pull` rather than `get`: the entry is removed as it is read, so a replayed URL finds
     * nothing. Doing this in two steps would leave a window in which the same ticket could be
     * consumed twice.
     *
     * @return string|null null when the token is unknown, expired, or already used
     */
    public function consume(string $token): ?string
    {
        $userId = Cache::pull(self::CACHE_PREFIX.$token);

        return is_string($userId) && $userId !== '' ? $userId : null;
    }
}

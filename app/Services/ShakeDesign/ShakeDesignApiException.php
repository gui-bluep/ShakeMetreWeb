<?php

namespace App\Services\ShakeDesign;

use RuntimeException;

/**
 * A ShakeDesign FileMaker Data API call failed.
 *
 * `$fileMakerCode` carries FileMaker's own error code - the `messages[].code` of the
 * response body - because in the Data API that code, not the HTTP status, is what
 * identifies the failure. The two genuinely disagree: a search matching nothing comes
 * back as FileMaker code 401 ("No records match the request"), which has nothing to do
 * with HTTP 401 / an expired session (FileMaker code 952).
 *
 * So callers distinguish "not found" from "broken" on the code, never the status:
 *
 *     try { $project = $client->findProject($zkp); }
 *     catch (ShakeDesignApiException $e) {
 *         if ($e->isNotFound()) { ... } else { throw $e; }
 *     }
 *
 * @see https://help.claris.com/en/pro-help/content/error-codes.html
 */
class ShakeDesignApiException extends RuntimeException
{
    /** FileMaker: "No records match the request". */
    public const CODE_NO_RECORDS = '401';

    /** FileMaker: "Invalid FileMaker Data API token". */
    public const CODE_INVALID_TOKEN = '952';

    public function __construct(
        string $message,
        public readonly ?string $fileMakerCode = null,
        public readonly ?string $fileMakerMessage = null,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($message);
    }

    public static function fromResponse(
        string $context,
        ?string $code,
        ?string $message,
        ?int $status,
    ): self {
        return new self(
            sprintf(
                'ShakeDesign %s failed: FileMaker error %s "%s" (HTTP %s).',
                $context,
                $code ?? 'unknown',
                $message ?? 'no message',
                $status ?? 'none',
            ),
            $code,
            $message,
            $status,
        );
    }

    public static function notConfigured(string $key): self
    {
        return new self(
            "ShakeDesign is not configured: services.shakedesign.{$key} is empty. ".
            'Set the SHAKEDESIGN_* environment variables.'
        );
    }

    /**
     * The header was written but a line was not, so ShakeDesign now holds a header with
     * no (or incomplete) lines. Deliberately not rolled back: deleting a record that
     * FileMaker scripts and stored foreign keys may already reference is more dangerous
     * than leaving it for a human to inspect. The orphan's zkp is in the message.
     */
    public static function orphanedHeader(
        string $entity,
        string $headerZkp,
        int $lineIndex,
        self $cause,
    ): self {
        return new self(
            sprintf(
                'ShakeDesign %s header %s was created but line index %d failed, so the header is '.
                'orphaned in ShakeDesign and was NOT deleted - investigate manually. Cause: %s',
                $entity,
                $headerZkp,
                $lineIndex,
                $cause->getMessage(),
            ),
            $cause->fileMakerCode,
            $cause->fileMakerMessage,
            $cause->httpStatus,
        );
    }

    /**
     * Same situation as orphanedHeader(), but reading the header back to learn its zkp is
     * what failed - so only the internal recordId is available to find it by.
     */
    public static function orphanedHeaderWithoutKey(
        string $entity,
        string $headerRecordId,
        string $layout,
        self $cause,
    ): self {
        return new self(
            sprintf(
                'ShakeDesign %s header was created (recordId %s on layout %s) but reading it back '.
                'to get its zkp failed, so no line was written. The header is orphaned in '.
                'ShakeDesign and was NOT deleted - investigate manually. Cause: %s',
                $entity,
                $headerRecordId,
                $layout,
                $cause->getMessage(),
            ),
            $cause->fileMakerCode,
            $cause->fileMakerMessage,
            $cause->httpStatus,
        );
    }

    public function isNotFound(): bool
    {
        return $this->fileMakerCode === self::CODE_NO_RECORDS;
    }

    public function isExpiredSession(): bool
    {
        return $this->fileMakerCode === self::CODE_INVALID_TOKEN || $this->httpStatus === 401;
    }
}

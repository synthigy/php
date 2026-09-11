<?php

declare(strict_types=1);

namespace Synthigy;

/**
 * Every error the SDK raises is a SynthigyError. Discriminate on ->code
 * (stable), never ->getMessage().
 *
 * Always present: message (getMessage()), code, category, retryable.
 * The rest are populated only when the server (or transport) included them.
 */
final class SynthigyError extends \RuntimeException
{
    /** @var array<string,string> code -> category */
    private const CATEGORIES = [
        // auth — token / session / IdP issues, re-authenticate
        'UNAUTHORIZED' => 'auth',
        'CLIENT_NOT_FOUND' => 'auth',
        'CLIENT_INACTIVE' => 'auth',
        'PUBLIC_CLIENT_FORBIDDEN' => 'auth',
        'NOT_TRUSTED' => 'auth',
        'USER_NOT_FOUND' => 'auth',
        'USER_INACTIVE' => 'auth',
        'PROVISION_FORBIDDEN' => 'auth',
        'CLAIM_INVALID' => 'auth',
        'NO_TOKEN' => 'auth',
        // iam — RBAC/RLS denial, caller lacks permission
        'FORBIDDEN' => 'iam',
        'FORBIDDEN_OP' => 'iam',
        'ENTITY_FORBIDDEN' => 'iam',
        'ENTITY_NOT_READABLE' => 'iam',
        'RELATION_NOT_READABLE' => 'iam',
        // validation — the caller's request shape is invalid
        'INVALID_BODY' => 'validation',
        'NO_OPERATIONS' => 'validation',
        'UNKNOWN_OP' => 'validation',
        'UNKNOWN_OPERATOR' => 'validation',
        'MISSING_ON' => 'validation',
        'MISSING_ROOT' => 'validation',
        'MISSING_RECORDS' => 'validation',
        'EMPTY_RECORDS' => 'validation',
        'MISSING_ENTITIES' => 'validation',
        'EMPTY_ENTITIES' => 'validation',
        'MISSING_RELATIONS' => 'validation',
        'EMPTY_RELATIONS' => 'validation',
        'INVALID_RELATION_NAME' => 'validation',
        'INVALID_SUBSCRIPTION' => 'validation',
        'INVALID_OPERATIONS' => 'validation',
        'INVALID_INTEREST' => 'validation',
        'EMPTY_INTEREST' => 'validation',
        'UNSUPPORTED_TYPE' => 'validation',
        'XSQL_PARSE_ERROR' => 'validation',
        'TEMPLATE_BAD_CTE' => 'validation',
        'TEMPLATE_UNBALANCED_PARENS' => 'validation',
        'TEMPLATE_ERROR' => 'validation',
        'TEMPLATE_PARAM_ERROR' => 'validation',
        'QUERY_NOT_SELECT' => 'validation',
        'PARAM_MISSING' => 'validation',
        'PARAM_TYPE_MISMATCH' => 'validation',
        'NOT_CONNECTED' => 'validation',
        'XID_REQUIRED' => 'validation',
        'CLAIM_METHOD_NOT_ALLOWED' => 'validation',
        'PASSWORD_TOO_WEAK' => 'validation',
        'RETURN_URL_NOT_REGISTERED' => 'validation',
        // not_found — referenced entity/relation/record doesn't exist
        'UNKNOWN_ENTITY' => 'not_found',
        'UNKNOWN_RELATION' => 'not_found',
        'UNKNOWN_TEMPLATE_RELATION' => 'not_found',
        'HISTORY_UNAVAILABLE' => 'not_found',
        // conflict — constraint violation
        'CONFLICT' => 'conflict',
        'UNIQUE_VIOLATION' => 'conflict',
        'FK_VIOLATION' => 'conflict',
        'CHECK_VIOLATION' => 'conflict',
        'NOT_NULL_VIOLATION' => 'conflict',
        // network — transport failure (DNS, TCP, timeout, abort)
        'NETWORK_ERROR' => 'network',
        'TIMEOUT' => 'network',
        // internal — unexpected server error, retry-safe
        'INTERNAL_ERROR' => 'internal',
        'OPERATION_ERROR' => 'internal',
        'HTTP_ERROR' => 'internal',
    ];

    private const RETRYABLE_CATEGORIES = ['network', 'rate_limit', 'internal'];

    // NOT readonly, and NOT re-typed: \Exception already declares an
    // UNTYPED, non-readonly $code and $line — PHP forbids a child class
    // from redeclaring an inherited property with any type at all (even
    // the "same" type) once the parent left it untyped, and forbids
    // readonly outright. Both are assigned once in the constructor and
    // never touched again in practice; the language just can't enforce
    // that here the way readonly normally would.
    /** @var string */
    public $code;
    public readonly string $category;
    public readonly bool $retryable;
    public readonly mixed $details;
    public readonly ?string $hint;
    /** @var list<string>|null */
    public readonly ?array $available;
    public readonly mixed $path;
    public readonly ?string $entity;
    public readonly ?string $relation;
    public readonly ?string $operator;
    public readonly ?string $requestId;
    public readonly ?int $status;
    // Named errorLine, not line: \Exception itself declares a non-nullable
    // `int $line` (the throw site), and PHP forbids a child class from
    // redeclaring an inherited property with an incompatible (here:
    // nullable) type. This is the XSQL source line from the SERVER's
    // error payload, unrelated to the exception's own throw-site line.
    public readonly ?int $errorLine;
    public readonly ?int $col;
    public readonly mixed $start;
    public readonly mixed $end;
    /** @var list<mixed>|null */
    public readonly ?array $diagnostics;

    /**
     * @param list<string>|null $available
     * @param list<mixed>|null $diagnostics
     */
    public function __construct(
        string $message,
        string $code,
        mixed $details = null,
        ?string $hint = null,
        ?array $available = null,
        mixed $path = null,
        ?string $entity = null,
        ?string $relation = null,
        ?string $operator = null,
        ?string $requestId = null,
        ?int $status = null,
        ?int $errorLine = null,
        ?int $col = null,
        mixed $start = null,
        mixed $end = null,
        ?array $diagnostics = null,
    ) {
        parent::__construct($message);
        $this->code = $code;
        $this->category = self::CATEGORIES[$code] ?? 'internal';
        $this->retryable = in_array($this->category, self::RETRYABLE_CATEGORIES, true);
        $this->details = $details;
        $this->hint = $hint;
        $this->available = $available;
        $this->path = $path;
        $this->entity = $entity;
        $this->relation = $relation;
        $this->operator = $operator;
        $this->requestId = $requestId;
        $this->status = $status;
        $this->errorLine = $errorLine;
        $this->col = $col;
        $this->start = $start;
        $this->end = $end;
        $this->diagnostics = $diagnostics;
    }

    /**
     * The server's `available` hint is a list of names; anything else in
     * that slot is dropped rather than trusted into a typed property.
     *
     * @return list<string>|null
     */
    private static function stringListOrNull(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        return array_values(array_map(strval(...), array_filter($value, is_scalar(...))));
    }

    /**
     * Build a SynthigyError from a server-returned error object (the wire
     * shape of a /data top-level `error` or a per-op `error`), plus the
     * transport-level status and request id for cross-correlation.
     *
     * @param array<string,mixed>|null $err
     */
    public static function fromServer(?array $err, ?int $status = null, ?string $requestId = null): self
    {
        $err ??= ['message' => 'Unknown error', 'code' => 'INTERNAL_ERROR'];
        return new self(
            message: (string)($err['message'] ?? 'Unknown error'),
            code: (string)($err['code'] ?? 'INTERNAL_ERROR'),
            details: $err['details'] ?? null,
            hint: $err['hint'] ?? null,
            available: self::stringListOrNull($err['available'] ?? null),
            path: $err['path'] ?? null,
            entity: $err['entity'] ?? null,
            relation: $err['relation'] ?? null,
            operator: $err['operator'] ?? null,
            requestId: $requestId,
            status: $status,
            errorLine: $err['line'] ?? null,
            col: $err['col'] ?? null,
            start: $err['start'] ?? null,
            end: $err['end'] ?? null,
            diagnostics: is_array($err['diagnostics'] ?? null) ? array_values($err['diagnostics']) : null,
        );
    }

    public function __toString(): string
    {
        return sprintf('synthigy: %s (%s)', $this->getMessage(), $this->code);
    }
}

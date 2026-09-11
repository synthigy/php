<?php

declare(strict_types=1);

namespace Synthigy\Codegen;

/**
 * Fail-loud codegen error — printed to stderr, exits non-zero. The stale-IR
 * lesson (an older Go generator once silently defaulted an unknown op kind
 * to "search"): every unrecognized shape here is a hard stop, never a
 * best-effort guess.
 */
final class CodegenError extends \RuntimeException
{
    public function __construct(string $message)
    {
        fwrite(STDERR, "codegen: {$message}\n");
        parent::__construct($message);
    }
}

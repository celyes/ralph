<?php

declare(strict_types=1);

namespace Celyes\RalphPhp\Preflight\Check;

use Celyes\RalphPhp\Preflight\CheckId;
use Celyes\RalphPhp\Preflight\CheckResult;
use Celyes\RalphPhp\Preflight\PreflightCheck;
use Celyes\RalphPhp\Preflight\PreflightContext;
use Celyes\RalphPhp\Ralph;

/**
 * The PHP build Ralph PHP is running on.
 *
 * `bin/ralph` refuses an unsupported runtime before the application loads, so
 * this is rarely the check that catches it. It is still worth reporting: the
 * report doubles as the statement of what the package requires, and the binary
 * can be bypassed by a caller that includes the autoloader and drives the
 * application itself.
 */
final class PhpCheck implements PreflightCheck
{
    public function id(): CheckId
    {
        return CheckId::Php;
    }

    public function inspect(PreflightContext $context): CheckResult
    {
        $details = [
            sprintf('binary: %s', PHP_BINARY),
            sprintf('requires PHP %s or newer', Ralph::MINIMUM_PHP_VERSION),
        ];

        if (version_compare(PHP_VERSION, Ralph::MINIMUM_PHP_VERSION, '<')) {
            return CheckResult::failed(
                $this->id(),
                sprintf('PHP %s is older than the required %s', PHP_VERSION, Ralph::MINIMUM_PHP_VERSION),
                $details,
                [
                    'Run ralph with a newer PHP, or install it under one.',
                    'The "ralph" binary refuses to start on this runtime as well.',
                ],
            );
        }

        return CheckResult::passed(
            $this->id(),
            sprintf('PHP %s (%s)', PHP_VERSION, PHP_SAPI),
            $details,
        );
    }
}

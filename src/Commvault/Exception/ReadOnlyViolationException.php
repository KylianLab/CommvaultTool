<?php

declare(strict_types=1);

namespace App\Commvault\Exception;

/**
 * Thrown when code tries to send a request that could modify Commvault.
 * TapeTrack is strictly read-only on the Commvault side.
 */
final class ReadOnlyViolationException extends CommvaultException
{
}

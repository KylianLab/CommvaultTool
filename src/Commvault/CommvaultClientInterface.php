<?php

declare(strict_types=1);

namespace App\Commvault;

/**
 * Low-level, read-only access to the Commvault REST API.
 *
 * Phase 0 deliberately exposes raw requests only: the typed methods
 * (storage policies, jobs, media...) will be added on top once the
 * endpoint mapping (docs/commvault-mapping.md) has been validated against
 * real dumps of the CommServe.
 *
 * Implementations MUST refuse any request that could modify Commvault
 * (see ReadOnlyGuard).
 */
interface CommvaultClientInterface
{
    /**
     * @param array<string, scalar|null> $query
     */
    public function get(string $path, array $query = []): CommvaultResponse;

    /**
     * POST on an endpoint that is a query in disguise (e.g. job search).
     * Only allowlisted paths are accepted.
     *
     * @param array<mixed>               $body
     * @param array<string, scalar|null> $query
     */
    public function query(string $path, array $body = [], array $query = []): CommvaultResponse;

    /**
     * Read-only QCommand, through POST /ExecuteQCommand (form-encoded) or,
     * with $v2, POST /QCommand/<url-encoded command>.
     * Only allowlisted "qlist" / "qinfo" commands are accepted.
     */
    public function qcommand(string $command, bool $v2 = false): CommvaultResponse;
}

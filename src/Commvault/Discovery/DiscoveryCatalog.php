<?php

declare(strict_types=1);

namespace App\Commvault\Discovery;

/**
 * Candidate endpoints for Phase 0, from the Commvault OpenAPI SDK
 * (CVPowershellSDKV2), cvpysdk and the 11.40 / SP40 documentation.
 * See docs/commvault-mapping.md for what each one is expected to return.
 *
 * Order matters: IDs found by earlier probes feed the later ones. Tape
 * libraries come first so that {libraryId} points to a tape library rather
 * than a disk library, and copy-level job lists come before the generic job
 * list so that {jobId} is preferably a job written to tape.
 *
 * Everything here is read-only (GET, or POST queries allowlisted by
 * ReadOnlyGuard). Write endpoints that exist on the same resources
 * (POST /V4/Storage/Tape/{id}/Media, /DriveOperations,
 * /V4/Plan/BackupDestination/JobOperations...) are deliberately absent.
 */
final class DiscoveryCatalog
{
    /**
     * @return list<Probe>
     */
    public static function probes(\DateTimeImmutable $since, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $lookupSeconds = max(3600, $now->getTimestamp() - $since->getTimestamp());
        $window = ['view' => 'custom', 'startTime' => $since->getTimestamp(), 'endTime' => $now->getTimestamp()];

        return [
            // --- Authentication / sanity -------------------------------------------------
            new Probe('auth.whoami', 'identity of the API account (token check)', 'WhoAmI'),

            // --- Tape libraries ----------------------------------------------------------
            new Probe('library.tape_list', 'tape libraries (V4)', 'V4/Storage/Tape', extract: ['libraryId' => ['tapeList.*.id'], 'libraryName' => ['tapeList.*.name']]),
            new Probe('library.tape_detail', 'tape library details, drives (+ loaded barcode)', 'V4/Storage/Tape/{libraryId}'),
            new Probe('library.tape_media_counts', 'media counters per library', 'V4/Storage/Tape/{libraryId}/Media'),
            new Probe('library.export_locations', 'export locations (vault / off-site, isTransit)', 'V4/Storage/Tape/Locations'),
            new Probe('library.list_legacy', 'all libraries (disk + tape, legacy)', 'Library'),
            new Probe('library.detail_legacy', 'library details (legacy, libraryType, tapeLibSummary)', 'Library/{libraryId}'),

            // --- Media (tapes) -----------------------------------------------------------
            new Probe('media.in_library', 'tapes inside the library: barcode, status, slot, lastWriteTime, retainDataUntil, SP copy', 'V4/Library/{libraryId}/Media', ['filterMediaType' => 'ALL', 'isExported' => 'false']),
            new Probe('media.exported', 'exported tapes (outside the library)', 'V4/Library/{libraryId}/Media', ['filterMediaType' => 'ALL', 'isExported' => 'true']),
            new Probe('media.aged', 'aged tapes (candidates to come back)', 'V4/Library/{libraryId}/Media', ['filterMediaType' => 'AGED', 'isExported' => 'true']),
            new Probe('media.slots', 'regular slots (slot <-> media, element address?)', 'V4/Library/{libraryId}/Slot', ['slotType' => 'REGULAR_SLOT']),
            new Probe('media.mail_slots', 'I/E (mail) slots', 'V4/Library/{libraryId}/Slot', ['slotType' => 'MAIL_SLOT']),
            new Probe('media.vault_tracker', 'Vault Tracker media (location, dueBackForReuseOn)', 'V4/VaultTracker/Media'),

            // --- Storage policies & copies ----------------------------------------------
            new Probe('sp.list', 'storage policies (legacy)', 'StoragePolicy', ['getAll' => 'TRUE']),
            new Probe('sp.list_v2', 'storage policies (V2)', 'V2/StoragePolicy'),
            new Probe('sp.detail', 'storage policy with its copies (legacy)', 'StoragePolicy/{storagePolicyId}', extract: ['copyId' => ['copy.*.StoragePolicyCopy.copyId']]),
            new Probe('sp.detail_v2', 'storage policy, full properties', 'V2/StoragePolicy/{storagePolicyId}', ['propertyLevel' => 10]),
            new Probe('sp.copy', 'copy details: type, library, retention rules', 'V2/StoragePolicy/{storagePolicyId}/Copy/{copyId}'),
            new Probe('plan.destinations', 'plan backup destinations (= SP copies, V4)', 'V4/Plan/BackupDestinations'),

            // --- Jobs --------------------------------------------------------------------
            new Probe('jobs.on_copy', 'jobs on a copy with retainUntil (all levels, incl. aged)', 'V4/Plan/BackupDestination/{copyId}/Jobs', $window + ['agedData' => 2]),
            new Probe('jobs.on_copy_full_lvl', 'jobs on a copy, Full only ("backupLvl" spelling)', 'V4/Plan/BackupDestination/{copyId}/Jobs', $window + ['agedData' => 2, 'backupLvl' => 1]),
            new Probe('jobs.on_copy_full_level', 'jobs on a copy, Full only ("backupLevel" spelling)', 'V4/Plan/BackupDestination/{copyId}/Jobs', $window + ['agedData' => 2, 'backupLevel' => 1]),
            new Probe('jobs.list', 'backup jobs (backup level, client/subclient, times, status)', 'Job', ['completedJobLookupTime' => $lookupSeconds, 'jobCategory' => 'All', 'limit' => 200]),
            new Probe('jobs.search', 'job search, incl. aged jobs', 'Jobs', body: [
                'scope' => 1,
                'category' => 0,
                'pagingConfig' => ['sortDirection' => 1, 'offset' => 0, 'sortField' => 'jobId', 'limit' => 200],
                'jobFilter' => ['completedJobLookupTime' => $lookupSeconds, 'showAgedJobs' => true, 'hideAdminJobs' => true],
            ]),
            new Probe('jobs.summary', 'one job summary', 'Job/{jobId}'),
            new Probe('jobs.details', 'one job details (media used?)', 'JobDetails', body: ['jobId' => '{jobId}', 'showAttempt' => true]),
            new Probe('jobs.retention', 'job retention per copy', 'Job/{jobId}/AdvancedDetails', ['infoType' => 1]),

            // --- Job <-> media (no REST endpoint known: read-only QCommands) ------------
            Probe::qcommand('qcmd.media_of_job', 'tapes used by a job', 'qlist media -j {jobId}'),
            Probe::qcommand('qcmd.media_of_copy', 'tapes of a storage policy copy', 'qlist media -sp "{storagePolicyName}" -spc "{copyName}"'),
            Probe::qcommand('qcmd.media_of_library', 'tapes of a library', 'qlist media -l "{libraryName}"'),
            Probe::qcommand('qcmd.media_info', 'one tape details', 'qinfo media -b {barcode}'),
            Probe::qcommand('qcmd.jobs_in_copy', 'jobs in a copy (reporting stored procedure)', 'qoperation execscript -sn QS_JobsinSPCopy -si @i_policyName=\'{storagePolicyName}\' -si @i_copyName=\'{copyName}\''),
        ];
    }
}

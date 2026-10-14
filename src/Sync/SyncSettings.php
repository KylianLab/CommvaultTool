<?php

declare(strict_types=1);

namespace App\Sync;

use App\Enum\CycleGrouping;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Typed view of the TAPETRACK_* settings.
 */
final readonly class SyncSettings
{
    /** @var list<int> */
    public array $managedLibraryIds;

    /** @var array<int, list<array{int, int, int}>> libraryId => [[drawer, first, last], ...] */
    public array $defaultDrawers;

    public CycleGrouping $cycleGrouping;

    public function __construct(
        #[Autowire('%env(TAPETRACK_MANAGED_LIBRARIES)%')] string $managedLibraries,
        #[Autowire('%env(TAPETRACK_DEFAULT_DRAWERS)%')] string $defaultDrawers,
        #[Autowire('%env(int:TAPETRACK_JOB_WINDOW_DAYS)%')] public int $jobWindowDays,
        #[Autowire('%env(TAPETRACK_CYCLE_GROUPING)%')] string $cycleGrouping,
        #[Autowire('%env(int:TAPETRACK_INVENTORY_GRACE_HOURS)%')] public int $inventoryGraceHours = 72,
    ) {
        $this->managedLibraryIds = array_values(array_map('intval', array_filter(array_map('trim', explode(',', $managedLibraries)), 'is_numeric')));
        $this->defaultDrawers = self::parseDrawers($defaultDrawers);
        $this->cycleGrouping = CycleGrouping::from(trim($cycleGrouping));
    }

    /**
     * "36=1:1-20,2:21-40;26=4:1-20"
     *
     * @return array<int, list<array{int, int, int}>>
     */
    public static function parseDrawers(string $spec): array
    {
        $result = [];
        foreach (array_filter(array_map('trim', explode(';', $spec))) as $librarySpec) {
            if (!preg_match('/^(\d+)\s*=\s*(.+)$/', $librarySpec, $m)) {
                throw new \InvalidArgumentException(\sprintf('Invalid TAPETRACK_DEFAULT_DRAWERS entry "%s".', $librarySpec));
            }
            foreach (array_filter(array_map('trim', explode(',', $m[2]))) as $drawer) {
                if (!preg_match('/^(\d+)\s*:\s*(\d+)\s*-\s*(\d+)$/', $drawer, $d)) {
                    throw new \InvalidArgumentException(\sprintf('Invalid drawer "%s" in TAPETRACK_DEFAULT_DRAWERS.', $drawer));
                }
                $result[(int) $m[1]][] = [(int) $d[1], (int) $d[2], (int) $d[3]];
            }
        }

        return $result;
    }
}

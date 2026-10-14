<?php

declare(strict_types=1);

namespace App\View;

use App\Entity\Cabinet;
use App\Entity\Media;
use App\Entity\StorageLocation;
use App\Entity\StoragePolicyCopy;
use App\Enum\LocationType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Cabinets -> boxes -> backups -> tapes, plus the external site and the
 * tapes stored nowhere known yet.
 */
final readonly class CabinetsBuilder
{
    public function __construct(
        private EntityManagerInterface $em,
    ) {
    }

    /**
     * @return array{
     *     cabinets: list<array{cabinet: Cabinet, boxes: list<array<string, mixed>>}>,
     *     offsite: list<array<string, mixed>>,
     *     unlocated: array<string, list<Media>>,
     *     copiesWithoutBox: list<StoragePolicyCopy>
     * }
     */
    public function build(): array
    {
        $outside = $this->em->createQuery('SELECT m FROM '.Media::class.' m WHERE m.physicalState IN (:states) ORDER BY m.retainUntil ASC, m.barcode ASC')
            ->setParameter('states', ['ejected', 'stored', 'in_transit', 'offsite'])
            ->getResult();

        $byLocation = [];
        $unlocated = [];
        foreach ($outside as $media) {
            if (null === $media->getLocation()) {
                $unlocated[$media->getCopy()?->getLabel() ?? 'Sans storage policy'][] = $media;
            } else {
                $byLocation[$media->getLocation()->getId()][] = $media;
            }
        }
        ksort($unlocated);

        $copiesByLocation = [];
        $copiesWithoutBox = [];
        foreach ($this->em->getRepository(StoragePolicyCopy::class)->findAll() as $copy) {
            if (null !== $copy->getStorageLocation()) {
                $copiesByLocation[$copy->getStorageLocation()->getId()][] = $copy;
            }
        }
        foreach ($unlocated as $tapes) {
            foreach ($tapes as $media) {
                if (null !== $media->getCopy() && null === $media->getCopy()->getStorageLocation()) {
                    $copiesWithoutBox[$media->getCopy()->getId()] = $media->getCopy();
                }
            }
        }

        $box = fn (StorageLocation $location) => $this->box($location, $byLocation[$location->getId()] ?? [], $copiesByLocation[$location->getId()] ?? []);

        $cabinets = [];
        foreach ($this->em->getRepository(Cabinet::class)->findBy([], ['room' => 'ASC', 'name' => 'ASC']) as $cabinet) {
            $cabinets[] = ['cabinet' => $cabinet, 'boxes' => array_map($box, $cabinet->getLocations()->toArray())];
        }
        $offsite = array_map($box, $this->em->getRepository(StorageLocation::class)->findBy(['type' => LocationType::Offsite], ['name' => 'ASC']));

        return [
            'cabinets' => $cabinets,
            'offsite' => $offsite,
            'unlocated' => $unlocated,
            'copiesWithoutBox' => array_values($copiesWithoutBox),
        ];
    }

    /**
     * @param list<Media>             $media
     * @param list<StoragePolicyCopy> $copies
     *
     * @return array<string, mixed>
     */
    private function box(StorageLocation $location, array $media, array $copies): array
    {
        $cycles = [];
        $noCycle = [];
        foreach ($media as $m) {
            if (null === $m->getCycle()) {
                $noCycle[] = $m;
            } else {
                $cycles[$m->getCycle()->getId()]['cycle'] = $m->getCycle();
                $cycles[$m->getCycle()->getId()]['media'][] = $m;
            }
        }
        usort($cycles, static fn ($a, $b) => $a['cycle']->getRetentionDate() <=> $b['cycle']->getRetentionDate());

        $retentions = array_filter(array_map(static fn (Media $m) => $m->getRetainUntil(), $media));

        return [
            'location' => $location,
            'copies' => $copies,
            'cycles' => $cycles,
            'noCycle' => $noCycle,
            'count' => \count($media),
            'toReturn' => \count(array_filter($media, static fn (Media $m) => $m->isToReturn())),
            'nextExpiry' => [] === $retentions ? null : min($retentions),
        ];
    }
}

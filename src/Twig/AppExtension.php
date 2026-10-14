<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\SyncRun;
use App\Enum\SyncStatus;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

final class AppExtension extends AbstractExtension
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('last_sync', $this->lastSync(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('ago', $this->ago(...)),
            new TwigFilter('bytes', $this->bytes(...)),
        ];
    }

    /** Last finished run (header badge). */
    public function lastSync(): ?SyncRun
    {
        return $this->em->createQuery('SELECT r FROM '.SyncRun::class.' r WHERE r.status IN (:s) ORDER BY r.startedAt DESC, r.id DESC')
            ->setParameter('s', [SyncStatus::Success->value, SyncStatus::Failed->value])
            ->setMaxResults(1)
            ->getOneOrNullResult();
    }

    /** "il y a 3 min", "dans 2 j" */
    public function ago(?\DateTimeInterface $at): string
    {
        if (null === $at) {
            return '—';
        }
        $delta = $this->clock->now()->getTimestamp() - $at->getTimestamp();
        $abs = abs($delta);
        $text = match (true) {
            $abs < 60 => 'quelques secondes',
            $abs < 3600 => intdiv($abs, 60).' min',
            $abs < 86400 => intdiv($abs, 3600).' h',
            default => intdiv($abs, 86400).' j',
        };

        return $delta >= 0 ? 'il y a '.$text : 'dans '.$text;
    }

    public function bytes(int $bytes): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To', 'Po'];
        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1000 && $i < \count($units) - 1) {
            $value /= 1000;
            ++$i;
        }

        return number_format($value, $i > 0 ? 1 : 0, ',', ' ').' '.$units[$i];
    }
}

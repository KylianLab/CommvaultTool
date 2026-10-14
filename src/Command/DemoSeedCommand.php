<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Cabinet;
use App\Entity\Media;
use App\Entity\StorageLocation;
use App\Entity\StoragePolicyCopy;
use App\Enum\LocationType;
use App\Enum\PhysicalState;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Demo storage referential on top of the demo Commvault fixtures: cabinets in
 * room +6I21, one box per copy, the external site, and physical states for
 * the tapes already outside. Only in fake mode, never on real data.
 */
#[AsCommand(name: 'app:demo:seed', description: 'Creates demo cabinets / boxes / external site (fake Commvault mode only)')]
final class DemoSeedCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ClockInterface $clock,
        #[Autowire('%env(COMMVAULT_CLIENT)%')] private readonly string $clientMode,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if ('fake' !== $this->clientMode) {
            $io->error('Réservé au mode démo (COMMVAULT_CLIENT=fake).');

            return Command::FAILURE;
        }
        if ($this->em->getRepository(Cabinet::class)->count([]) > 0) {
            $io->note('Référentiel déjà présent, rien à faire.');

            return Command::SUCCESS;
        }
        if (0 === $this->em->getRepository(Media::class)->count([])) {
            $io->error('Lancez d\'abord app:sync.');

            return Command::FAILURE;
        }

        $sb1 = new Cabinet('+6I21', 'SB1');
        $sb2 = new Cabinet('+6I21', 'SB2');
        $this->em->persist($sb1);
        $this->em->persist($sb2);
        $box = function (Cabinet $cabinet, string $shelf, string $name): StorageLocation {
            $location = new StorageLocation(LocationType::Box, $name);
            $location->setCabinet($cabinet);
            $location->setShelf($shelf);
            $this->em->persist($location);

            return $location;
        };
        $onlineN = $box($sb1, 'Sh1', 'Online N');
        $stock = $box($sb1, 'Sh2', 'Stock');
        $box($sb2, 'Sh1', 'Online A');
        $perron = new StorageLocation(LocationType::Offsite, 'Perron-LesBinches');
        $this->em->persist($perron);

        $copies = [];
        foreach ($this->em->getRepository(StoragePolicyCopy::class)->findAll() as $copy) {
            $copies[$copy->getCommvaultId()] = $copy;
        }
        $copies[167]?->setStorageLocation($onlineN);
        $copies[161]?->setStorageLocation($stock);
        $copies[168]?->setStorageLocation($perron); // Online S goes to the external site

        $now = \DateTimeImmutable::createFromInterface($this->clock->now());
        $states = ['K00141L8' => PhysicalState::InTransit, 'K00140L8' => PhysicalState::Offsite, 'K00036L8' => PhysicalState::Offsite];
        foreach ($this->em->getRepository(Media::class)->findBy(['inLibrary' => false]) as $media) {
            $media->setLocation($media->getCopy()?->getStorageLocation());
            $media->setPhysicalState($states[$media->getBarcode()] ?? PhysicalState::Stored, $now->modify('-30 days'));
        }

        $this->em->flush();
        $io->success('Référentiel de démo créé. Relancez app:sync pour recalculer alertes et retours.');

        return Command::SUCCESS;
    }
}

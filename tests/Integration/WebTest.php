<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Sync\CommvaultSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Test\ClockSensitiveTrait;
use Symfony\Component\Console\Tester\CommandTester;

final class WebTest extends WebTestCase
{
    use ClockSensitiveTrait;

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        static::mockTime(new \DateTimeImmutable('2026-10-14 10:00', new \DateTimeZone('Europe/Brussels')));

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $tool = new SchemaTool($em);
        $tool->dropSchema($em->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($em->getMetadataFactory()->getAllMetadata());
    }

    private function seedDemo(): void
    {
        $sync = static::getContainer()->get(CommvaultSynchronizer::class);
        $sync->run('test');
        $tester = new CommandTester((new Application(static::$kernel))->find('app:demo:seed'));
        self::assertSame(0, $tester->execute([]));
        $sync->run('test');
    }

    public function testPagesBeforeAnySync(): void
    {
        foreach (['/', '/tl', '/armoires', '/synchronisations'] as $url) {
            $this->client->request('GET', $url);
            self::assertResponseIsSuccessful($url);
        }
        self::assertSelectorTextContains('header', 'Jamais synchronisé');
    }

    public function testDashboard(): void
    {
        $this->seedDemo();
        $crawler = $this->client->request('GET', '/');

        self::assertResponseIsSuccessful();
        $counters = $crawler->filter('.text-5xl')->each(static fn ($n) => (int) $n->text());
        self::assertSame([5, 3, 3, 1, 5], $counters, 'to eject, sheets, to return, in transit, new tapes');
        self::assertSelectorTextContains('body', 'K00099L8 — Commvault signale la tape comme défectueuse');
        self::assertSelectorTextContains('#retours', '+6I21 › SB1 › Sh1 › Online N');
        self::assertSelectorTextContains('#fiches', 'BackUp 13/10/2026');
    }

    public function testLibraryDrawers(): void
    {
        $this->seedDemo();
        $crawler = $this->client->request('GET', '/tl');

        self::assertResponseIsSuccessful();
        self::assertCount(60, $crawler->filter('.grid .slot'));
        self::assertCount(5, $crawler->filter('.grid .slot-eject'));
        self::assertCount(1, $crawler->filter('.grid .slot-writing'));
        self::assertSelectorTextContains('body', 'Tiroir 3');
        self::assertSelectorTextContains('body', 'IBM ULT3580-HH9_1');
    }

    public function testCabinets(): void
    {
        $this->seedDemo();
        $this->client->request('GET', '/armoires');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Site externe · Perron-LesBinches');
        self::assertSelectorTextContains('body', 'K00067L8');
    }

    public function testSyncNowRequiresCsrfAndQueuesTheMessage(): void
    {
        $this->client->disableReboot(); // keep the in-memory transport between requests
        $this->client->request('POST', '/synchroniser', ['_token' => 'bad']);
        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Jeton de sécurité invalide');

        $crawler = $this->client->request('GET', '/');
        $this->client->submit($crawler->selectButton('Synchroniser maintenant')->form());
        // checked before the next request: the in-memory transport is reset between requests
        $sent = static::getContainer()->get('messenger.transport.async')->getSent();
        self::assertCount(1, $sent);
        self::assertSame('manual', $sent[0]->getMessage()->trigger);

        $this->client->followRedirect();
        self::assertSelectorTextContains('body', 'Synchronisation demandée');
    }
}

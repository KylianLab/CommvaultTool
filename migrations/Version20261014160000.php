<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Date of the last confirmed physical state change of a tape (inventory grace period).
 */
final class Version20261014160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'media.physical_state_changed_at';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'TapeTrack migrations target MariaDB.');
        $this->addSql('ALTER TABLE media ADD physical_state_changed_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP physical_state_changed_at');
    }
}

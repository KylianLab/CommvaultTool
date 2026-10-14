<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Initial TapeTrack schema (Phase 2): libraries, drawers, storage locations,
 * storage policies / copies, tapes, jobs, job <-> tape links, backups (sheets),
 * synchronisation runs, Messenger queue.
 */
final class Version20261014120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'TapeTrack initial schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'TapeTrack migrations target MariaDB.');

        $this->addSql('CREATE TABLE media (id INT AUTO_INCREMENT NOT NULL, commvault_id INT NOT NULL, barcode VARCHAR(32) NOT NULL, commvault_status VARCHAR(64) NOT NULL, in_library TINYINT NOT NULL, slot INT DEFAULT NULL, drive VARCHAR(64) DEFAULT NULL, drive_job_ids JSON NOT NULL, last_write_at DATETIME DEFAULT NULL, retain_until DATETIME DEFAULT NULL, media_type VARCHAR(64) DEFAULT NULL, spare_group VARCHAR(64) DEFAULT NULL, stored_bytes BIGINT NOT NULL, first_seen_at DATETIME NOT NULL, last_seen_at DATETIME NOT NULL, physical_state VARCHAR(32) NOT NULL, to_eject TINYINT NOT NULL, eject_reason VARCHAR(255) DEFAULT NULL, to_return TINYINT NOT NULL, alerts JSON NOT NULL, library_id INT DEFAULT NULL, copy_id INT DEFAULT NULL, location_id INT DEFAULT NULL, cycle_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6A2CA10C538E01CB (commvault_id), UNIQUE INDEX UNIQ_6A2CA10C97AE0266 (barcode), INDEX idx_media_flags (to_eject, to_return), INDEX IDX_6A2CA10CFE2541D7 (library_id), INDEX IDX_6A2CA10CA8752772 (copy_id), INDEX IDX_6A2CA10C64D218E (location_id), INDEX IDX_6A2CA10C5EC1162 (cycle_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE storage_location (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(16) NOT NULL, name VARCHAR(128) NOT NULL, shelf VARCHAR(32) DEFAULT NULL, position INT DEFAULT NULL, cabinet_id INT DEFAULT NULL, INDEX IDX_FD55B58CD351EC (cabinet_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE job_media (id INT AUTO_INCREMENT NOT NULL, inferred TINYINT NOT NULL, job_id INT NOT NULL, media_id INT NOT NULL, UNIQUE INDEX uniq_job_media (job_id, media_id), INDEX IDX_6B701C7CBE04EA9 (job_id), INDEX IDX_6B701C7CEA9FDD75 (media_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE cabinet (id INT AUTO_INCREMENT NOT NULL, room VARCHAR(64) NOT NULL, name VARCHAR(64) NOT NULL, UNIQUE INDEX uniq_cabinet (room, name), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE storage_policy (id INT AUTO_INCREMENT NOT NULL, commvault_id INT NOT NULL, name VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_CB7EF117538E01CB (commvault_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE library_drawer (id INT AUTO_INCREMENT NOT NULL, number INT NOT NULL, first_slot INT NOT NULL, last_slot INT NOT NULL, library_id INT NOT NULL, UNIQUE INDEX uniq_drawer_number (library_id, number), INDEX IDX_7EF2A68AFE2541D7 (library_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE sync_run (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, finished_at DATETIME DEFAULT NULL, status VARCHAR(16) NOT NULL, triggered_by VARCHAR(128) NOT NULL, stats JSON NOT NULL, error LONGTEXT DEFAULT NULL, warnings JSON NOT NULL, INDEX idx_sync_started (started_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE backup_cycle (id INT AUTO_INCREMENT NOT NULL, group_key VARCHAR(64) NOT NULL, retention_date DATE DEFAULT NULL, backup_date DATETIME DEFAULT NULL, anomalies JSON NOT NULL, first_seen_at DATETIME NOT NULL, copy_id INT NOT NULL, full_job_id INT DEFAULT NULL, UNIQUE INDEX uniq_cycle_key (copy_id, group_key), INDEX IDX_49A40E79A8752772 (copy_id), INDEX IDX_49A40E79912A125B (full_job_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE storage_policy_copy (id INT AUTO_INCREMENT NOT NULL, commvault_id INT NOT NULL, name VARCHAR(255) NOT NULL, primary_copy TINYINT NOT NULL, copy_precedence INT NOT NULL, library_commvault_id INT DEFAULT NULL, retention_days INT DEFAULT NULL, retention_cycles INT DEFAULT NULL, eject_rule VARCHAR(32) NOT NULL, policy_id INT NOT NULL, storage_location_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_8AE4BA16538E01CB (commvault_id), INDEX IDX_8AE4BA162D29E3C6 (policy_id), INDEX IDX_8AE4BA16CDDD8AF (storage_location_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tape_library (id INT AUTO_INCREMENT NOT NULL, commvault_id INT NOT NULL, name VARCHAR(128) NOT NULL, model VARCHAR(64) DEFAULT NULL, managed TINYINT NOT NULL, slot_count INT DEFAULT NULL, UNIQUE INDEX UNIQ_9D94D2C7538E01CB (commvault_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE backup_job (id INT AUTO_INCREMENT NOT NULL, commvault_id INT NOT NULL, job_type VARCHAR(64) NOT NULL, client_id INT DEFAULT NULL, client_name VARCHAR(255) DEFAULT NULL, subclient_name VARCHAR(255) DEFAULT NULL, start_at DATETIME DEFAULT NULL, end_at DATETIME DEFAULT NULL, retain_until DATETIME DEFAULT NULL, status VARCHAR(64) NOT NULL, aged TINYINT NOT NULL, last_seen_at DATETIME NOT NULL, copy_id INT NOT NULL, INDEX idx_job_retain (copy_id, retain_until), UNIQUE INDEX uniq_job_copy (commvault_id, copy_id), INDEX IDX_9F9FCC38A8752772 (copy_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CFE2541D7 FOREIGN KEY (library_id) REFERENCES tape_library (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10CA8752772 FOREIGN KEY (copy_id) REFERENCES storage_policy_copy (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C64D218E FOREIGN KEY (location_id) REFERENCES storage_location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE media ADD CONSTRAINT FK_6A2CA10C5EC1162 FOREIGN KEY (cycle_id) REFERENCES backup_cycle (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE storage_location ADD CONSTRAINT FK_FD55B58CD351EC FOREIGN KEY (cabinet_id) REFERENCES cabinet (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE job_media ADD CONSTRAINT FK_6B701C7CBE04EA9 FOREIGN KEY (job_id) REFERENCES backup_job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE job_media ADD CONSTRAINT FK_6B701C7CEA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE library_drawer ADD CONSTRAINT FK_7EF2A68AFE2541D7 FOREIGN KEY (library_id) REFERENCES tape_library (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE backup_cycle ADD CONSTRAINT FK_49A40E79A8752772 FOREIGN KEY (copy_id) REFERENCES storage_policy_copy (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE backup_cycle ADD CONSTRAINT FK_49A40E79912A125B FOREIGN KEY (full_job_id) REFERENCES backup_job (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE storage_policy_copy ADD CONSTRAINT FK_8AE4BA162D29E3C6 FOREIGN KEY (policy_id) REFERENCES storage_policy (id)');
        $this->addSql('ALTER TABLE storage_policy_copy ADD CONSTRAINT FK_8AE4BA16CDDD8AF FOREIGN KEY (storage_location_id) REFERENCES storage_location (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE backup_job ADD CONSTRAINT FK_9F9FCC38A8752772 FOREIGN KEY (copy_id) REFERENCES storage_policy_copy (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CFE2541D7');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10CA8752772');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C64D218E');
        $this->addSql('ALTER TABLE media DROP FOREIGN KEY FK_6A2CA10C5EC1162');
        $this->addSql('ALTER TABLE storage_location DROP FOREIGN KEY FK_FD55B58CD351EC');
        $this->addSql('ALTER TABLE job_media DROP FOREIGN KEY FK_6B701C7CBE04EA9');
        $this->addSql('ALTER TABLE job_media DROP FOREIGN KEY FK_6B701C7CEA9FDD75');
        $this->addSql('ALTER TABLE library_drawer DROP FOREIGN KEY FK_7EF2A68AFE2541D7');
        $this->addSql('ALTER TABLE backup_cycle DROP FOREIGN KEY FK_49A40E79A8752772');
        $this->addSql('ALTER TABLE backup_cycle DROP FOREIGN KEY FK_49A40E79912A125B');
        $this->addSql('ALTER TABLE storage_policy_copy DROP FOREIGN KEY FK_8AE4BA162D29E3C6');
        $this->addSql('ALTER TABLE storage_policy_copy DROP FOREIGN KEY FK_8AE4BA16CDDD8AF');
        $this->addSql('ALTER TABLE backup_job DROP FOREIGN KEY FK_9F9FCC38A8752772');
        $this->addSql('DROP TABLE media');
        $this->addSql('DROP TABLE storage_location');
        $this->addSql('DROP TABLE job_media');
        $this->addSql('DROP TABLE cabinet');
        $this->addSql('DROP TABLE storage_policy');
        $this->addSql('DROP TABLE library_drawer');
        $this->addSql('DROP TABLE sync_run');
        $this->addSql('DROP TABLE backup_cycle');
        $this->addSql('DROP TABLE storage_policy_copy');
        $this->addSql('DROP TABLE tape_library');
        $this->addSql('DROP TABLE backup_job');
        $this->addSql('DROP TABLE messenger_messages');
    }
}

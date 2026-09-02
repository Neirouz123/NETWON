<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520061333 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. The schema changes originally listed here (site_validated as
        // VARCHAR(255), the FK_60A5CE1D502CB44E constraint and its index on
        // next_assigned_to_id) were already created by the previous migration
        // Version20260514133006. Re-running them here fails on PostgreSQL with
        // "constraint already exists", so this migration is intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

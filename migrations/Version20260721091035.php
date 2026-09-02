<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260721091035 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. The site_prediction table (including the projection_fiable
        // column and the idx_site_prediction_site_horizon / idx_site_prediction_etat
        // indexes) is created and managed at runtime by the Python API
        // (api_python/ia_service.py), not by Doctrine migrations. The indexes
        // this migration tried to DROP do not exist at migration time, so it
        // failed on PostgreSQL. Intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260702110538 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. The `trafic_historique` table is created and maintained at
        // runtime by the Python API (_ensure_trafic_historique_table), not by
        // Doctrine migrations. The index names referenced here
        // (idx_trafic_historique_site / _date) do not match the index actually
        // created by Python (idx_trafic_historique_site_heure), so this
        // migration would fail and is intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625092131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. The `trafic_historique` table is NOT managed by Doctrine
        // migrations: it is created and evolved at runtime by the Python API
        // (api_python/traitement.py::_ensure_trafic_historique_table), which
        // uses CREATE/ALTER TABLE IF NOT EXISTS. The authoritative schema is
        // reflected by App\Entity\TraficHistorique, which mixes date_jour,
        // max_trafic, capacite_mbps, max_speed and date_heure. This migration
        // was generated against an outdated/renamed schema and would both fail
        // ("relation/index does not exist") and contradict the real table, so
        // it is intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

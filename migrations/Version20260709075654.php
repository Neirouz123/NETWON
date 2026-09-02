<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709075654 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. The analyse_resultat / ticket_history columns referenced here
        // are created and maintained at runtime by the Python API
        // (_ensure_analyse_resultat_table in traitement.py), not by Doctrine
        // migrations. The base analyse_resultat table only contains the columns
        // from Version20260423090754; the columns this migration tried to DROP
        // do not exist at migration time, so every statement failed on
        // PostgreSQL. This migration is intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

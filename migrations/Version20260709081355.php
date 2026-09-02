<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709081355 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. Duplicate/broken auto-generated migration: every statement
        // targets analyse_resultat / ticket_history columns that are owned and
        // created at runtime by the Python API, and which do not exist at
        // migration time. Intentionally empty (see Version20260709075654).
    }

    public function down(Schema $schema): void
    {
    }
}

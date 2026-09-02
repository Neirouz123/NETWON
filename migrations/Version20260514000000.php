<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Migration pour ajouter les champs de workflow amélioré
 * - siteValidated: Enregistre le site validé lors de la complétion d'une tâche
 * - startedAt: Enregistre la date de démarrage d'une tâche
 * - nextAssignedTo: Référence l'utilisateur assigné à la tâche suivante
 */
final class Version20260514000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add enhanced workflow fields to TicketTask table';
    }

    public function up(Schema $schema): void
    {
        // No-op. The schema changes described here (site_validated, started_at,
        // next_assigned_to_id + FK + index) are applied by the subsequent,
        // correctly-generated PostgreSQL migration Version20260514133006.
        // This earlier migration was originally written with MySQL-only syntax
        // (DATETIME, unquoted `user`, DROP FOREIGN KEY, DROP INDEX ... ON),
        // which PostgreSQL rejects. Keeping it as a no-op preserves the
        // migration version ordering without breaking the schema.
    }

    public function down(Schema $schema): void
    {
    }
}

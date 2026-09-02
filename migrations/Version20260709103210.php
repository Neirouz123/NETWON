<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709103210 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // No-op. Exact duplicate of Version20260709103159: the ticket_task
        // columns wo_ip_content / fh_fields are already added by the earlier
        // migration, and the analyse_resultat / ticket_history statements
        // target Python-managed columns that do not exist at migration time.
        // Re-running them would fail ("column already exists"), so this
        // migration is intentionally empty.
    }

    public function down(Schema $schema): void
    {
    }
}

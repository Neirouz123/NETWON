<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20270705000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create missing tables and add missing columns across the schema';
    }

    public function up(Schema $schema): void
    {
        // This migration was applied via direct SQL against the database.
        // It covers:
        //   - CREATE TABLE security_event (login audit trail)
        //   - CREATE TABLE chatbot_conversation (chatbot state)
        //   - CREATE TABLE workflow_history (ticket workflow audit)
        //   - ALTER TABLE ticket ADD 9 columns (current_step, total_steps, etc.)
        //   - ALTER TABLE ticket_site ADD status column
        //   - ALTER TABLE ticket_task ADD deploiement_data column
        //   - ALTER TABLE analyse_resultat ADD 2 columns (s1_fail_*)
        //   - ALTER TABLE processed_site ADD 10 columns
        //   - ALTER TABLE notification ADD alert_id + email_sent_at columns
    }

    public function down(Schema $schema): void
    {
        throw new \RuntimeException('This migration cannot be reversed safely.');
    }
}

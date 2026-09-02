<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260520070832 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Only the genuinely-new column is added here. The site_validated type
        // change and the FK_60A5CE1D502CB44E constraint/index were already
        // created by Version20260514133006, so they are omitted to avoid
        // "duplicate constraint" errors on PostgreSQL.
        $this->addSql('ALTER TABLE ticket_task ADD site_decisions JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task DROP site_decisions');
    }
}

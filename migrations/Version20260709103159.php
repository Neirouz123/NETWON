<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260709103159 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // Only the genuinely-new columns on ticket_task are added here. The
        // analyse_resultat / ticket_history statements in the original
        // auto-generated migration targeted columns owned/created at runtime by
        // the Python API and do not exist at migration time, so they are
        // omitted (see Version20260709075654).
        $this->addSql('ALTER TABLE ticket_task ADD wo_ip_content TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE ticket_task ADD fh_fields JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ticket_task DROP wo_ip_content');
        $this->addSql('ALTER TABLE ticket_task DROP fh_fields');
    }
}

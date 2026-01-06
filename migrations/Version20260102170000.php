<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add locale to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE orders ADD locale VARCHAR(2) NOT NULL DEFAULT 'en'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP locale');
    }
}

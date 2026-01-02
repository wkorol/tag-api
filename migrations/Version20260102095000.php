<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102095000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add status and confirmation token to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE orders ADD status VARCHAR(16) NOT NULL DEFAULT 'pending'");
        $this->addSql('ALTER TABLE orders ADD confirmationToken VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP status');
        $this->addSql('ALTER TABLE orders DROP confirmationToken');
    }
}

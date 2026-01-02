<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102095700 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rejection reason to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD rejectionReason TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP rejectionReason');
    }
}

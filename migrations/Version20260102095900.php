<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102095900 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add pending price and price proposal token to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD pendingPrice VARCHAR(32) DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD priceProposalToken VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP pendingPrice');
        $this->addSql('ALTER TABLE orders DROP priceProposalToken');
    }
}

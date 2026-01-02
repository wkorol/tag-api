<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260102095500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add generatedId to orders';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD generatedId VARCHAR(4) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_orders_generated_id ON orders (generatedId)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX UNIQ_orders_generated_id');
        $this->addSql('ALTER TABLE orders DROP generatedId');
    }
}

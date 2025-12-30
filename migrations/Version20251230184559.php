<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251230184559 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE orders (carType INT NOT NULL, pickupAddress VARCHAR(255) NOT NULL, proposedPrice VARCHAR(32) NOT NULL, date TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, pickupTime VARCHAR(5) NOT NULL, flightNumber VARCHAR(32) NOT NULL, fullName VARCHAR(255) NOT NULL, emailAddress VARCHAR(255) NOT NULL, phoneNumber VARCHAR(32) NOT NULL, additionalNotes TEXT NOT NULL, id UUID NOT NULL, PRIMARY KEY (id))');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE orders');
    }
}

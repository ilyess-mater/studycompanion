<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add updated_at on teacher_comment for editable discussion messages.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_comment ADD updated_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_comment DROP updated_at');
    }
}

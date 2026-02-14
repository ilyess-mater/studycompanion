<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260213201000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Align teacher_comment parent index naming with Doctrine mapping.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY `FK_59B6DF2D6DF00440`');
        $this->addSql('DROP INDEX idx_59b6df2d6df00440 ON teacher_comment');
        $this->addSql('CREATE INDEX IDX_59B6DF2DBF2AF943 ON teacher_comment (parent_comment_id)');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT `FK_59B6DF2D6DF00440` FOREIGN KEY (parent_comment_id) REFERENCES teacher_comment (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY `FK_59B6DF2D6DF00440`');
        $this->addSql('DROP INDEX IDX_59B6DF2DBF2AF943 ON teacher_comment');
        $this->addSql('CREATE INDEX idx_59b6df2d6df00440 ON teacher_comment (parent_comment_id)');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT `FK_59B6DF2D6DF00440` FOREIGN KEY (parent_comment_id) REFERENCES teacher_comment (id) ON DELETE SET NULL');
    }
}


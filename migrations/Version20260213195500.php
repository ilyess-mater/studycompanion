<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260213195500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add lesson-scoped threaded comment metadata to teacher_comment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE teacher_comment ADD lesson_id INT DEFAULT NULL, ADD author_role VARCHAR(20) NOT NULL DEFAULT 'ROLE_TEACHER', ADD parent_comment_id INT DEFAULT NULL");
        $this->addSql("UPDATE teacher_comment SET author_role = 'ROLE_TEACHER' WHERE author_role IS NULL OR author_role = ''");
        $this->addSql('CREATE INDEX IDX_59B6DF2DCDF80196 ON teacher_comment (lesson_id)');
        $this->addSql('CREATE INDEX IDX_59B6DF2D6DF00440 ON teacher_comment (parent_comment_id)');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT FK_59B6DF2DCDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT FK_59B6DF2D6DF00440 FOREIGN KEY (parent_comment_id) REFERENCES teacher_comment (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY FK_59B6DF2DCDF80196');
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY FK_59B6DF2D6DF00440');
        $this->addSql('DROP INDEX IDX_59B6DF2DCDF80196 ON teacher_comment');
        $this->addSql('DROP INDEX IDX_59B6DF2D6DF00440 ON teacher_comment');
        $this->addSql('ALTER TABLE teacher_comment DROP lesson_id, DROP author_role, DROP parent_comment_id');
    }
}


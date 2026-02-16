<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260215170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add composite index for teacher_comment(student_id, lesson_id, created_at).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_teacher_comment_student_lesson_created ON teacher_comment (student_id, lesson_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_teacher_comment_student_lesson_created ON teacher_comment');
    }
}


<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260214123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add third_party_meta JSON column to core diagram entities';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE performance_report ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE question ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE quiz ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE student_answer ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE student_profile ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE study_group ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE study_material ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE teacher_comment ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE teacher_profile ADD third_party_meta JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE `user` ADD third_party_meta JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE lesson DROP third_party_meta');
        $this->addSql('ALTER TABLE performance_report DROP third_party_meta');
        $this->addSql('ALTER TABLE question DROP third_party_meta');
        $this->addSql('ALTER TABLE quiz DROP third_party_meta');
        $this->addSql('ALTER TABLE student_answer DROP third_party_meta');
        $this->addSql('ALTER TABLE student_profile DROP third_party_meta');
        $this->addSql('ALTER TABLE study_group DROP third_party_meta');
        $this->addSql('ALTER TABLE study_material DROP third_party_meta');
        $this->addSql('ALTER TABLE teacher_comment DROP third_party_meta');
        $this->addSql('ALTER TABLE teacher_profile DROP third_party_meta');
        $this->addSql('ALTER TABLE `user` DROP third_party_meta');
    }
}


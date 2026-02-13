<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260213140858 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE ai_job_log (id INT AUTO_INCREMENT NOT NULL, job_type VARCHAR(50) NOT NULL, prompt_hash VARCHAR(64) NOT NULL, provider_status VARCHAR(80) NOT NULL, used_fallback TINYINT NOT NULL, latency_ms INT DEFAULT NULL, token_usage INT DEFAULT NULL, error_message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, lesson_id INT NOT NULL, INDEX IDX_77EFF587CDF80196 (lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE api_token (id INT AUTO_INCREMENT NOT NULL, token_hash VARCHAR(128) NOT NULL, expires_at DATETIME NOT NULL, revoked TINYINT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_7BA2F5EBA76ED395 (user_id), UNIQUE INDEX uniq_api_token_token_hash (token_hash), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE focus_session (id INT AUTO_INCREMENT NOT NULL, status VARCHAR(30) NOT NULL, started_at DATETIME DEFAULT NULL, ended_at DATETIME DEFAULT NULL, duration_seconds INT NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, lesson_id INT NOT NULL, quiz_id INT DEFAULT NULL, INDEX IDX_2AF21A4DCB944F1A (student_id), INDEX IDX_2AF21A4DCDF80196 (lesson_id), INDEX IDX_2AF21A4D853CD175 (quiz_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE focus_violation (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, details LONGTEXT DEFAULT NULL, severity INT NOT NULL, occurred_at DATETIME NOT NULL, focus_session_id INT NOT NULL, INDEX IDX_4CE25026FD9DB262 (focus_session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE lesson (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, subject VARCHAR(100) NOT NULL, difficulty VARCHAR(20) NOT NULL, file_path VARCHAR(255) NOT NULL, estimated_study_minutes INT DEFAULT NULL, learning_objectives JSON DEFAULT NULL, analysis_data JSON DEFAULT NULL, processing_status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, uploaded_by_id INT DEFAULT NULL, INDEX IDX_F87474F3A2B28FE8 (uploaded_by_id), INDEX idx_lesson_subject_difficulty (subject, difficulty), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE performance_report (id INT AUTO_INCREMENT NOT NULL, quiz_score DOUBLE PRECISION NOT NULL, weak_topics JSON NOT NULL, mastery_status VARCHAR(30) NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, lesson_id INT NOT NULL, quiz_id INT DEFAULT NULL, INDEX IDX_A4C759E6CB944F1A (student_id), INDEX IDX_A4C759E6CDF80196 (lesson_id), INDEX IDX_A4C759E6853CD175 (quiz_id), INDEX idx_performance_report_student_lesson (student_id, lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE question (id INT AUTO_INCREMENT NOT NULL, text LONGTEXT NOT NULL, options JSON NOT NULL, correct_answer VARCHAR(255) NOT NULL, quiz_id INT NOT NULL, INDEX IDX_B6F7494E853CD175 (quiz_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE quiz (id INT AUTO_INCREMENT NOT NULL, difficulty VARCHAR(20) NOT NULL, generated_at DATETIME NOT NULL, lesson_id INT NOT NULL, INDEX IDX_A412FA92CDF80196 (lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE student_answer (id INT AUTO_INCREMENT NOT NULL, answer VARCHAR(255) NOT NULL, is_correct TINYINT NOT NULL, response_time_ms INT NOT NULL, created_at DATETIME NOT NULL, student_id INT NOT NULL, question_id INT NOT NULL, INDEX IDX_54EB92A5CB944F1A (student_id), INDEX IDX_54EB92A51E27F6BF (question_id), INDEX idx_student_answer_student_question (student_id, question_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE student_profile (id INT AUTO_INCREMENT NOT NULL, grade VARCHAR(30) DEFAULT NULL, user_id INT NOT NULL, group_id INT DEFAULT NULL, UNIQUE INDEX UNIQ_6C611FF7A76ED395 (user_id), INDEX IDX_6C611FF7FE54D947 (group_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE study_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, invite_code VARCHAR(20) NOT NULL, teacher_id INT NOT NULL, INDEX IDX_32BA142541807E1D (teacher_id), UNIQUE INDEX uniq_study_group_invite_code (invite_code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE study_material (id INT AUTO_INCREMENT NOT NULL, summary LONGTEXT DEFAULT NULL, flashcards JSON DEFAULT NULL, type VARCHAR(30) NOT NULL, content LONGTEXT NOT NULL, version INT NOT NULL, created_at DATETIME NOT NULL, lesson_id INT NOT NULL, INDEX IDX_DF37601CCDF80196 (lesson_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_comment (id INT AUTO_INCREMENT NOT NULL, content LONGTEXT NOT NULL, created_at DATETIME NOT NULL, teacher_id INT NOT NULL, student_id INT NOT NULL, INDEX IDX_59B6DF2D41807E1D (teacher_id), INDEX IDX_59B6DF2DCB944F1A (student_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE teacher_profile (id INT AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, UNIQUE INDEX UNIQ_4C95274EA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(120) NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX uniq_user_email (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE video_recommendation (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(200) NOT NULL, url VARCHAR(255) NOT NULL, channel_name VARCHAR(120) NOT NULL, duration_seconds INT DEFAULT NULL, score DOUBLE PRECISION DEFAULT NULL, created_at DATETIME NOT NULL, lesson_id INT NOT NULL, study_material_id INT DEFAULT NULL, INDEX IDX_65E06E52CDF80196 (lesson_id), INDEX IDX_65E06E52AE3D5153 (study_material_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT AUTO_INCREMENT NOT NULL, body LONGTEXT NOT NULL, headers LONGTEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at DATETIME NOT NULL, available_at DATETIME NOT NULL, delivered_at DATETIME DEFAULT NULL, INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 (queue_name, available_at, delivered_at, id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE ai_job_log ADD CONSTRAINT FK_77EFF587CDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EBA76ED395 FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE focus_session ADD CONSTRAINT FK_2AF21A4DCB944F1A FOREIGN KEY (student_id) REFERENCES student_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE focus_session ADD CONSTRAINT FK_2AF21A4DCDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE focus_session ADD CONSTRAINT FK_2AF21A4D853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE focus_violation ADD CONSTRAINT FK_4CE25026FD9DB262 FOREIGN KEY (focus_session_id) REFERENCES focus_session (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE lesson ADD CONSTRAINT FK_F87474F3A2B28FE8 FOREIGN KEY (uploaded_by_id) REFERENCES student_profile (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE performance_report ADD CONSTRAINT FK_A4C759E6CB944F1A FOREIGN KEY (student_id) REFERENCES student_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE performance_report ADD CONSTRAINT FK_A4C759E6CDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE performance_report ADD CONSTRAINT FK_A4C759E6853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE question ADD CONSTRAINT FK_B6F7494E853CD175 FOREIGN KEY (quiz_id) REFERENCES quiz (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE quiz ADD CONSTRAINT FK_A412FA92CDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_answer ADD CONSTRAINT FK_54EB92A5CB944F1A FOREIGN KEY (student_id) REFERENCES student_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_answer ADD CONSTRAINT FK_54EB92A51E27F6BF FOREIGN KEY (question_id) REFERENCES question (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE student_profile ADD CONSTRAINT FK_6C611FF7A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE student_profile ADD CONSTRAINT FK_6C611FF7FE54D947 FOREIGN KEY (group_id) REFERENCES study_group (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE study_group ADD CONSTRAINT FK_32BA142541807E1D FOREIGN KEY (teacher_id) REFERENCES teacher_profile (id)');
        $this->addSql('ALTER TABLE study_material ADD CONSTRAINT FK_DF37601CCDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT FK_59B6DF2D41807E1D FOREIGN KEY (teacher_id) REFERENCES teacher_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_comment ADD CONSTRAINT FK_59B6DF2DCB944F1A FOREIGN KEY (student_id) REFERENCES student_profile (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE teacher_profile ADD CONSTRAINT FK_4C95274EA76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE video_recommendation ADD CONSTRAINT FK_65E06E52CDF80196 FOREIGN KEY (lesson_id) REFERENCES lesson (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE video_recommendation ADD CONSTRAINT FK_65E06E52AE3D5153 FOREIGN KEY (study_material_id) REFERENCES study_material (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE ai_job_log DROP FOREIGN KEY FK_77EFF587CDF80196');
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EBA76ED395');
        $this->addSql('ALTER TABLE focus_session DROP FOREIGN KEY FK_2AF21A4DCB944F1A');
        $this->addSql('ALTER TABLE focus_session DROP FOREIGN KEY FK_2AF21A4DCDF80196');
        $this->addSql('ALTER TABLE focus_session DROP FOREIGN KEY FK_2AF21A4D853CD175');
        $this->addSql('ALTER TABLE focus_violation DROP FOREIGN KEY FK_4CE25026FD9DB262');
        $this->addSql('ALTER TABLE lesson DROP FOREIGN KEY FK_F87474F3A2B28FE8');
        $this->addSql('ALTER TABLE performance_report DROP FOREIGN KEY FK_A4C759E6CB944F1A');
        $this->addSql('ALTER TABLE performance_report DROP FOREIGN KEY FK_A4C759E6CDF80196');
        $this->addSql('ALTER TABLE performance_report DROP FOREIGN KEY FK_A4C759E6853CD175');
        $this->addSql('ALTER TABLE question DROP FOREIGN KEY FK_B6F7494E853CD175');
        $this->addSql('ALTER TABLE quiz DROP FOREIGN KEY FK_A412FA92CDF80196');
        $this->addSql('ALTER TABLE student_answer DROP FOREIGN KEY FK_54EB92A5CB944F1A');
        $this->addSql('ALTER TABLE student_answer DROP FOREIGN KEY FK_54EB92A51E27F6BF');
        $this->addSql('ALTER TABLE student_profile DROP FOREIGN KEY FK_6C611FF7A76ED395');
        $this->addSql('ALTER TABLE student_profile DROP FOREIGN KEY FK_6C611FF7FE54D947');
        $this->addSql('ALTER TABLE study_group DROP FOREIGN KEY FK_32BA142541807E1D');
        $this->addSql('ALTER TABLE study_material DROP FOREIGN KEY FK_DF37601CCDF80196');
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY FK_59B6DF2D41807E1D');
        $this->addSql('ALTER TABLE teacher_comment DROP FOREIGN KEY FK_59B6DF2DCB944F1A');
        $this->addSql('ALTER TABLE teacher_profile DROP FOREIGN KEY FK_4C95274EA76ED395');
        $this->addSql('ALTER TABLE video_recommendation DROP FOREIGN KEY FK_65E06E52CDF80196');
        $this->addSql('ALTER TABLE video_recommendation DROP FOREIGN KEY FK_65E06E52AE3D5153');
        $this->addSql('DROP TABLE ai_job_log');
        $this->addSql('DROP TABLE api_token');
        $this->addSql('DROP TABLE focus_session');
        $this->addSql('DROP TABLE focus_violation');
        $this->addSql('DROP TABLE lesson');
        $this->addSql('DROP TABLE performance_report');
        $this->addSql('DROP TABLE question');
        $this->addSql('DROP TABLE quiz');
        $this->addSql('DROP TABLE student_answer');
        $this->addSql('DROP TABLE student_profile');
        $this->addSql('DROP TABLE study_group');
        $this->addSql('DROP TABLE study_material');
        $this->addSql('DROP TABLE teacher_comment');
        $this->addSql('DROP TABLE teacher_profile');
        $this->addSql('DROP TABLE user');
        $this->addSql('DROP TABLE video_recommendation');
        $this->addSql('DROP TABLE messenger_messages');
    }
}

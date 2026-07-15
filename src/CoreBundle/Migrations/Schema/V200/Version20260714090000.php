<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260714090000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Creates c_homework_form, c_homework_form_page, c_homework_form_field, c_homework_assignment, c_homework_submission and c_homework_submission_answer tables.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE c_homework_form (
                iid INT AUTO_INCREMENT NOT NULL,
                resource_node_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                UNIQUE INDEX UNIQ_HOMEWORK_FORM_RESOURCE_NODE (resource_node_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_form
            ADD CONSTRAINT FK_HOMEWORK_FORM_RESOURCE_NODE FOREIGN KEY (resource_node_id) REFERENCES resource_node (id) ON DELETE CASCADE
        ');

        $this->addSql("
            CREATE TABLE c_homework_form_page (
                iid INT AUTO_INCREMENT NOT NULL,
                form_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                INDEX IDX_HOMEWORK_FORM_PAGE_FORM (form_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_form_page
            ADD CONSTRAINT FK_HOMEWORK_FORM_PAGE_FORM FOREIGN KEY (form_id) REFERENCES c_homework_form (iid) ON DELETE CASCADE
        ');

        $this->addSql("
            CREATE TABLE c_homework_form_field (
                iid INT AUTO_INCREMENT NOT NULL,
                page_id INT NOT NULL,
                type SMALLINT NOT NULL,
                label VARCHAR(255) NOT NULL,
                help_text LONGTEXT DEFAULT NULL,
                required TINYINT(1) NOT NULL DEFAULT 0,
                options LONGTEXT DEFAULT NULL COMMENT '(DC2Type:json)',
                sort_order INT NOT NULL DEFAULT 0,
                INDEX IDX_HOMEWORK_FORM_FIELD_PAGE (page_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_form_field
            ADD CONSTRAINT FK_HOMEWORK_FORM_FIELD_PAGE FOREIGN KEY (page_id) REFERENCES c_homework_form_page (iid) ON DELETE CASCADE
        ');

        $this->addSql("
            CREATE TABLE c_homework_assignment (
                iid INT AUTO_INCREMENT NOT NULL,
                resource_node_id INT DEFAULT NULL,
                title VARCHAR(255) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                session_id INT DEFAULT NULL,
                submission_type SMALLINT NOT NULL,
                opens_on DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime)',
                deadline DATETIME NOT NULL COMMENT '(DC2Type:datetime)',
                allow_late_submission TINYINT(1) NOT NULL DEFAULT 0,
                template_document_id INT DEFAULT NULL,
                form_id INT DEFAULT NULL,
                evaluation_mode SMALLINT NOT NULL,
                add_to_gradebook TINYINT(1) NOT NULL DEFAULT 0,
                gradebook_category_id INT NOT NULL DEFAULT 0,
                weight DOUBLE PRECISION NOT NULL DEFAULT 0,
                add_to_calendar TINYINT(1) NOT NULL DEFAULT 0,
                event_calendar_id INT NOT NULL DEFAULT 0,
                UNIQUE INDEX UNIQ_HOMEWORK_ASSIGNMENT_RESOURCE_NODE (resource_node_id),
                INDEX IDX_HOMEWORK_ASSIGNMENT_SESSION (session_id),
                INDEX IDX_HOMEWORK_ASSIGNMENT_FORM (form_id),
                INDEX IDX_HOMEWORK_ASSIGNMENT_TEMPLATE_DOCUMENT (template_document_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        // Note: gradebook_category_id and event_calendar_id use a NOT NULL DEFAULT 0 sentinel
        // (matching the legacy Chamilo 1.11 c_student_publication convention) instead of a nullable
        // FK, because 0 means "not linked" and these tables don't own the gradebook/calendar
        // relationship — this is intentional, not an oversight.
        $this->addSql('
            ALTER TABLE c_homework_assignment
            ADD CONSTRAINT FK_HOMEWORK_ASSIGNMENT_RESOURCE_NODE FOREIGN KEY (resource_node_id) REFERENCES resource_node (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_ASSIGNMENT_SESSION FOREIGN KEY (session_id) REFERENCES session (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_ASSIGNMENT_FORM FOREIGN KEY (form_id) REFERENCES c_homework_form (iid) ON DELETE SET NULL,
            ADD CONSTRAINT FK_HOMEWORK_ASSIGNMENT_TEMPLATE_DOCUMENT FOREIGN KEY (template_document_id) REFERENCES c_document (iid) ON DELETE SET NULL
        ');

        $this->addSql("
            CREATE TABLE c_homework_submission (
                iid INT AUTO_INCREMENT NOT NULL,
                resource_node_id INT DEFAULT NULL,
                assignment_id INT NOT NULL,
                user_id INT NOT NULL,
                submitted_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime)',
                document_id INT DEFAULT NULL,
                status SMALLINT NOT NULL DEFAULT 1,
                score DOUBLE PRECISION DEFAULT NULL,
                feedback LONGTEXT DEFAULT NULL,
                evaluated_by INT DEFAULT NULL,
                evaluated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime)',
                UNIQUE INDEX UNIQ_HOMEWORK_SUBMISSION_RESOURCE_NODE (resource_node_id),
                UNIQUE INDEX UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER (assignment_id, user_id),
                INDEX IDX_HOMEWORK_SUBMISSION_USER (user_id),
                INDEX IDX_HOMEWORK_SUBMISSION_EVALUATED_BY (evaluated_by),
                INDEX IDX_HOMEWORK_SUBMISSION_DOCUMENT (document_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_submission
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_RESOURCE_NODE FOREIGN KEY (resource_node_id) REFERENCES resource_node (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES c_homework_assignment (iid) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_EVALUATED_BY FOREIGN KEY (evaluated_by) REFERENCES user (id) ON DELETE SET NULL,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_DOCUMENT FOREIGN KEY (document_id) REFERENCES c_document (iid) ON DELETE SET NULL
        ');

        $this->addSql("
            CREATE TABLE c_homework_submission_answer (
                iid INT AUTO_INCREMENT NOT NULL,
                submission_id INT NOT NULL,
                field_id INT NOT NULL,
                `value` LONGTEXT DEFAULT NULL,
                file_document_id INT DEFAULT NULL,
                INDEX IDX_HOMEWORK_SUBMISSION_ANSWER_SUBMISSION (submission_id),
                INDEX IDX_HOMEWORK_SUBMISSION_ANSWER_FIELD (field_id),
                INDEX IDX_HOMEWORK_SUBMISSION_ANSWER_FILE_DOCUMENT (file_document_id),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_submission_answer
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_ANSWER_SUBMISSION FOREIGN KEY (submission_id) REFERENCES c_homework_submission (iid) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_ANSWER_FIELD FOREIGN KEY (field_id) REFERENCES c_homework_form_field (iid) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_SUBMISSION_ANSWER_FILE_DOCUMENT FOREIGN KEY (file_document_id) REFERENCES c_document (iid) ON DELETE SET NULL
        ');

        $this->write('Created Huiswerk module tables.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS c_homework_submission_answer');
        $this->addSql('DROP TABLE IF EXISTS c_homework_submission');
        $this->addSql('DROP TABLE IF EXISTS c_homework_assignment');
        $this->addSql('DROP TABLE IF EXISTS c_homework_form_field');
        $this->addSql('DROP TABLE IF EXISTS c_homework_form_page');
        $this->addSql('DROP TABLE IF EXISTS c_homework_form');
        $this->write('Dropped Huiswerk module tables.');
    }
}

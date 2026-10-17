<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260911142000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Creates c_homework_assignment_evaluation table (final evaluation for multi-report assignments).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("
            CREATE TABLE c_homework_assignment_evaluation (
                iid INT AUTO_INCREMENT NOT NULL,
                resource_node_id INT DEFAULT NULL,
                assignment_id INT NOT NULL,
                user_id INT NOT NULL,
                score DOUBLE PRECISION DEFAULT NULL,
                feedback LONGTEXT DEFAULT NULL,
                evaluated_by INT DEFAULT NULL,
                evaluated_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime)',
                UNIQUE INDEX UNIQ_HOMEWORK_EVALUATION_RESOURCE_NODE (resource_node_id),
                UNIQUE INDEX UNIQ_HOMEWORK_EVALUATION_ASSIGNMENT_USER (assignment_id, user_id),
                INDEX IDX_HOMEWORK_EVALUATION_USER (user_id),
                INDEX IDX_HOMEWORK_EVALUATION_EVALUATED_BY (evaluated_by),
                PRIMARY KEY(iid)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql('
            ALTER TABLE c_homework_assignment_evaluation
            ADD CONSTRAINT FK_HOMEWORK_EVALUATION_RESOURCE_NODE FOREIGN KEY (resource_node_id) REFERENCES resource_node (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_EVALUATION_ASSIGNMENT FOREIGN KEY (assignment_id) REFERENCES c_homework_assignment (iid) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_EVALUATION_USER FOREIGN KEY (user_id) REFERENCES user (id) ON DELETE CASCADE,
            ADD CONSTRAINT FK_HOMEWORK_EVALUATION_EVALUATED_BY FOREIGN KEY (evaluated_by) REFERENCES user (id) ON DELETE SET NULL
        ');

        $this->write('Created c_homework_assignment_evaluation.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS c_homework_assignment_evaluation');
        $this->write('Dropped c_homework_assignment_evaluation.');
    }
}

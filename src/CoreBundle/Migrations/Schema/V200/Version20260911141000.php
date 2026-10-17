<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260911141000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Adds sequence_number/label to c_homework_submission and relaxes its unique constraint to (assignment_id, user_id, sequence_number).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE c_homework_submission ADD sequence_number INT NOT NULL DEFAULT 1, ADD label VARCHAR(255) DEFAULT NULL');
        // The new index must be added BEFORE the old one is dropped: the old
        // UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER index (assignment_id, user_id)
        // is the only index covering FK_HOMEWORK_SUBMISSION_ASSIGNMENT (no plain
        // index exists on assignment_id alone) - dropping it first fails with
        // "Cannot drop index ... needed in a foreign key constraint" because the
        // new (assignment_id, user_id, sequence_number) index doesn't exist yet
        // to take over that role. Adding it first keeps assignment_id covered
        // throughout (its leading column), so the drop below is then safe.
        $this->addSql('ALTER TABLE c_homework_submission ADD UNIQUE INDEX UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER_SEQ (assignment_id, user_id, sequence_number)');
        $this->addSql('ALTER TABLE c_homework_submission DROP INDEX UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER');
        $this->write('Added c_homework_submission.sequence_number / label; relaxed unique constraint.');
    }

    public function down(Schema $schema): void
    {
        // Same add-before-drop ordering as up() - see that method's comment.
        $this->addSql('ALTER TABLE c_homework_submission ADD UNIQUE INDEX UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER (assignment_id, user_id)');
        $this->addSql('ALTER TABLE c_homework_submission DROP INDEX UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER_SEQ');
        $this->addSql('ALTER TABLE c_homework_submission DROP COLUMN sequence_number, DROP COLUMN label');
        $this->write('Reverted c_homework_submission.sequence_number / label / unique constraint.');
    }
}

<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260911140000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Adds allow_multiple_submissions and required_submission_count to c_homework_assignment.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE c_homework_assignment ADD allow_multiple_submissions TINYINT(1) NOT NULL DEFAULT 0, ADD required_submission_count INT DEFAULT NULL');
        $this->write('Added c_homework_assignment.allow_multiple_submissions / required_submission_count.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE c_homework_assignment DROP COLUMN allow_multiple_submissions, DROP COLUMN required_submission_count');
        $this->write('Dropped c_homework_assignment.allow_multiple_submissions / required_submission_count.');
    }
}

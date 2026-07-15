<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260715150000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Adds c_homework_form_field.textarea_rows, letting teachers configure a textarea field\'s visible height in the Huiswerk form builder.';
    }

    public function up(Schema $schema): void
    {
        // "rows" is a reserved word in MySQL/MariaDB (used by e.g. window
        // functions) - named the physical column textarea_rows to avoid
        // needing to quote it in every future raw query, while keeping the
        // PHP-side property/getter/setter simply named "rows".
        $this->addSql('
            ALTER TABLE c_homework_form_field
            ADD textarea_rows SMALLINT DEFAULT NULL
        ');
        $this->write('Added c_homework_form_field.textarea_rows.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE c_homework_form_field DROP COLUMN textarea_rows');
        $this->write('Dropped c_homework_form_field.textarea_rows.');
    }
}

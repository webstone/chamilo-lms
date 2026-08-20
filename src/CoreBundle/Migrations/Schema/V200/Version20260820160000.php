<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Doctrine\DBAL\Schema\Schema;

final class Version20260820160000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Adds c_dropbox_person.cat_id so each receiver can organize a received Dropbox file into their own folder, independent of the sender\'s own categorization.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            ALTER TABLE c_dropbox_person
            ADD cat_id INT NOT NULL DEFAULT 0
        ');
        $this->write('Added c_dropbox_person.cat_id.');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE c_dropbox_person DROP COLUMN cat_id');
        $this->write('Dropped c_dropbox_person.cat_id.');
    }
}

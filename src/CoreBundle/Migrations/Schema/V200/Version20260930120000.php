<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Chamilo\CoreBundle\Tool\ToolChain;
use Doctrine\DBAL\Schema\Schema;

/**
 * CHomeworkAssignmentEvaluation was missing from Homework::getResourceTypes()
 * since the entity was introduced (see the docs/superpowers spec for round 4
 * of the homework module work), so no matching resource_type row ever
 * existed for it - ResourceListener::prePersist() silently skips creating a
 * ResourceNode for any entity whose class isn't in the resource_type table,
 * leaving every evaluation's resourceNode permanently null and breaking
 * every resourceNode-based security check/collection query on that entity
 * (found via live test execution, not just static review).
 *
 * ToolChain::createTools() is idempotent (matches existing rows by title via
 * Tool::hasResourceType(), only inserts what's missing), same pattern as
 * Version20260716090000/Version20250928100200/etc. for their own new
 * resource types - safe to call again even where the other homework_* rows
 * already exist.
 */
final class Version20260930120000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Seeds the missing homework_assignment_evaluations resource_type row via ToolChain::createTools(), so CHomeworkAssignmentEvaluation gets a real ResourceNode on persist.';
    }

    public function up(Schema $schema): void
    {
        /** @var ToolChain $toolChain */
        $toolChain = $this->container->get(ToolChain::class);
        $toolChain->createTools();
    }

    public function down(Schema $schema): void
    {
        // Not reversible: removing the resource_type row would orphan any
        // ResourceNode already created for an existing evaluation.
    }
}

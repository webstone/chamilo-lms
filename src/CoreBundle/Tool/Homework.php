<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Tool;

use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;

class Homework extends AbstractTool implements ToolInterface
{
    public function getTitle(): string
    {
        return 'huiswerk';
    }

    public function getTitleToShow(): string
    {
        return 'Homework';
    }

    public function getLink(): string
    {
        return '/resources/homework/:nodeId';
    }

    public function getIcon(): string
    {
        return 'mdi-notebook-check';
    }

    public function getCategory(): string
    {
        return 'interaction';
    }

    public function getResourceTypes(): ?array
    {
        return [
            'homework_forms' => CHomeworkForm::class,
            'homework_assignments' => CHomeworkAssignment::class,
            'homework_submissions' => CHomeworkSubmission::class,
            // Was missing entirely until 2026-09-30 - without a matching
            // resource_type row (seeded from this array by
            // ToolChain::createTools(), see Migrations/Schema/V200 for the
            // one that backfills it), ResourceListener::prePersist() silently
            // skips ResourceNode creation for every CHomeworkAssignmentEvaluation,
            // leaving getResourceNode() permanently null and breaking every
            // resourceNode-based security check/query on that entity.
            'homework_assignment_evaluations' => CHomeworkAssignmentEvaluation::class,
        ];
    }
}

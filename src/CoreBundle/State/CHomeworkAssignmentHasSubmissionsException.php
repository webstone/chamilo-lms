<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Thrown by CHomeworkAssignmentDeleteProcessor when deleting an assignment
 * is rejected because at least one student has already turned in a real
 * (SUBMITTED/LATE, not draft) submission for it.
 *
 * A dedicated class (rather than throwing ConflictHttpException directly)
 * documents the specific business rule at the call site and gives tests a
 * precise exception to assert against, matching the existing
 * CHomeworkSubmissionDeadlinePassedException precedent.
 */
final class CHomeworkAssignmentHasSubmissionsException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('This assignment already has submitted work and cannot be deleted.');
    }
}

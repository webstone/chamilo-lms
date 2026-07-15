<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Repository\CHomeworkSubmissionRepository;

/**
 * @implements ProcessorInterface<CHomeworkAssignment, void>
 */
final class CHomeworkAssignmentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $removeProcessor,
        private readonly CHomeworkSubmissionRepository $submissionRepository,
    ) {}

    public function process($data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof CHomeworkAssignment) {
            return;
        }

        // Deliberately checks SUBMITTED/LATE only, not drafts - a student
        // who merely started filling in the form (never hit "Submit")
        // hasn't turned anything in yet, so it shouldn't block the teacher
        // from deleting/reworking the assignment.
        if ($this->submissionRepository->hasSubmittedSubmissions($data)) {
            throw new CHomeworkAssignmentHasSubmissionsException();
        }

        $this->removeProcessor->process($data, $operation, $uriVariables, $context);
    }
}

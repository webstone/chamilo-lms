<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\ResourceNode;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Repository\CHomeworkSubmissionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * @implements ProcessorInterface<CHomeworkAssignment, void>
 */
final class CHomeworkAssignmentDeleteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $removeProcessor,
        private readonly CHomeworkSubmissionRepository $submissionRepository,
        private readonly EntityManagerInterface $em,
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

        // The default remove processor only removes the CHomeworkAssignment
        // row itself, leaving its resource_node's resource_link rows behind
        // (same structural gap documented for course deletion, PR #8675).
        // Cleaned up here the same way CStudentPublicationDeleteProcessor
        // already does for its own entity.
        $node = $data->hasResourceNode() ? $data->getResourceNode() : null;

        $this->em->beginTransaction();

        try {
            $this->removeProcessor->process($data, $operation, $uriVariables, $context);

            if ($node instanceof ResourceNode) {
                foreach ($node->getResourceLinks() as $link) {
                    $this->em->remove($link);
                }
                $this->em->flush();

                foreach ($node->getResourceFiles() as $file) {
                    $this->em->remove($file);
                }
                $this->em->flush();
            }

            $this->em->commit();
        } catch (Throwable $e) {
            $this->em->rollback();

            throw $e;
        }
    }
}

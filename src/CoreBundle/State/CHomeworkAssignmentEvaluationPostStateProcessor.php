<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use Chamilo\CourseBundle\Repository\CHomeworkAssignmentEvaluationRepository;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<CHomeworkAssignmentEvaluation, CHomeworkAssignmentEvaluation>
 */
final class CHomeworkAssignmentEvaluationPostStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly CHomeworkAssignmentEvaluationRepository $evaluationRepository,
        private readonly Security $security,
    ) {}

    public function process(
        $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): CHomeworkAssignmentEvaluation {
        if (!$data->getAssignment()->isAllowMultipleSubmissions()) {
            throw new CHomeworkAssignmentDoesNotAllowMultipleSubmissionsException();
        }

        $existing = $this->evaluationRepository->findOneBy([
            'assignment' => $data->getAssignment(),
            'user' => $data->getUser(),
        ]);

        if (null !== $existing) {
            throw new CHomeworkAssignmentEvaluationAlreadyExistsException();
        }

        // Unlike CHomeworkSubmission (always created score-less by the
        // student, graded later via PUT), a teacher may set score/feedback
        // directly at creation time here - the Post operation's own security
        // expression already restricts it to a course-(session-)teacher role,
        // so (unlike the Put processor) no self-grading guard is needed: only
        // a teacher can ever reach this processor at all.
        if (null !== $data->getScore() || null !== $data->getFeedback()) {
            $grader = $this->security->getUser();
            if ($grader instanceof User) {
                $data->setEvaluatedBy($grader);
                $data->setEvaluatedAt(new DateTime());
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}

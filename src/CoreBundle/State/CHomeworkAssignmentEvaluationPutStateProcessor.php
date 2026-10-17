<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Mirrors CHomeworkSubmissionPutStateProcessor's guard #4 (self-grading
 * prevention) in spirit, minus that processor's status-transition machinery
 * (an evaluation has no draft/submit workflow - it's created once by a
 * teacher and only ever revised by a teacher).
 *
 * Unlike CHomeworkSubmission (always created by the owning student, so the
 * student IS the ResourceNode creator and gets EDIT via
 * ResourceNodeVoter's creator-based ownership check), a CHomeworkAssignmentEvaluation
 * is created by the TEACHER on the student's behalf - the student is never
 * its creator, so in practice they never reach this processor at all (the
 * PUT operation's security expression already rejects them with 403).
 * Confirmed live (2026-09-11): this guard is therefore currently
 * unreachable for a student and kept only as defense-in-depth, in case the
 * security model ever changes to also grant resourceLink-based EDIT here.
 *
 * @implements ProcessorInterface<CHomeworkAssignmentEvaluation, CHomeworkAssignmentEvaluation>
 */
final class CHomeworkAssignmentEvaluationPutStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly HomeworkCourseTeacherChecker $teacherChecker,
    ) {}

    public function process(
        $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): CHomeworkAssignmentEvaluation {
        $previousData = $context['previous_data'] ?? null;

        // $assignment/$user are immutable after creation - see this
        // entity's ApiResource docblock for why $user must be writable on
        // Post at all (unlike CHomeworkSubmission, where it never is).
        if ($previousData instanceof CHomeworkAssignmentEvaluation) {
            $data->setAssignment($previousData->getAssignment());
            $data->setUser($previousData->getUser());
        }

        $previousScore = $previousData instanceof CHomeworkAssignmentEvaluation ? $previousData->getScore() : null;
        $previousFeedback = $previousData instanceof CHomeworkAssignmentEvaluation ? $previousData->getFeedback() : null;
        $isGradingChange = $data->getScore() !== $previousScore || $data->getFeedback() !== $previousFeedback;

        if ($isGradingChange) {
            $grader = $this->resolvePrivilegedGrader($data);
            if (null !== $grader) {
                $data->setEvaluatedBy($grader);
                $data->setEvaluatedAt(new DateTime());
            } else {
                // In practice unreachable for a student today (see class
                // docblock) - kept as defense-in-depth, mirroring
                // CHomeworkSubmissionPutStateProcessor's guard #4: silently
                // revert instead of rejecting the whole request, so a
                // legitimate non-grading PUT from a privileged-but-not-quite
                // caller is never hard-rejected over this alone.
                $data->setScore($previousScore);
                $data->setFeedback($previousFeedback);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }

    private function resolvePrivilegedGrader(CHomeworkAssignmentEvaluation $evaluation): ?User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return $user;
        }

        $course = $this->resolveCourse($evaluation);
        if (!$course instanceof Course) {
            return null;
        }

        return $this->teacherChecker->isTeacherOfCourse($user, $course) ? $user : null;
    }

    /**
     * Mirrors HomeworkVoter::resolveCourse() for the
     * CHomeworkAssignmentEvaluation case.
     */
    private function resolveCourse(CHomeworkAssignmentEvaluation $evaluation): ?Course
    {
        $links = $evaluation->getAssignment()->getResourceNode()?->getResourceLinks();
        foreach ($links ?? [] as $link) {
            $course = $link->getCourse();
            if (null !== $course) {
                return $course;
            }
        }

        return null;
    }
}

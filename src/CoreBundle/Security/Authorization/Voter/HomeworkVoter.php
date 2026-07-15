<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Security\Authorization\Voter;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Grants VIEW/EDIT on Huiswerk entities to any teacher of the underlying
 * course, regardless of which session the current request is scoped to.
 *
 * This complements (does not replace) ResourceNodeVoter, which only checks
 * teacher rights for the CURRENTLY selected session/course context. The
 * access_decision_manager strategy configured in
 * config/packages/security.yaml is actually "unanimous", not "affirmative" -
 * under that strategy a single denying voter for a given is_granted() call
 * defeats every granting voter, so "either voter granting access is enough"
 * only holds if no OTHER voter also votes on the same (attribute, subject)
 * pair. This is exactly what ResourceVoter must abstain on for the three
 * Homework entities (see its supports() exemption list): ResourceVoter
 * delegates any AbstractResource without a dedicated voter straight back to
 * ResourceNodeVoter's own (CURRENT session-scoped) decision, so without that
 * exemption it would silently shadow this voter's cross-session grant with a
 * denial on the exact same call.
 *
 * @extends Voter<'VIEW'|'EDIT', CHomeworkAssignment|CHomeworkForm|CHomeworkSubmission>
 */
final class HomeworkVoter extends Voter
{
    public const VIEW = 'VIEW';

    /**
     * Deliberately also used to gate the entities' ApiResource Delete
     * operations (alongside DELETE against object.resourceNode): the Huiswerk
     * spec has no "can delete but not edit" role, so HomeworkCourseTeacherChecker's
     * single isTeacherOfCourse() check covers both. This voter intentionally
     * exposes no separate DELETE attribute.
     */
    public const EDIT = 'EDIT';

    public function __construct(
        private readonly HomeworkCourseTeacherChecker $checker
    ) {}

    protected function supports(string $attribute, mixed $subject): bool
    {
        return \in_array($attribute, [self::VIEW, self::EDIT], true)
            && ($subject instanceof CHomeworkAssignment
                || $subject instanceof CHomeworkForm
                || $subject instanceof CHomeworkSubmission);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        $course = $this->resolveCourse($subject);
        if (null === $course) {
            return false;
        }

        return $this->checker->isTeacherOfCourse($user, $course);
    }

    private function resolveCourse(CHomeworkAssignment|CHomeworkForm|CHomeworkSubmission $subject): ?Course
    {
        $resourceHolder = $subject instanceof CHomeworkSubmission
            ? $subject->getAssignment()
            : $subject;

        $links = $resourceHolder->getResourceNode()?->getResourceLinks();
        foreach ($links ?? [] as $link) {
            $course = $link->getCourse();
            if (null !== $course) {
                return $course;
            }
        }

        return null;
    }
}

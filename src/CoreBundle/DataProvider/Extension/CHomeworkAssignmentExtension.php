<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\DataProvider\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Session-aware collection scoping for CHomeworkAssignment, replacing the
 * generic SidFilter (deliberately NOT registered on this entity - see the
 * comment on the class itself).
 *
 * Two requirements that a single generic filter cannot satisfy together:
 *   1. Cursist-facing privacy: a session-scoped assignment (e.g. session A)
 *      must stay invisible to a student browsing from a DIFFERENT session
 *      (B/C) of the same course - confirmed still enforced below.
 *   2. Module's core cross-session teacher requirement ("Lesgevers... via om
 *      het even welke sessie... krijgen volledige inzage... over alle
 *      sessies"): a teacher linked to the course via ANY session
 *      (HomeworkCourseTeacherChecker::isTeacherOfCourse()) must see EVERY
 *      session's assignments, not just the one their current URL happens to
 *      carry as `sid`.
 *
 * SidFilter alone can express #1 (mostly - it does not even show
 * whole-course assignments across sessions, since CHomeworkAssignment does
 * not implement ResourceShowCourseResourcesInSessionInterface either) but has
 * no privilege-aware bypass for #2 - and since filters/extensions each add
 * their own WHERE clause to the same query independently, a privileged
 * teacher would stay blocked by SidFilter's `resource_links.session = :sid`
 * restriction regardless of what this extension does. Confirmed via a live
 * reproduction before this fix: a whole-course (no-session)
 * assignment did not appear in a student's own list while browsing inside
 * ANY session context (not just an unrelated one), and a cross-session
 * teacher's submission-grading flow was blocked the same way (see the
 * analogous fix on CHomeworkSubmission).
 */
class CHomeworkAssignmentExtension implements QueryCollectionExtensionInterface
{
    use CourseLinkExtensionTrait;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly HomeworkCourseTeacherChecker $teacherChecker,
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (CHomeworkAssignment::class !== $resourceClass) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedException();
        }

        if ($user->isSuperAdmin()) {
            return;
        }

        $course = $this->resolveCourseFromRequest();
        if ($course instanceof Course && $this->teacherChecker->isTeacherOfCourse($user, $course)) {
            // Course-wide teacher: every session's assignments, unrestricted.
            return;
        }

        // Idempotent: joins the same "resource_links" alias CidFilter already
        // adds (order between filters/extensions is not guaranteed), same
        // pattern as CHomeworkSubmissionExtension.
        $this->addCourseLinkWithVisibilityConditions($queryBuilder, false);

        $sid = $this->requestStack->getCurrentRequest()?->query->get('sid');

        if (!empty($sid)) {
            // Inside a specific session: that session's assignments PLUS
            // whole-course ones (session IS NULL) - the "loadBaseSessionContent"
            // behavior SidFilter would give an
            // ResourceShowCourseResourcesInSessionInterface-implementing
            // entity, hand-implemented here since privileged teachers need the
            // early-return above instead.
            $queryBuilder
                ->andWhere(
                    $queryBuilder->expr()->orX(
                        'resource_links.session = :homeworkCurrentSession',
                        'resource_links.session IS NULL'
                    )
                )
                ->setParameter('homeworkCurrentSession', (int) $sid)
            ;
        } else {
            // No session in context at all: whole-course assignments only.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    'resource_links.session IS NULL',
                    'resource_links.session = 0'
                )
            );
        }
    }
}

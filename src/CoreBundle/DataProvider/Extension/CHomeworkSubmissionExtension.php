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
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Restricts the CHomeworkSubmission GetCollection results to the requesting
 * student's own submissions.
 *
 * GetCollection's operation-level security only checks
 * ROLE_CURRENT_COURSE_STUDENT/ROLE_CURRENT_COURSE_SESSION_STUDENT - unlike the
 * single-item Get operation (gated additionally by "is_granted('VIEW',
 * object.resourceNode) or is_granted('VIEW', object)", which resolves through
 * ResourceNodeVoter's per-resource-link check), a collection query has no
 * built-in row-level filter. Without this extension, any student enrolled in
 * the course could list every OTHER student's submissions to the same
 * assignment - including their score, feedback and answers - defeating the
 * "cursist: enkel eigen indieningen" requirement documented in
 * tests/CoreBundle/Api/HomeworkPermissionMatrixTest.php (that test only
 * covers the item-level Get; this is its collection-level counterpart).
 *
 * Teachers of the course (HomeworkCourseTeacherChecker - course-wide across
 * sessions, same broadening as HomeworkVoter) and platform admins keep full
 * visibility, matching the rest of the Huiswerk permission model.
 */
class CHomeworkSubmissionExtension implements QueryCollectionExtensionInterface
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
        if (CHomeworkSubmission::class !== $resourceClass) {
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
            return;
        }

        // Idempotent: joins the same "resource_links" alias CidFilter already
        // adds (order between filters/extensions is not guaranteed), without
        // duplicating the join.
        $this->addCourseLinkWithVisibilityConditions($queryBuilder, false);

        $queryBuilder
            ->andWhere('resource_links.user = :homeworkCurrentUser')
            ->setParameter('homeworkCurrentUser', $user->getId())
        ;
    }
}

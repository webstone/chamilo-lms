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
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Mirrors CHomeworkSubmissionExtension exactly, retargeted at
 * CHomeworkAssignmentEvaluation: restricts GetCollection results to the
 * requesting student's own final evaluation (never another student's), full
 * visibility for a course-wide teacher/superadmin - see that extension's
 * docblock for the full reasoning (operation-level security alone has no
 * row-level filter).
 */
class CHomeworkAssignmentEvaluationExtension implements QueryCollectionExtensionInterface
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
        if (CHomeworkAssignmentEvaluation::class !== $resourceClass) {
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

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $joins = $queryBuilder->getDQLPart('join');
        if (empty($joins[$rootAlias]) || !array_filter($joins[$rootAlias], fn ($j) => 'assignment' === $j->getAlias())) {
            $queryBuilder->innerJoin("$rootAlias.assignment", 'assignment');
        }

        $queryBuilder
            ->andWhere('resource_links.user = :homeworkCurrentUser')
            ->andWhere('assignment.deadline < :homeworkNow')
            ->setParameter('homeworkCurrentUser', $user->getId())
            ->setParameter('homeworkNow', new DateTime())
        ;
    }
}

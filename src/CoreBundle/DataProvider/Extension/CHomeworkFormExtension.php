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
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Same fix as CHomeworkAssignmentExtension/CHomeworkSubmissionExtension:
 * SidFilter (deliberately NOT registered on CHomeworkForm - see that
 * entity's docblock) unconditionally restricted GetCollection to
 * `resource_links.session = :sid`, blocking the module's core cross-session
 * teacher requirement whenever `sid` was present (which chomeworkform.js's
 * buildCidParams() always sends while browsing any specific session).
 *
 * CHomeworkForm has no DOMAIN-level session property (unlike
 * CHomeworkAssignment's own `$session`), but that does not mean its
 * ResourceLink is never session-scoped: ResourceListener::prePersist() ->
 * normalizeSingleLinkContextFromSession() stamps `resource_link.session`
 * from whatever session the creating request was browsing in, for EVERY
 * AbstractResource, CHomeworkForm included. CHomeworkForm's own Post
 * operation explicitly allows ROLE_CURRENT_COURSE_SESSION_TEACHER (not just
 * course-wide teachers), so a session-B-only teacher's form genuinely does
 * end up with `resource_link.session = B`. An earlier version of this class
 * treated forms as unconditionally course-wide-visible ("Formuliersjablonen
 * zijn cursusgebonden (niet platformbreed gedeeld)" was read as "visible to
 * the whole course"), which was wrong: that spec line is about the
 * TEACHER-facing reuse scope when saving a template, not about STUDENT
 * visibility - the spec's actual student-rights rule is narrow ("enkel eigen
 * indieningen/score/feedback, enkel opdrachten gericht op hen") and grants no
 * course-wide form-library access. Confirmed live: a student in session A
 * could read a session-B-only form's full pages/fields structure (labels,
 * help text, required flags, options) via GetCollection?sid=A - the same
 * class of gap this whole batch of fixes exists to close, just missed on the
 * first pass here specifically. Session-scoping for the non-privileged path
 * is therefore hand-implemented exactly like CHomeworkAssignmentExtension's:
 * session match OR whole-course (session IS NULL).
 */
class CHomeworkFormExtension implements QueryCollectionExtensionInterface
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
        if (CHomeworkForm::class !== $resourceClass) {
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
            // Course-wide teacher: every session's forms, unrestricted.
            return;
        }

        // Idempotent: joins the same "resource_links" alias CidFilter already
        // adds (order between filters/extensions is not guaranteed), same
        // pattern as the other two Huiswerk extensions.
        $this->addCourseLinkWithVisibilityConditions($queryBuilder, false);

        $sid = $this->requestStack->getCurrentRequest()?->query->get('sid');

        if (!empty($sid)) {
            // Inside a specific session: that session's forms PLUS
            // whole-course ones (session IS NULL).
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
            // No session in context at all: whole-course forms only.
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    'resource_links.session IS NULL',
                    'resource_links.session = 0'
                )
            );
        }
    }
}

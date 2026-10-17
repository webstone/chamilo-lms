<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Chamilo\CoreBundle\ApiResource\HomeworkAssignmentProgress;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @implements ProviderInterface<HomeworkAssignmentProgress>
 */
final class HomeworkAssignmentProgressStateProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly HomeworkCourseTeacherChecker $teacherChecker,
        private readonly RequestStack $requestStack,
    ) {}

    /**
     * @return list<HomeworkAssignmentProgress>
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $assignmentId = (int) $this->requestStack->getCurrentRequest()?->query->get('assignmentId');
        $assignment = $this->entityManager->getRepository(CHomeworkAssignment::class)->find($assignmentId);
        if (!$assignment instanceof CHomeworkAssignment) {
            throw new NotFoundHttpException('Homework assignment not found.');
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException();
        }

        $course = $this->resolveCourse($assignment);
        if (!$course instanceof Course || (!$user->isSuperAdmin() && !$this->teacherChecker->isTeacherOfCourse($user, $course))) {
            throw new AccessDeniedHttpException('Only a course-wide teacher may view assignment progress.');
        }

        $rows = $this->entityManager->getRepository(CHomeworkSubmission::class)
            ->createQueryBuilder('s')
            ->select('IDENTITY(s.user) AS userId', 'u.firstname', 'u.lastname', 's.status')
            ->join('s.user', 'u')
            ->andWhere('s.assignment = :assignment')
            ->andWhere('s.status IN (:submittedStatuses)')
            ->setParameter('assignment', $assignment)
            ->setParameter('submittedStatuses', [
                CHomeworkSubmission::STATUS_SUBMITTED,
                CHomeworkSubmission::STATUS_LATE,
            ])
            ->getQuery()
            ->getArrayResult()
        ;

        $byUser = [];
        foreach ($rows as $row) {
            $uid = (int) $row['userId'];
            $byUser[$uid] ??= ['name' => \trim($row['firstname'].' '.$row['lastname']), 'onTime' => 0, 'total' => 0];
            $byUser[$uid]['total']++;
            if (CHomeworkSubmission::STATUS_SUBMITTED === (int) $row['status']) {
                $byUser[$uid]['onTime']++;
            }
        }

        $progress = [];
        foreach ($byUser as $uid => $data) {
            $progress[] = new HomeworkAssignmentProgress(
                userId: $uid,
                userFullName: $data['name'],
                onTimeCount: $data['onTime'],
                totalCount: $data['total'],
                requiredCount: $assignment->getRequiredSubmissionCount(),
            );
        }

        return $progress;
    }

    private function resolveCourse(CHomeworkAssignment $assignment): ?Course
    {
        $links = $assignment->getResourceNode()?->getResourceLinks();
        foreach ($links ?? [] as $link) {
            $course = $link->getCourse();
            if (null !== $course) {
                return $course;
            }
        }

        return null;
    }
}

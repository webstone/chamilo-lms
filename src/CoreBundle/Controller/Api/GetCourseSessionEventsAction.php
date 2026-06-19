<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelUser;
use Chamilo\CoreBundle\Entity\User;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class GetCourseSessionEventsAction
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {}

    public function __invoke(Course $data): JsonResponse
    {
        $now = new DateTime('now');
        $viewer = $this->security->getUser();

        // Fetch all sessions attached to this course via the SessionRelCourse join entity.
        // Session::$courses is a OneToMany to SessionRelCourse, and SessionRelCourse has a
        // ManyToOne to Course stored as 'course'. We join through 's.courses' (the collection
        // of SessionRelCourse rows) and filter by 'src.course = :course'.
        $sessions = $this->em->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->innerJoin('s.courses', 'src')
            ->where('src.course = :course')
            ->setParameter('course', $data)
            ->getQuery()
            ->getResult()
        ;

        $enrolledSessionIds = [];
        if ($viewer instanceof User && [] !== $sessions) {
            $rows = $this->em->createQueryBuilder()
                ->select('IDENTITY(sru.session) AS sid')
                ->from(SessionRelUser::class, 'sru')
                ->where('sru.user = :user')
                ->andWhere('sru.session IN (:sessions)')
                ->setParameter('user', $viewer)
                ->setParameter('sessions', $sessions)
                ->getQuery()
                ->getArrayResult()
            ;
            // IDENTITY() returns raw PDO string values; cast to int for strict in_array() below.
            $enrolledSessionIds = array_map(static fn (array $r): int => (int) $r['sid'], $rows);
        }

        $events = [];
        foreach ($sessions as $session) {
            $start = $session->getDisplayStartDate();
            if (null === $start) {
                continue;
            }

            $end = $session->getDisplayEndDate();
            $isPast = null !== $end && $end < $now;

            $events[] = [
                'id' => 'session-'.$session->getId(),
                'title' => $data->getTitle(),
                'start' => $start->format('c'),
                'end' => $end?->format('c'),
                'allDay' => false,
                'extendedProps' => [
                    'courseId' => $data->getId(),
                    'sessionId' => $session->getId(),
                    'sessionTitle' => $session->getTitle(),
                    'sessionStart' => $start->format('c'),
                    'sessionEnd' => $end?->format('c'),
                    'isPast' => $isPast,
                    'isViewerEnrolled' => \in_array($session->getId(), $enrolledSessionIds, true),
                ],
            ];
        }

        return new JsonResponse($events);
    }
}

<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Repository;

use Chamilo\CoreBundle\Entity\AccessUrl;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelCourseRelUser;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CLp;
use Chamilo\CourseBundle\Entity\CLpView;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class SessionRelCourseRelUserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SessionRelCourseRelUser::class);
    }

    /**
     * Every (session, course) pair the given user is linked to via
     * SessionRelCourseRelUser - the relation Session::addUserInCourse()
     * actually writes to for regular students and per-course session
     * coaches (unlike SessionRelUser, which only ever holds General Coach /
     * Session Admin rows).
     *
     * @return array<int, array{sessionId: int, courseId: int}>
     */
    public function getSessionCourseIdPairsByUser(User $user, AccessUrl $accessUrl): array
    {
        return $this->createQueryBuilder('scu')
            ->select('IDENTITY(scu.session) AS sessionId, IDENTITY(scu.course) AS courseId')
            ->innerJoin('scu.session', 'session')
            ->innerJoin('session.urls', 'urls')
            ->where('scu.user = :user')
            ->andWhere('urls.url = :url')
            ->setParameter('user', $user)
            ->setParameter('url', $accessUrl)
            ->getQuery()
            ->getResult()
        ;
    }

    /**
     * Every (Session, Course) pair the given user is enrolled in via
     * SessionRelCourseRelUser, as full entities (not just ids) - used to
     * build the session-markers payload for the personal/global Agenda.
     *
     * @return array<int, array{session: Session, course: Course}>
     */
    public function getSessionsWithCourseByUser(User $user, AccessUrl $accessUrl): array
    {
        $relations = $this->createQueryBuilder('scu')
            ->select('scu', 'session', 'course')
            ->innerJoin('scu.session', 'session')
            ->innerJoin('scu.course', 'course')
            ->innerJoin('session.urls', 'urls')
            ->where('scu.user = :user')
            ->andWhere('urls.url = :url')
            ->setParameter('user', $user)
            ->setParameter('url', $accessUrl)
            ->getQuery()
            ->getResult()
        ;

        return array_map(
            static fn (SessionRelCourseRelUser $relation): array => [
                'session' => $relation->getSession(),
                'course' => $relation->getCourse(),
            ],
            $relations
        );
    }

    /**
     * Retrieves users from a course session and their LP progress.
     */
    public function getSessionCourseUsers(int $courseId, array $lpIds): array
    {
        $qb = $this->createQueryBuilder('scu')
            ->select('u.id AS userId, c.title AS courseTitle, lp.iid AS lpId, COALESCE(lpv.progress, 0) AS progress, IDENTITY(scu.session) AS sessionId')
            ->innerJoin('scu.user', 'u')
            ->innerJoin('scu.course', 'c')
            ->leftJoin(CLpView::class, 'lpv', 'WITH', 'lpv.user = u.id AND lpv.course = scu.course AND lpv.lp IN (:lpIds)')
            ->leftJoin(CLp::class, 'lp', 'WITH', 'lp.iid IN (:lpIds)')
            ->innerJoin('lp.resourceNode', 'rn')
            ->where('scu.course = :courseId')
            ->andWhere('rn.parent = c.resourceNode')
            ->andWhere('(lpv.progress < 100 OR lpv.progress IS NULL)')
            ->setParameter('courseId', $courseId)
            ->setParameter('lpIds', $lpIds)
        ;

        return $qb->getQuery()->getResult();
    }
}

<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\SessionRelCourse;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Helpers\AccessUrlHelper;
use Chamilo\CoreBundle\Repository\SessionRelCourseRelUserRepository;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;

#[AsController]
final class GetMySessionEventsAction
{
    public function __construct(
        private readonly SessionRelCourseRelUserRepository $sessionRelCourseRelUserRepository,
        private readonly AccessUrlHelper $accessUrlHelper,
        private readonly EntityManagerInterface $em,
    ) {}

    public function __invoke(User $data, Request $request): JsonResponse
    {
        $now = new DateTime('now');
        $accessUrl = $this->accessUrlHelper->getCurrent();

        // $data is the User named in the URL (/users/{id}/session_events) -
        // the Get operation's own security expression ("is_granted('ROLE_ADMIN')
        // or object == user") restricts this to the viewer themself or an
        // admin, so $data IS "the user whose sessions to list", not
        // necessarily the current request's authenticated user. This
        // intentionally does NOT use UserVoter::VIEW, which is broader
        // (it also grants social-network friends, friends-of-friends,
        // "boss" relations, and message recipients/senders) and would be
        // unsuitable for this endpoint's private schedule data.
        $pairs = $this->sessionRelCourseRelUserRepository->getSessionsWithCourseByUser($data, $accessUrl);

        // A course-level teacher (CourseRelUser::TEACHER) sees every session
        // attached to "their" course, even with no session-level relation of
        // their own - same precedent already established in
        // HomeworkCourseTeacherChecker for the Homework module.
        //
        // The Course side is scoped to the current AccessUrl (mirroring
        // CourseRepository::getCoursesByUser/getCoursesInfoByUser) - a
        // Course can legitimately belong to more than one AccessUrl on a
        // multi-tenant install, with no guarantee its sessions share the
        // same AccessUrl, so an unscoped lookup here would risk leaking
        // another tenant's session titles/dates to this teacher.
        $courseTeacherLinks = $this->em->createQueryBuilder()
            ->select('cru', 'c')
            ->from(CourseRelUser::class, 'cru')
            ->innerJoin('cru.course', 'c')
            ->innerJoin('c.urls', 'courseUrls')
            ->where('cru.user = :user')
            ->andWhere('cru.status = :teacherStatus')
            ->andWhere('courseUrls.url = :url')
            ->setParameter('user', $data)
            ->setParameter('teacherStatus', CourseRelUser::TEACHER)
            ->setParameter('url', $accessUrl)
            ->getQuery()
            ->getResult()
        ;

        // This source is appended BEFORE the General Coach source below: when
        // the same session is reachable through both (e.g. the user is also
        // General Coach of a session attached to a course they teach), the
        // existing first-wins dedup (see the $pairs loop) must keep this
        // more specific, genuinely-taught course rather than the General
        // Coach source's arbitrary "first attached course" pick.
        if ([] !== $courseTeacherLinks) {
            $teacherCourses = array_map(
                static fn (CourseRelUser $courseRelUser) => $courseRelUser->getCourse(),
                $courseTeacherLinks
            );

            // Rooted on SessionRelCourse (not Session) so the matching
            // Session and Course are eager-selected directly off each row -
            // same pattern as SessionRelCourseRelUserRepository::getSessionsWithCourseByUser()
            // - instead of doing a PHP-side foreach ($session->getCourses())
            // per session afterward, which would force-initialize the
            // EXTRA_LAZY Session::$courses collection with one extra query
            // per session (N+1). Filtering by "c IN (:courses)" here also
            // moves the "is this actually a course $data teaches" check into
            // the query itself, rather than an in_array() done in PHP.
            $courseTeacherRelations = $this->em->createQueryBuilder()
                ->select('src', 's', 'c')
                ->from(SessionRelCourse::class, 'src')
                ->innerJoin('src.session', 's')
                ->innerJoin('src.course', 'c')
                ->innerJoin('s.urls', 'urls')
                ->where('c IN (:courses)')
                ->andWhere('urls.url = :url')
                ->setParameter('courses', $teacherCourses)
                ->setParameter('url', $accessUrl)
                ->getQuery()
                ->getResult()
            ;

            foreach ($courseTeacherRelations as $sessionRelCourse) {
                $pairs[] = [
                    'session' => $sessionRelCourse->getSession(),
                    'course' => $sessionRelCourse->getCourse(),
                ];
            }
        }

        // A user can also be "part of" a session as a General Coach/Session
        // Admin (SessionRelUser) without ever having a per-course
        // SessionRelCourseRelUser enrollment row - that relationship is
        // separate from regular student/course-coach enrollment and was
        // being missed entirely here, so it never showed up in the personal
        // Agenda even though it's a legitimate "I'm responsible for this
        // session" relation (see the sibling course-scoped endpoint, which
        // already accounts for both sources when flagging isViewerEnrolled).
        //
        // A session can be linked to more than one course (SessionRelCourse);
        // a General Coach/Session Admin relationship (SessionRelUser) isn't
        // tied to any single one of them the way SessionRelCourseRelUser is,
        // so this just picks whichever attached course comes back first for
        // the event's title - sessions with zero attached courses are
        // excluded by the innerJoin (nothing sensible to show as the title).
        // Rooted on SessionRelCourse for the same N+1-avoidance reason as
        // the course-teacher source above - Session and Course come back
        // eager-selected in the same query, no PHP-side
        // foreach ($session->getCourses()) needed.
        $generalCoachRelations = $this->em->createQueryBuilder()
            ->select('src', 's', 'c')
            ->from(SessionRelCourse::class, 'src')
            ->innerJoin('src.session', 's')
            ->innerJoin('src.course', 'c')
            ->innerJoin('s.users', 'sru')
            ->innerJoin('s.urls', 'urls')
            ->where('sru.user = :user')
            ->andWhere('urls.url = :url')
            ->setParameter('user', $data)
            ->setParameter('url', $accessUrl)
            ->getQuery()
            ->getResult()
        ;

        foreach ($generalCoachRelations as $sessionRelCourse) {
            $pairs[] = [
                'session' => $sessionRelCourse->getSession(),
                'course' => $sessionRelCourse->getCourse(),
            ];
        }

        $events = [];
        $seenSessionIds = [];

        foreach ($pairs as $pair) {
            $session = $pair['session'];
            $course = $pair['course'];

            // A user can be linked to the same session via more than one
            // SessionRelCourseRelUser row (e.g. distinct statuses) - only
            // ever emit one marker per session.
            if (isset($seenSessionIds[$session->getId()])) {
                continue;
            }
            $seenSessionIds[$session->getId()] = true;

            $start = $session->getDisplayStartDate();
            if (null === $start) {
                continue;
            }

            $end = $session->getDisplayEndDate();
            $isPast = null !== $end && $end < $now;

            $events[] = [
                'id' => 'session-'.$session->getId(),
                'title' => $course->getTitle(),
                'start' => $start->format('c'),
                'end' => $end?->format('c'),
                'allDay' => false,
                'extendedProps' => [
                    'courseId' => $course->getId(),
                    'sessionId' => $session->getId(),
                    'sessionTitle' => $session->getTitle(),
                    'sessionStart' => $start->format('c'),
                    'sessionEnd' => $end?->format('c'),
                    'isPast' => $isPast,
                    // Every session returned here is, by construction, one
                    // $data is enrolled in - unlike the course-scoped
                    // endpoint, there is no "someone else's session" case.
                    'isViewerEnrolled' => true,
                ],
            ];
        }

        return new JsonResponse($events);
    }
}

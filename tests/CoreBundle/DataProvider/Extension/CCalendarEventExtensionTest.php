<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\DataProvider\Extension;

use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CourseBundle\Entity\CCalendarEvent;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

/**
 * Covers CCalendarEventExtension::addSessionConditions() - the personal
 * ("global") Agenda's aggregation of session-scoped calendar events for the
 * logged-in user. See project_global_agenda_sessions.md session memory for
 * the bug report this was written to reproduce: a regular student/teacher
 * enrolled in a session only via SessionRelCourseRelUser (the only relation
 * Session::addUserInCourse() ever writes to) never saw that session's
 * calendar events in their own Agenda, because the extension's non-DRH
 * branch derived its session list from SessionRelUser instead (a completely
 * separate relation, only ever populated for General Coach/Session Admin).
 */
final class CCalendarEventExtensionTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testStudentSeesSessionScopedEventFromEnrolledSession(): void
    {
        $course = $this->createCourse('agenda_session_course_student');
        $session = $this->createSession('agenda_session_session_student');
        $student = $this->createUser('agenda_session_student');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        $event = (new CCalendarEvent())
            ->setTitle('Sessie-event voor cursist')
            ->setContent('Inhoud')
            ->setStartDate(new DateTime('2040-06-30 11:00'))
            ->setEndDate(new DateTime('2040-06-30 15:00'))
            ->setCreator($this->getUser('admin'))
        ;
        $event->setParent($course);
        $event->addCourseLink($course, $session);

        $em->persist($event);
        $em->flush();

        $token = $this->getUserTokenFromUser($student);

        $response = $this->createClientWithCredentials($token)->request('GET', '/api/c_calendar_events');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertSame(
            1,
            $data['hydra:totalItems'],
            'A student enrolled in the session (via SessionRelCourseRelUser) must see the session-scoped event in their personal agenda.'
        );
    }

    public function testCourseCoachSeesSessionScopedEventFromEnrolledSession(): void
    {
        $course = $this->createCourse('agenda_session_course_teacher');
        $session = $this->createSession('agenda_session_session_teacher');
        $teacher = $this->createUser('agenda_session_teacher');

        $session->addCourse($course);
        $session->addUserInCourse(Session::COURSE_COACH, $teacher, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        $event = (new CCalendarEvent())
            ->setTitle('Sessie-event voor lesgever')
            ->setContent('Inhoud')
            ->setStartDate(new DateTime('2040-06-30 11:00'))
            ->setEndDate(new DateTime('2040-06-30 15:00'))
            ->setCreator($this->getUser('admin'))
        ;
        $event->setParent($course);
        $event->addCourseLink($course, $session);

        $em->persist($event);
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request('GET', '/api/c_calendar_events');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertSame(
            1,
            $data['hydra:totalItems'],
            'A course coach enrolled in the session (via SessionRelCourseRelUser) must see the session-scoped event in their personal agenda.'
        );
    }

    public function testStudentDoesNotSeeSessionScopedEventFromUnrelatedSession(): void
    {
        $course = $this->createCourse('agenda_session_course_unrelated');
        $session = $this->createSession('agenda_session_session_unrelated');
        $otherStudent = $this->createUser('agenda_session_other_student');
        $outsider = $this->createUser('agenda_session_outsider');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $otherStudent, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        $event = (new CCalendarEvent())
            ->setTitle('Sessie-event niet voor buitenstaander')
            ->setContent('Inhoud')
            ->setStartDate(new DateTime('2040-06-30 11:00'))
            ->setEndDate(new DateTime('2040-06-30 15:00'))
            ->setCreator($this->getUser('admin'))
        ;
        $event->setParent($course);
        $event->addCourseLink($course, $session);

        $em->persist($event);
        $em->flush();

        $token = $this->getUserTokenFromUser($outsider);

        $response = $this->createClientWithCredentials($token)->request('GET', '/api/c_calendar_events');

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        $this->assertSame(
            0,
            $data['hydra:totalItems'],
            'A user with no relation to the session must not see its session-scoped event.'
        );
    }
}

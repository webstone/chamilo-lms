<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelUser;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

final class GetCourseSessionEventsActionTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testReturnsEmptyArrayWhenCourseHasNoSessions(): void
    {
        $teacher = $this->createUser('teacher_no_sessions', 'teacher_no_sessions');
        $course = $this->createCourse('Course without sessions');
        // Course visibility defaults to OPEN_PLATFORM; CourseVoter grants VIEW
        // to any authenticated user, so no explicit subscription is needed here.

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $body);
    }

    public function testReturnsOneEventPerSessionWithIsoStartDate(): void
    {
        $teacher = $this->createUser('teacher_one_session', 'teacher_one_session');
        $course = $this->createCourse('Course with one session');

        $session = $this->createSessionWithDates(
            'Future session',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $session->addCourse($course);
        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $body);
        $event = $body[0];
        $this->assertSame('session-'.$session->getId(), $event['id']);
        $this->assertSame($course->getTitle(), $event['title']);
        $this->assertSame($session->getDisplayStartDate()->format('c'), $event['start']);
        $this->assertSame($session->getDisplayEndDate()->format('c'), $event['end']);
        $this->assertFalse($event['allDay']);
        $this->assertSame($course->getId(), $event['extendedProps']['courseId']);
        $this->assertSame($session->getId(), $event['extendedProps']['sessionId']);
        $this->assertSame($session->getTitle(), $event['extendedProps']['sessionTitle']);
        $this->assertFalse($event['extendedProps']['isPast']);
    }

    public function testIsPastIsTrueOnlyWhenEndDateInPast(): void
    {
        $teacher = $this->createUser('teacher_three_sessions', 'teacher_three_sessions');
        $course = $this->createCourse('Course with three sessions');

        $pastSession = $this->createSessionWithDates(
            'Past',
            new DateTime('-90 days'),
            new DateTime('-30 days'),
        );
        $pastSession->addCourse($course);

        $ongoingSession = $this->createSessionWithDates(
            'Ongoing',
            new DateTime('-5 days'),
            new DateTime('+5 days'),
        );
        $ongoingSession->addCourse($course);

        $upcomingSession = $this->createSessionWithDates(
            'Upcoming',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $upcomingSession->addCourse($course);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(3, $body);

        $bySessionTitle = [];
        foreach ($body as $event) {
            $bySessionTitle[$event['extendedProps']['sessionTitle']] = $event;
        }

        $this->assertTrue($bySessionTitle['Past']['extendedProps']['isPast']);
        $this->assertFalse($bySessionTitle['Ongoing']['extendedProps']['isPast']);
        $this->assertFalse($bySessionTitle['Upcoming']['extendedProps']['isPast']);
    }

    public function testNullEndDateIsNeverPast(): void
    {
        $teacher = $this->createUser('teacher_null_end', 'teacher_null_end');
        $course = $this->createCourse('Course with open-ended session');

        $session = $this->createSessionWithDates(
            'Open-ended past start',
            new DateTime('-200 days'),
            null,
        );
        $session->addCourse($course);
        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $body);
        $this->assertFalse($body[0]['extendedProps']['isPast']);
    }

    public function testSessionWithNullStartDateIsSkipped(): void
    {
        $teacher = $this->createUser('teacher_null_start', 'teacher_null_start');
        $course = $this->createCourse('Course with null-start session');

        $withStart = $this->createSessionWithDates(
            'With start',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $withStart->addCourse($course);

        $noStart = $this->createSessionWithDates('No start', null, null);
        $noStart->addCourse($course);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $body);
        $this->assertSame('With start', $body[0]['extendedProps']['sessionTitle']);
    }

    public function testIsViewerEnrolledIsTrueOnlyForSessionsTheViewerIsIn(): void
    {
        $em = $this->getEntityManager();

        $student = $this->createUser('student_sess_a', 'student_sess_a');
        $course = $this->createCourse('Course with two sessions');

        $sessionA = $this->createSessionWithDates('Cohort A', new DateTime('+10 days'), new DateTime('+40 days'));
        $sessionA->addCourse($course);

        $sessionB = $this->createSessionWithDates('Cohort B', new DateTime('+50 days'), new DateTime('+90 days'));
        $sessionB->addCourse($course);

        // Student enrolled only in cohort A.
        $sru = new SessionRelUser();
        $sru->setSession($sessionA);
        $sru->setUser($student);
        $sru->setRelationType(Session::STUDENT);

        $em->persist($sru);
        $em->flush();

        $token = $this->getUserTokenFromUser($student);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $body = json_decode($client->getResponse()->getContent(), true);
        $bySessionTitle = [];
        foreach ($body as $e) {
            $bySessionTitle[$e['extendedProps']['sessionTitle']] = $e;
        }

        self::assertTrue($bySessionTitle['Cohort A']['extendedProps']['isViewerEnrolled']);
        self::assertFalse($bySessionTitle['Cohort B']['extendedProps']['isViewerEnrolled']);
    }

    public function testNonSubscribedNonAdminGetsForbidden(): void
    {
        $em = $this->getEntityManager();

        $teacher = $this->createUser('teacher_owner', 'teacher_owner');
        $stranger = $this->createUser('stranger', 'stranger');
        $course = $this->createCourse('Private course');
        // Force REGISTERED visibility so only subscribed users + admins can VIEW.
        $course->setVisibility(Course::REGISTERED);

        $rel = (new CourseRelUser())
            ->setCourse($course)
            ->setUser($teacher)
            ->setStatus(CourseRelUser::TEACHER)
        ;
        $em->persist($rel);
        $em->flush();

        $token = $this->getUserTokenFromUser($stranger);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/courses/'.$course->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * Creates a Session with specific display dates and persists it.
     * The trait's createSession() does not accept date parameters, so we
     * use a dedicated helper here that mirrors its setup.
     */
    private function createSessionWithDates(string $title, ?DateTime $start, ?DateTime $end): Session
    {
        $session = (new Session())
            ->setTitle($title)
            ->setDisplayStartDate($start)
            ->setDisplayEndDate($end)
            ->addGeneralCoach($this->getUser('admin'))
            ->addAccessUrl($this->getAccessUrl())
        ;

        $em = $this->getEntityManager();
        $em->persist($session);

        return $session;
    }
}

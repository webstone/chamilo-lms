<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\Message;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Repository\MessageRepository;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

final class GetMySessionEventsActionTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testReturnsEmptyArrayWhenUserHasNoSessions(): void
    {
        $student = $this->createUser('my_events_no_sessions', 'my_events_no_sessions');

        $token = $this->getUserTokenFromUser($student);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $body);
    }

    public function testReturnsOneEventPerEnrolledSessionAcrossDifferentCourses(): void
    {
        $student = $this->createUser('my_events_two_courses', 'my_events_two_courses');

        $courseA = $this->createCourse('Course A for my_events');
        $sessionA = $this->createSessionWithDates(
            'Session in course A',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $sessionA->addCourse($courseA);
        $sessionA->addUserInCourse(Session::STUDENT, $student, $courseA);

        $courseB = $this->createCourse('Course B for my_events');
        $sessionB = $this->createSessionWithDates(
            'Session in course B',
            new DateTime('+50 days'),
            new DateTime('+90 days'),
        );
        $sessionB->addCourse($courseB);
        $sessionB->addUserInCourse(Session::COURSE_COACH, $student, $courseB);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($student);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(2, $body);

        $byTitle = [];
        foreach ($body as $event) {
            $byTitle[$event['extendedProps']['sessionTitle']] = $event;
        }

        $eventA = $byTitle['Session in course A'];
        $this->assertSame('session-'.$sessionA->getId(), $eventA['id']);
        $this->assertSame($courseA->getTitle(), $eventA['title']);
        $this->assertSame($sessionA->getDisplayStartDate()->format('c'), $eventA['start']);
        $this->assertFalse($eventA['allDay']);
        $this->assertSame($courseA->getId(), $eventA['extendedProps']['courseId']);
        $this->assertSame($sessionA->getId(), $eventA['extendedProps']['sessionId']);
        $this->assertFalse($eventA['extendedProps']['isPast']);
        $this->assertTrue($eventA['extendedProps']['isViewerEnrolled']);

        $eventB = $byTitle['Session in course B'];
        $this->assertSame($courseB->getTitle(), $eventB['title']);
        $this->assertTrue($eventB['extendedProps']['isViewerEnrolled']);
    }

    public function testDoesNotReturnSessionsFromUnrelatedCourses(): void
    {
        $student = $this->createUser('my_events_unrelated', 'my_events_unrelated');
        $otherStudent = $this->createUser('my_events_other', 'my_events_other');

        $course = $this->createCourse('Course unrelated to viewer');
        $session = $this->createSessionWithDates(
            'Unrelated session',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $otherStudent, $course);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($student);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);
        $this->assertSame([], $body);
    }

    public function testIsPastIsTrueOnlyWhenEndDateInPast(): void
    {
        $student = $this->createUser('my_events_past', 'my_events_past');
        $course = $this->createCourse('Course for past-session test');

        $pastSession = $this->createSessionWithDates('Past', new DateTime('-90 days'), new DateTime('-30 days'));
        $pastSession->addCourse($course);
        $pastSession->addUserInCourse(Session::STUDENT, $student, $course);

        $upcomingSession = $this->createSessionWithDates('Upcoming', new DateTime('+10 days'), new DateTime('+40 days'));
        $upcomingSession->addCourse($course);
        $upcomingSession->addUserInCourse(Session::STUDENT, $student, $course);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($student);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $body = json_decode($client->getResponse()->getContent(), true);
        $byTitle = [];
        foreach ($body as $event) {
            $byTitle[$event['extendedProps']['sessionTitle']] = $event;
        }

        $this->assertTrue($byTitle['Past']['extendedProps']['isPast']);
        $this->assertFalse($byTitle['Upcoming']['extendedProps']['isPast']);
    }

    public function testViewingAnotherUsersSessionsAsNonAdminIsForbidden(): void
    {
        $student = $this->createUser('my_events_owner', 'my_events_owner');
        $stranger = $this->createUser('my_events_stranger', 'my_events_stranger');

        $token = $this->getUserTokenFromUser($stranger);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testViewingAnotherUsersSessionsIsForbiddenEvenForAMessageRecipient(): void
    {
        $student = $this->createUser('my_events_msg_owner', 'my_events_msg_owner');
        $messageRecipient = $this->createUser('my_events_msg_recipient', 'my_events_msg_recipient');

        // $messageRecipient has received an internal Message sent by $student.
        // Under the old, broader UserVoter::VIEW check this alone was enough
        // to grant VIEW on $student to $messageRecipient - the tighter,
        // self-or-admin-only security expression on this operation must NOT
        // honor that relation.
        $messageRepo = self::getContainer()->get(MessageRepository::class);

        $message = (new Message())
            ->setTitle('hello')
            ->setContent('content')
            ->setMsgType(Message::MESSAGE_TYPE_INBOX)
            ->setSender($student)
            ->addReceiverTo($messageRecipient)
        ;

        $messageRepo->update($message);

        $token = $this->getUserTokenFromUser($messageRecipient);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$student->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        self::assertResponseStatusCodeSame(403);
    }

    public function testReturnsSessionsWhereViewerIsGeneralCoachEvenWithoutCourseEnrollment(): void
    {
        $coach = $this->createUser('my_events_general_coach', 'my_events_general_coach');
        $course = $this->createCourse('Course coached without enrollment');

        $session = $this->createSessionWithDates(
            'Coached session',
            new DateTime('+10 days'),
            new DateTime('+40 days'),
        );
        $session->addCourse($course);
        // Deliberately NOT calling addUserInCourse() - this user has zero
        // SessionRelCourseRelUser rows, only a SessionRelUser row (General
        // Coach), which is what this test is proving gets picked up too.
        $session->addGeneralCoach($coach);

        $this->getEntityManager()->flush();

        $token = $this->getUserTokenFromUser($coach);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$coach->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $body);
        $this->assertSame('session-'.$session->getId(), $body[0]['id']);
        $this->assertSame($course->getTitle(), $body[0]['title']);
        $this->assertSame('Coached session', $body[0]['extendedProps']['sessionTitle']);
        $this->assertTrue($body[0]['extendedProps']['isViewerEnrolled']);
    }

    public function testReturnsAllSessionsOfACourseTheViewerTeachesAtCourseLevel(): void
    {
        $teacher = $this->createUser('my_events_course_teacher', 'my_events_course_teacher');
        $course = $this->createCourse('Course taught at course level');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        // Two sessions attached to the course - the teacher has NO
        // session-level relation to either (no SessionRelCourseRelUser, no
        // SessionRelUser row) - only the course-level CourseRelUser::TEACHER
        // link above. Both sessions must still show up, per the same
        // precedent already established in HomeworkCourseTeacherChecker.
        $sessionA = $this->createSessionWithDates('Course-level A', new DateTime('+10 days'), new DateTime('+40 days'));
        $sessionA->addCourse($course);

        $sessionB = $this->createSessionWithDates('Course-level B', new DateTime('+50 days'), new DateTime('+90 days'));
        $sessionB->addCourse($course);

        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$teacher->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(2, $body);

        $byTitle = [];
        foreach ($body as $event) {
            $byTitle[$event['extendedProps']['sessionTitle']] = $event;
        }

        $this->assertArrayHasKey('Course-level A', $byTitle);
        $this->assertArrayHasKey('Course-level B', $byTitle);
        $this->assertSame($course->getTitle(), $byTitle['Course-level A']['title']);
        $this->assertTrue($byTitle['Course-level A']['extendedProps']['isViewerEnrolled']);
        $this->assertTrue($byTitle['Course-level B']['extendedProps']['isViewerEnrolled']);
    }

    public function testCourseTeacherSourceTakesPrecedenceOverGeneralCoachForSameSession(): void
    {
        $teacher = $this->createUser('my_events_precedence', 'my_events_precedence');

        $taughtCourse = $this->createCourse('Taught course (precedence)');
        $taughtCourse->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $unrelatedCourse = $this->createCourse('Unrelated course (precedence)');

        $em = $this->getEntityManager();
        $em->persist($taughtCourse);
        $em->persist($unrelatedCourse);
        $em->flush();

        // Session attached to BOTH courses - the teacher only actually
        // teaches $taughtCourse, but is also General Coach of the session
        // itself, which (before this fix) could have picked $unrelatedCourse
        // as the event's title depending on attachment order.
        $session = $this->createSessionWithDates('Precedence session', new DateTime('+10 days'), new DateTime('+40 days'));
        $session->addCourse($unrelatedCourse);
        $session->addCourse($taughtCourse);
        $session->addGeneralCoach($teacher);

        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $client->request(
            'GET',
            '/api/users/'.$teacher->getId().'/session_events',
            ['headers' => ['Accept' => 'application/json']]
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($client->getResponse()->getContent(), true);

        $this->assertCount(1, $body);
        $this->assertSame($taughtCourse->getTitle(), $body[0]['title']);
    }

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

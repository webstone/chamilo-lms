<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Security;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

final class HomeworkCourseTeacherCheckerTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testTeacherLinkedOnlyToSecondSessionOfCourseIsRecognizedAsCourseTeacher(): void
    {
        $course = $this->createCourse('homework_checker_course');
        $sessionA = $this->createSession('homework_checker_session_a');
        $sessionB = $this->createSession('homework_checker_session_b');
        $teacher = $this->createUser('homework_checker_teacher');
        $otherCoach = $this->createUser('homework_checker_other_coach');

        $sessionA->addCourse($course);
        $sessionB->addCourse($course);

        // The teacher under test has NO relation whatsoever to session A (which
        // has its own, different, course coach) and is NOT linked to the course
        // directly. The teacher is only a course coach of session B - the
        // second session, not the "current"/first one. This proves the checker
        // scans across ALL sessions of the course rather than only the first
        // one it happens to encounter, and is not accidentally scoped to a
        // single/"current" session.
        $sessionA->addUserInCourse(Session::COURSE_COACH, $otherCoach, $course);
        $sessionB->addUserInCourse(Session::COURSE_COACH, $teacher, $course);

        $em = $this->getEntityManager();
        $em->persist($sessionA);
        $em->persist($sessionB);
        $em->flush();

        /** @var HomeworkCourseTeacherChecker $checker */
        $checker = static::getContainer()->get(HomeworkCourseTeacherChecker::class);

        $this->assertTrue($checker->isTeacherOfCourse($teacher, $course));
    }

    public function testStudentIsNotRecognizedAsCourseTeacher(): void
    {
        $course = $this->createCourse('homework_checker_course_student');
        $student = $this->createUser('homework_checker_student');

        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        /** @var HomeworkCourseTeacherChecker $checker */
        $checker = static::getContainer()->get(HomeworkCourseTeacherChecker::class);

        $this->assertFalse($checker->isTeacherOfCourse($student, $course));
    }

    public function testStudentSubscribedToSessionOfCourseIsNotRecognizedAsCourseTeacher(): void
    {
        // This pins down that the session-level check only counts
        // Session::COURSE_COACH, not "any session relation to the course".
        // A regression that dropped the status filter from the session-level
        // findOneBy() would make this test fail.
        $course = $this->createCourse('homework_checker_course_session_student');
        $session = $this->createSession('homework_checker_session_student');
        $student = $this->createUser('homework_checker_session_student_user');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        /** @var HomeworkCourseTeacherChecker $checker */
        $checker = static::getContainer()->get(HomeworkCourseTeacherChecker::class);

        $this->assertFalse($checker->isTeacherOfCourse($student, $course));
    }
}

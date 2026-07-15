<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\State;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CourseBundle\Entity\CCalendarEvent;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

/**
 * Functional (HTTP, full API-Platform pipeline) coverage for
 * CHomeworkAssignmentPostStateProcessor. Modeled on
 * tests/CourseBundle/Repository/CToolIntroRepositoryTest.php for the
 * authenticated-request pattern (AbstractApiTest::createClientWithCredentials())
 * and on tests/CoreBundle/Security/HomeworkCourseTeacherCheckerTest.php for
 * granting the requesting user teacher rights on the course
 * (Course::addSubscriptionForUser(..., CourseRelUser::TEACHER)).
 *
 * NOTE: this is a StateProcessor, so its calendar/gradebook/email side
 * effects only run inside the real API request pipeline - there is no
 * repository-level shortcut the way Tasks 1-8 tested entities/services
 * directly. A POST here relies on the ROLE_CURRENT_COURSE_TEACHER security
 * expression on CHomeworkAssignment's Post operation, which (as of this
 * writing) has no other functional-HTTP test exercising it anywhere in this
 * suite to confirm the request-shape (cid query param + course subscription)
 * against. It is also subject to the known infra gap documented in the task
 * brief: anything going through ChamiloTestTrait::createCourse() currently
 * fails with "Access denied ... chamilo_test" in this environment, so these
 * tests could not be confirmed green via phpunit here; the processor's
 * business logic was instead verified separately via temporary dev-DB
 * scripts (see Task 9 report and its follow-up fix for the
 * strict-comparison email bug). All of these tests use the same real
 * HTTP-request pattern deliberately - it's what naturally triggers the
 * legacy Database/Container bootstrap that api_get_course_setting(),
 * MessageManager, etc. depend on, which a bare service call or console
 * script does not get for free.
 */
final class CHomeworkAssignmentPostStateProcessorTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCreatingAssignmentWithAddToCalendarCreatesCalendarEvent(): void
    {
        $course = $this->createCourse('homework_processor_course');
        $teacher = $this->createUser('homework_processor_teacher');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => [
                    'cid' => $course->getId(),
                ],
                'json' => [
                    'title' => 'Verslag les 5',
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2026-08-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => true,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                        ],
                    ],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $assignmentId = $response->toArray()['iid'];

        $em->clear();

        /** @var CHomeworkAssignment $assignment */
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignmentId);
        $this->assertNotNull($assignment);
        $this->assertGreaterThan(0, $assignment->getEventCalendarId());

        /** @var CCalendarEvent $event */
        $event = $em->getRepository(CCalendarEvent::class)->find($assignment->getEventCalendarId());
        $this->assertNotNull($event);
        $this->assertStringContainsString('Verslag les 5', (string) $event->getContent());
    }

    public function testCreatingAssignmentWithoutAddToCalendarLeavesEventCalendarIdAtZero(): void
    {
        $course = $this->createCourse('homework_processor_course_no_cal');
        $teacher = $this->createUser('homework_processor_teacher_no_cal');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => [
                    'cid' => $course->getId(),
                ],
                'json' => [
                    'title' => 'Verslag les 6',
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2026-08-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => false,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                        ],
                    ],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $assignmentId = $response->toArray()['iid'];

        $em->clear();

        /** @var CHomeworkAssignment $assignment */
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignmentId);
        $this->assertNotNull($assignment);
        $this->assertSame(0, $assignment->getEventCalendarId());
    }

    /**
     * Regression guard for the LinkFactory gap documented in
     * saveGradebookConfig(): LinkFactory::create() has no case for
     * LINK_HOMEWORK (no HomeworkLink class exists yet), so
     * GradebookUtils::add_resource_to_course_gradebook() must never actually
     * be reached for a homework assignment today - it would fatal-error
     * (null->set_user_id()) if it were. This proves a teacher checking "add
     * to gradebook" with a real category id does not crash the request.
     */
    public function testCreatingAssignmentWithAddToGradebookDoesNotFatalError(): void
    {
        $course = $this->createCourse('homework_processor_course_gradebook');
        $teacher = $this->createUser('homework_processor_teacher_gradebook');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => [
                    'cid' => $course->getId(),
                ],
                'json' => [
                    'title' => 'Verslag les 7',
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2026-08-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => false,
                    'addToGradebook' => true,
                    'gradebookCategoryId' => 1,
                    'weight' => 10.0,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                        ],
                    ],
                ],
            ]
        );

        // The important assertion here is simply that this doesn't 500 -
        // saveGradebookConfig()'s LinkFactory guard is what stands between a
        // "no fatal error" and "Call to a member function set_user_id() on
        // null" for every homework assignment created with addToGradebook=true
        // until a HomeworkLink class is registered.
        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);
    }

    /**
     * Regression test for the strict-type comparison bug: c_course_setting.value
     * is a `longtext` column, so api_get_course_setting() returns a string, not
     * an int. `1 !== api_get_course_setting(...)` is therefore always true
     * (1 !== '1'), making sendEmailAlertStudentsOnNewHomework() permanently
     * dead code regardless of the real setting value. The fix casts to int
     * before comparing.
     */
    public function testEmailAlertSendsToStudentsWhenCourseSettingIsStoredAsStringOne(): void
    {
        $course = $this->createCourse('homework_processor_course_email_one');
        $teacher = $this->createUser('homework_processor_teacher_email_one');
        $student = $this->createUser('homework_processor_student_email_one');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        // Seed the course setting the same way the real settings UI would -
        // c_course_setting.value is a longtext column, so this is genuinely a
        // string '1' in the DB, not a PHP int, which is exactly what exposed
        // the strict-comparison bug.
        $em->getConnection()->executeStatement(
            'INSERT INTO c_course_setting (variable, value, c_id, category, title) VALUES (?, ?, ?, ?, ?)',
            ['email_alert_students_on_new_homework', '1', $course->getId(), 'work', 'email_alert_students_on_new_homework']
        );

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => [
                    'cid' => $course->getId(),
                ],
                'json' => [
                    'title' => 'Verslag les 8',
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2026-08-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => false,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                        ],
                    ],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $messageCount = (int) $em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM message_rel_user WHERE user_id = ?',
            [$student->getId()]
        )->fetchOne();

        $this->assertGreaterThan(
            0,
            $messageCount,
            'Expected an email/message to be queued for the student when the course setting is stored as the string "1".'
        );
    }

    /**
     * Companion to the '1' case above: value '2' means "alert DRH only" in
     * the reference semantics (no DRH alert is implemented for Huiswerk), so
     * students must NOT receive an email. Guards against a future fix
     * over-correcting to a loose/truthy comparison that would misfire here.
     */
    public function testEmailAlertDoesNotSendToStudentsWhenCourseSettingIsStoredAsStringTwo(): void
    {
        $course = $this->createCourse('homework_processor_course_email_two');
        $teacher = $this->createUser('homework_processor_teacher_email_two');
        $student = $this->createUser('homework_processor_student_email_two');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $em->getConnection()->executeStatement(
            'INSERT INTO c_course_setting (variable, value, c_id, category, title) VALUES (?, ?, ?, ?, ?)',
            ['email_alert_students_on_new_homework', '2', $course->getId(), 'work', 'email_alert_students_on_new_homework']
        );

        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => [
                    'cid' => $course->getId(),
                ],
                'json' => [
                    'title' => 'Verslag les 9',
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2026-08-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => false,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                        ],
                    ],
                ],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseStatusCodeSame(201);

        $messageCount = (int) $em->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM message_rel_user WHERE user_id = ?',
            [$student->getId()]
        )->fetchOne();

        $this->assertSame(
            0,
            $messageCount,
            'Course setting value "2" means DRH-only alert (not implemented for Huiswerk) - students must not be emailed.'
        );
    }
}

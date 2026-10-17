<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\State;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

final class HomeworkAssignmentProgressStateProviderTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testProgressCountsOnTimeAndLateSubmissionsPerStudent(): void
    {
        $course = $this->createCourse('homework_progress_course');
        $teacher = $this->createUser('homework_progress_teacher');
        $studentA = $this->createUser('homework_progress_student_a');
        $studentB = $this->createUser('homework_progress_student_b');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($studentA, 0, null, CourseRelUser::STUDENT);
        $course->addSubscriptionForUser($studentB, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Voortgangstest', 3);

        // createMultiReportAssignmentFixture() goes through an HTTP client
        // request (self::createClientWithCredentials()), which may reboot
        // the kernel/container - $em captured at the top of this test can
        // then be a DIFFERENT EntityManager instance than the one behind
        // $this->getEntityManager() now, making entities fetched via the
        // fixture (including $assignment itself) look "foreign"/unpersisted
        // to the stale $em, even though both point at the same database
        // (confirmed live 2026-09-11: ORMInvalidArgumentException naming
        // both the course AND the assignment as unpersisted "new" entities).
        // Re-resolve $em and every entity used below through it.
        $em = $this->getEntityManager();
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignment->getIid());
        $studentA = $em->find(\Chamilo\CoreBundle\Entity\User::class, $studentA->getId());
        $studentB = $em->find(\Chamilo\CoreBundle\Entity\User::class, $studentB->getId());

        // Student A: 2 on-time, 1 late.
        $this->persistSubmission($em, $assignment, $studentA, 1, CHomeworkSubmission::STATUS_SUBMITTED);
        $this->persistSubmission($em, $assignment, $studentA, 2, CHomeworkSubmission::STATUS_SUBMITTED);
        $this->persistSubmission($em, $assignment, $studentA, 3, CHomeworkSubmission::STATUS_LATE);

        // Student B: 1 on-time only, plus an unsubmitted draft that must NOT count.
        $this->persistSubmission($em, $assignment, $studentB, 1, CHomeworkSubmission::STATUS_SUBMITTED);
        $this->persistSubmission($em, $assignment, $studentB, 2, CHomeworkSubmission::STATUS_DRAFT);

        $teacherToken = $this->getUserTokenFromUser($teacher);
        $response = $this->createClientWithCredentials($teacherToken)->request(
            'GET',
            '/api/homework_assignments_progress',
            ['query' => ['cid' => $course->getId(), 'assignmentId' => $assignment->getIid()]]
        );

        $this->assertResponseStatusCodeSame(200);
        // Confirmed live against the running dev app (2026-09-11, since the
        // local `test` DB kernel is unusable here - see Task 5's note):
        // this paginationEnabled:false GetCollection serializes as Hydra,
        // keyed "hydra:member".
        $rows = $response->toArray()['hydra:member'];

        $byUserId = [];
        foreach ($rows as $row) {
            $byUserId[$row['userId']] = $row;
        }

        $this->assertSame(2, $byUserId[$studentA->getId()]['onTimeCount']);
        $this->assertSame(3, $byUserId[$studentA->getId()]['totalCount']);
        $this->assertSame(3, $byUserId[$studentA->getId()]['requiredCount']);

        $this->assertSame(1, $byUserId[$studentB->getId()]['onTimeCount']);
        $this->assertSame(1, $byUserId[$studentB->getId()]['totalCount'], 'The draft must not be counted.');
    }

    public function testStudentCannotReadTheProgressEndpoint(): void
    {
        $course = $this->createCourse('homework_progress_course_denied');
        $teacher = $this->createUser('homework_progress_teacher_denied');
        $student = $this->createUser('homework_progress_student_denied');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Voortgang - geen toegang', 3);

        $studentToken = $this->getUserTokenFromUser($student);
        $this->createClientWithCredentials($studentToken)->request(
            'GET',
            '/api/homework_assignments_progress',
            ['query' => ['cid' => $course->getId(), 'assignmentId' => $assignment->getIid()]]
        );

        $this->assertResponseStatusCodeSame(403);
    }

    private function persistSubmission(
        \Doctrine\ORM\EntityManagerInterface $em,
        CHomeworkAssignment $assignment,
        \Chamilo\CoreBundle\Entity\User $student,
        int $sequenceNumber,
        int $status
    ): void {
        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setSequenceNumber($sequenceNumber)
            ->setStatus($status)
            ->setCreator($student)
        ;
        if (\in_array($status, [CHomeworkSubmission::STATUS_SUBMITTED, CHomeworkSubmission::STATUS_LATE], true)) {
            $submission->setSubmittedAt(new DateTime());
        }
        $submission->setParent($assignment->getFirstResourceLink()->getCourse());
        $submission->addUserLink($student, $assignment->getFirstResourceLink()->getCourse());
        $em->persist($submission);
        $em->flush();
    }

    private function createMultiReportAssignmentFixture(\Chamilo\CoreBundle\Entity\Course $course, string $title, int $requiredCount): CHomeworkAssignment
    {
        $em = $this->getEntityManager();
        $resourceNodeId = $course->getResourceNode()->getId();
        $token = $this->getUserTokenFromUser($course->getCreator());

        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_assignments',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'title' => $title,
                    'submissionType' => CHomeworkAssignment::TYPE_FILE,
                    'deadline' => '2030-01-01T23:59:00+00:00',
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_SCORE,
                    'addToCalendar' => false,
                    'allowMultipleSubmissions' => true,
                    'requiredSubmissionCount' => $requiredCount,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(201);
        $em->clear();

        return $em->getRepository(CHomeworkAssignment::class)->find($this->extractIidFromResponseData($response->toArray()));
    }

    /**
     * CHomeworkAssignment never exposes `iid` as a serialized property (no
     * Groups on that field - it's the identifier, exposed only via the
     * JSON-LD "@id" IRI). Confirmed live (2026-09-11):
     * `$response->toArray()['iid']` always throws "Undefined array key" -
     * extract the numeric id from "@id" instead.
     */
    private function extractIidFromResponseData(array $data): int
    {
        return (int) \substr((string) $data['@id'], \strrpos((string) $data['@id'], '/') + 1);
    }
}

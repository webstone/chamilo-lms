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

final class CHomeworkSubmissionMultipleSubmissionsTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testSecondReportGetsSequenceNumberTwoOnceTheFirstIsSubmitted(): void
    {
        $course = $this->createCourse('homework_multi_course_seq');
        $teacher = $this->createUser('homework_multi_teacher_seq');
        $student = $this->createUser('homework_multi_student_seq');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Meervoudig verslag - volgnummer', 3);
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        $first = $this->createSubmissionFixture($studentToken, $assignment, $course, $resourceNodeId, $student->getId(), 'Cliënt A');
        $this->assertSame(1, $first['sequenceNumber']);

        // Submit the first report - required before a second one may start.
        $this->createClientWithCredentials($studentToken)->request(
            'PUT',
            '/api/c_homework_submissions/'.$first['iid'],
            ['query' => ['cid' => $course->getId()], 'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED]]
        );
        $this->assertResponseStatusCodeSame(200);

        $second = $this->createSubmissionFixture($studentToken, $assignment, $course, $resourceNodeId, $student->getId(), 'Cliënt B');
        $this->assertSame(2, $second['sequenceNumber']);
    }

    public function testStartingASecondReportWhileTheFirstIsStillADraftIsRejected(): void
    {
        $course = $this->createCourse('homework_multi_course_draft_lock');
        $teacher = $this->createUser('homework_multi_teacher_draft_lock');
        $student = $this->createUser('homework_multi_student_draft_lock');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Meervoudig verslag - draft lock', 3);
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        // First report left as a draft (never submitted).
        $this->createSubmissionFixture($studentToken, $assignment, $course, $resourceNodeId, $student->getId(), 'Cliënt A');

        $response = $this->createClientWithCredentials($studentToken)->request(
            'POST',
            '/api/c_homework_submissions',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'label' => 'Cliënt B',
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function testSecondSubmissionOnAPlainAssignmentStillHitsTheUniqueConstraint(): void
    {
        // Regression guard: an assignment that does NOT allow multiple
        // submissions must still behave exactly as before Task 1-6 - a
        // second POST for the same (assignment, user) is rejected.
        // Verified live via real HTTP against the dev app (2026-09-11,
        // since the local `test` DB kernel is unusable here - see Task 5's
        // note): the DB unique-constraint violation surfaces as a plain
        // 409, not a 422 - Doctrine DBAL's UniqueConstraintViolationException
        // is mapped to 409 by API Platform's default exception handling.
        $course = $this->createCourse('homework_multi_course_single_regression');
        $teacher = $this->createUser('homework_multi_teacher_single_regression');
        $student = $this->createUser('homework_multi_student_single_regression');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Gewone opdracht - regressie');
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        $this->createSubmissionFixture($studentToken, $assignment, $course, $resourceNodeId, $student->getId(), null);

        $response = $this->createClientWithCredentials($studentToken)->request(
            'POST',
            '/api/c_homework_submissions',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(409);
    }

    /**
     * @return array{iid: int, sequenceNumber: int}
     */
    private function createSubmissionFixture(
        string $studentToken,
        CHomeworkAssignment $assignment,
        \Chamilo\CoreBundle\Entity\Course $course,
        int $resourceNodeId,
        int $studentId,
        ?string $label
    ): array {
        $response = $this->createClientWithCredentials($studentToken)->request(
            'POST',
            '/api/c_homework_submissions',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    ...(null !== $label ? ['label' => $label] : []),
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $studentId],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $response->toArray();

        return ['iid' => $this->extractIidFromResponseData($data), 'sequenceNumber' => $data['sequenceNumber']];
    }

    /**
     * CHomeworkAssignment/CHomeworkSubmission/CHomeworkAssignmentEvaluation
     * never expose `iid` as a serialized property (no Groups on that field -
     * it's the identifier, exposed only via the JSON-LD "@id" IRI). Confirmed
     * live (2026-09-11): `$response->toArray()['iid']` always throws
     * "Undefined array key" - extract the numeric id from "@id" instead.
     */
    private function extractIidFromResponseData(array $data): int
    {
        return (int) \substr((string) $data['@id'], \strrpos((string) $data['@id'], '/') + 1);
    }

    private function createAssignmentFixture(\Chamilo\CoreBundle\Entity\Course $course, string $title): CHomeworkAssignment
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
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_STATUS_ONLY,
                    'addToCalendar' => false,
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
}

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

/**
 * Functional coverage for CHomeworkAssignment::$opensOn enforcement, added
 * 2026-09-11 to CHomeworkSubmissionStatusResolver::resolveSubmitAttempt().
 */
final class CHomeworkSubmissionOpensOnTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testSubmittingBeforeOpensOnIsRejected(): void
    {
        $course = $this->createCourse('homework_opens_course_too_early');
        $teacher = $this->createUser('homework_opens_teacher_too_early');
        $student = $this->createUser('homework_opens_student_too_early');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        // opensOn is safely in the future relative to "now" (2026-09-11 in
        // this codebase's test fixtures) - deadline further still.
        $assignment = $this->createAssignmentFixture($course, 'Nog niet open', '2030-06-01T00:00:00+00:00', '2030-12-31T23:59:00+00:00');
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        // Creating a draft is still allowed before opensOn - only the
        // submit transition is gated.
        $created = $this->createSubmissionDraft($studentToken, $assignment, $course, $resourceNodeId, $student->getId());
        $this->assertSame(CHomeworkSubmission::STATUS_DRAFT, $created['status']);

        $this->createClientWithCredentials($studentToken)->request(
            'PUT',
            '/api/c_homework_submissions/'.$created['iid'],
            ['query' => ['cid' => $course->getId()], 'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED]]
        );
        $this->assertResponseStatusCodeSame(422);

        $em->clear();
        /** @var CHomeworkSubmission $submission */
        $submission = $em->getRepository(CHomeworkSubmission::class)->find($created['iid']);
        $this->assertSame(
            CHomeworkSubmission::STATUS_DRAFT,
            $submission->getStatus(),
            'A rejected early submit attempt must leave the submission as DRAFT.'
        );
    }

    public function testSubmittingAfterOpensOnHasPassedSucceeds(): void
    {
        $course = $this->createCourse('homework_opens_course_already_open');
        $teacher = $this->createUser('homework_opens_teacher_already_open');
        $student = $this->createUser('homework_opens_student_already_open');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        // opensOn safely in the past, deadline safely in the future.
        $assignment = $this->createAssignmentFixture($course, 'Al open', '2020-01-01T00:00:00+00:00', '2030-12-31T23:59:00+00:00');
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        $created = $this->createSubmissionDraft($studentToken, $assignment, $course, $resourceNodeId, $student->getId());

        $this->createClientWithCredentials($studentToken)->request(
            'PUT',
            '/api/c_homework_submissions/'.$created['iid'],
            ['query' => ['cid' => $course->getId()], 'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED]]
        );
        $this->assertResponseStatusCodeSame(200);
    }

    public function testAssignmentWithNoOpensOnSetIsUnaffected(): void
    {
        // Regression guard: an assignment with opensOn left empty (the
        // overwhelming majority of existing/older assignments) must submit
        // exactly as it did before this feature existed.
        $course = $this->createCourse('homework_opens_course_null');
        $teacher = $this->createUser('homework_opens_teacher_null');
        $student = $this->createUser('homework_opens_student_null');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Geen opensOn', null, '2030-12-31T23:59:00+00:00');
        $studentToken = $this->getUserTokenFromUser($student);
        $resourceNodeId = $course->getResourceNode()->getId();

        $created = $this->createSubmissionDraft($studentToken, $assignment, $course, $resourceNodeId, $student->getId());

        $this->createClientWithCredentials($studentToken)->request(
            'PUT',
            '/api/c_homework_submissions/'.$created['iid'],
            ['query' => ['cid' => $course->getId()], 'json' => ['status' => CHomeworkSubmission::STATUS_SUBMITTED]]
        );
        $this->assertResponseStatusCodeSame(200);
    }

    /**
     * @return array{iid: int, status: int}
     */
    private function createSubmissionDraft(
        string $studentToken,
        CHomeworkAssignment $assignment,
        \Chamilo\CoreBundle\Entity\Course $course,
        int $resourceNodeId,
        int $studentId
    ): array {
        $response = $this->createClientWithCredentials($studentToken)->request(
            'POST',
            '/api/c_homework_submissions',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $studentId],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(201);
        $data = $response->toArray();

        return ['iid' => $this->extractIidFromResponseData($data), 'status' => $data['status']];
    }

    private function createAssignmentFixture(
        \Chamilo\CoreBundle\Entity\Course $course,
        string $title,
        ?string $opensOn,
        string $deadline
    ): CHomeworkAssignment {
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
                    ...(null !== $opensOn ? ['opensOn' => $opensOn] : []),
                    'deadline' => $deadline,
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

    /**
     * CHomeworkAssignment/CHomeworkSubmission never expose `iid` as a
     * serialized property (no Groups on that field - it's the identifier,
     * exposed only via the JSON-LD "@id" IRI).
     */
    private function extractIidFromResponseData(array $data): int
    {
        return (int) \substr((string) $data['@id'], \strrpos((string) $data['@id'], '/') + 1);
    }
}

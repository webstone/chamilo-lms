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

/**
 * Functional (HTTP, full API-Platform pipeline) coverage for
 * CHomeworkAssignmentDeleteProcessor, modeled on
 * CHomeworkAssignmentPostStateProcessorTest's own pattern for the same
 * known reason: the processor's collaborators (the default remove
 * processor, CHomeworkSubmissionRepository) aren't independently mockable
 * (the repository is declared final, matching every other Homework
 * repository - not special-cased here just for testability), so this
 * exercises the real DELETE operation end to end instead.
 *
 * Subject to the same documented infra gap as
 * CHomeworkAssignmentPostStateProcessorTest (ChamiloTestTrait::createCourse()
 * fails with "Access denied ... chamilo_test" in this dev sandbox) - the
 * guard's core logic (hasSubmittedSubmissions()) was additionally verified
 * live against real data via a temporary console command during
 * development (see the PR description / commit history for
 * CHomeworkAssignmentDeleteProcessor).
 */
final class CHomeworkAssignmentDeleteProcessorTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testDeletingAssignmentWithNoSubmissionsSucceeds(): void
    {
        $course = $this->createCourse('homework_delete_course_empty');
        $teacher = $this->createUser('homework_delete_teacher_empty');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Opdracht zonder inzendingen');
        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'DELETE',
            '/api/c_homework_assignments/'.$assignment->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(204);

        // Re-resolve $em (see the other two tests' comments for why) rather
        // than just clearing the possibly-stale instance captured above.
        $em = $this->getEntityManager();
        $this->assertNull($em->getRepository(CHomeworkAssignment::class)->find($assignment->getIid()));
    }

    public function testDeletingAssignmentWithADraftOnlySubmissionSucceeds(): void
    {
        $course = $this->createCourse('homework_delete_course_draft');
        $teacher = $this->createUser('homework_delete_teacher_draft');
        $student = $this->createUser('homework_delete_student_draft');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Opdracht met enkel een concept');

        // createAssignmentFixture() goes through an HTTP client request,
        // which may reboot the kernel/container - $em/$course/$student
        // captured above can then be tied to a DIFFERENT EntityManager
        // instance than the one behind $this->getEntityManager() now,
        // making them look "foreign"/unpersisted to it even though they
        // point at the same database (confirmed live 2026-09-11:
        // ORMInvalidArgumentException - this is the first time this test
        // ever actually ran, see this class's own docblock). Re-resolve
        // $em and every entity used below through it.
        $em = $this->getEntityManager();
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignment->getIid());
        $course = $em->getRepository(\Chamilo\CoreBundle\Entity\Course::class)->find($course->getId());
        $student = $em->find(\Chamilo\CoreBundle\Entity\User::class, $student->getId());

        // A student who merely opened the form (draft, never hit "Submit")
        // must NOT block deletion - only real SUBMITTED/LATE work should.
        $draft = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_DRAFT)
            ->setCreator($student)
        ;
        $draft->setParent($course);
        $draft->addUserLink($student, $course);
        $em->persist($draft);
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'DELETE',
            '/api/c_homework_assignments/'.$assignment->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(204);
    }

    public function testDeletingAssignmentWithASubmittedSubmissionIsRejected(): void
    {
        $course = $this->createCourse('homework_delete_course_submitted');
        $teacher = $this->createUser('homework_delete_teacher_submitted');
        $student = $this->createUser('homework_delete_student_submitted');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Opdracht met een ingediend verslag');

        // See the sibling test's identical comment above for why this
        // re-resolution is necessary.
        $em = $this->getEntityManager();
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignment->getIid());
        $course = $em->getRepository(\Chamilo\CoreBundle\Entity\Course::class)->find($course->getId());
        $student = $em->find(\Chamilo\CoreBundle\Entity\User::class, $student->getId());

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setCreator($student)
        ;
        $submission->setParent($course);
        $submission->addUserLink($student, $course);
        $em->persist($submission);
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'DELETE',
            '/api/c_homework_assignments/'.$assignment->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(409);

        $em->clear();
        $this->assertNotNull(
            $em->getRepository(CHomeworkAssignment::class)->find($assignment->getIid()),
            'The assignment must survive a rejected delete attempt.'
        );
    }

    public function testDeletingAssignmentCleansUpResourceNodeLinks(): void
    {
        $course = $this->createCourse('homework_delete_course_cleanup');
        $teacher = $this->createUser('homework_delete_teacher_cleanup');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createAssignmentFixture($course, 'Opdracht cleanup-test');
        $resourceNodeId = $assignment->getResourceNode()->getId();

        $token = $this->getUserTokenFromUser($teacher);

        $this->createClientWithCredentials($token)->request(
            'DELETE',
            '/api/c_homework_assignments/'.$assignment->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseStatusCodeSame(204);

        // Deleting the assignment must not leave its resource_link row(s)
        // behind - same structural gap already known for course deletion
        // (see PR #8675).
        $em = $this->getEntityManager();
        $remainingLinks = $em->getRepository(ResourceLink::class)->findBy(['resourceNode' => $resourceNodeId]);
        $this->assertCount(0, $remainingLinks, 'Deleting the assignment must also remove its resource_link rows.');
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

        $this->assertResponseStatusCodeSame(201);
        // CHomeworkAssignment never exposes `iid` as a serialized property
        // (no Groups on that field - it's the identifier, exposed only via
        // the JSON-LD "@id" IRI). Fixed 2026-09-11: `->toArray()['iid']`
        // always threw "Undefined array key" - this is the first time this
        // test class actually ran to completion (previously blocked by the
        // "test.service_container"/test-DB environment gap documented in
        // this class's own docblock above), which is how this latent bug
        // went undetected until now.
        $data = $response->toArray();
        $assignmentId = (int) \substr((string) $data['@id'], \strrpos((string) $data['@id'], '/') + 1);

        $em->clear();

        /** @var CHomeworkAssignment $assignment */
        $assignment = $em->getRepository(CHomeworkAssignment::class)->find($assignmentId);
        $this->assertNotNull($assignment);

        return $assignment;
    }
}

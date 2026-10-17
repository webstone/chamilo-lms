<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\State;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkAssignmentEvaluation;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

final class CHomeworkAssignmentEvaluationProcessorTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testTeacherCanCreateAndUpdateAFinalEvaluation(): void
    {
        $course = $this->createCourse('homework_eval_course');
        $teacher = $this->createUser('homework_eval_teacher');
        $student = $this->createUser('homework_eval_student');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Meervoudig verslag met eindbeoordeling', 5);
        $teacherToken = $this->getUserTokenFromUser($teacher);
        $resourceNodeId = $course->getResourceNode()->getId();

        $response = $this->createClientWithCredentials($teacherToken)->request(
            'POST',
            '/api/c_homework_assignment_evaluations',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'user' => '/api/users/'.$student->getId(),
                    'score' => 15.0,
                    'feedback' => 'Goed werk over het geheel.',
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        [
                            'cid' => $course->getId(),
                            'visibility' => ResourceLink::VISIBILITY_PUBLISHED,
                            'uid' => $student->getId(),
                        ],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(201);
        $created = $response->toArray();
        $evaluationIid = $this->extractIidFromResponseData($created);
        // A teacher may set score/feedback directly at creation time (unlike
        // CHomeworkSubmission, always created score-less by the student) -
        // evaluatedBy/evaluatedAt must be stamped immediately, not only on a
        // later PUT. Confirmed live via HTTP against the running dev app
        // (2026-09-11) that this was NOT happening before this assertion was
        // added - see CHomeworkAssignmentEvaluationPostStateProcessor.
        $this->assertNotNull($created['evaluatedBy'] ?? null, 'evaluatedBy must be stamped when a score is set at creation time.');
        $this->assertNotNull($created['evaluatedAt'] ?? null, 'evaluatedAt must be stamped when a score is set at creation time.');

        // A second evaluation for the same (assignment, user) must be
        // rejected - one final evaluation per student per assignment.
        $this->createClientWithCredentials($teacherToken)->request(
            'POST',
            '/api/c_homework_assignment_evaluations',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'user' => '/api/users/'.$student->getId(),
                    'score' => 10.0,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()],
                    ],
                ],
            ]
        );
        $this->assertResponseStatusCodeSame(409);

        // The teacher revises the score via PUT.
        $this->createClientWithCredentials($teacherToken)->request(
            'PUT',
            '/api/c_homework_assignment_evaluations/'.$evaluationIid,
            ['query' => ['cid' => $course->getId()], 'json' => ['score' => 17.0, 'feedback' => 'Goed werk over het geheel.']]
        );
        $this->assertResponseStatusCodeSame(200);

        $em->clear();
        /** @var CHomeworkAssignmentEvaluation $evaluation */
        $evaluation = $em->getRepository(CHomeworkAssignmentEvaluation::class)->find($evaluationIid);
        $this->assertSame(17.0, $evaluation->getScore());
        $this->assertSame($teacher->getId(), $evaluation->getEvaluatedBy()->getId());
        $this->assertNotNull($evaluation->getEvaluatedAt());
    }

    public function testCreatingAnEvaluationForASingleSubmissionAssignmentIsRejected(): void
    {
        $course = $this->createCourse('homework_eval_course_single');
        $teacher = $this->createUser('homework_eval_teacher_single');
        $student = $this->createUser('homework_eval_student_single');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        // A plain (non-multi-report) assignment - see the shared fixture
        // helper further down this file.
        $assignment = $this->createAssignmentFixture($course, 'Gewone opdracht');
        $teacherToken = $this->getUserTokenFromUser($teacher);
        $resourceNodeId = $course->getResourceNode()->getId();

        $this->createClientWithCredentials($teacherToken)->request(
            'POST',
            '/api/c_homework_assignment_evaluations',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'user' => '/api/users/'.$student->getId(),
                    'score' => 10.0,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()],
                    ],
                ],
            ]
        );

        $this->assertResponseStatusCodeSame(422);
    }

    public function testStudentCannotSelfGradeTheirOwnFinalEvaluation(): void
    {
        $course = $this->createCourse('homework_eval_course_selfgrade');
        $teacher = $this->createUser('homework_eval_teacher_selfgrade');
        $student = $this->createUser('homework_eval_student_selfgrade');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = $this->createMultiReportAssignmentFixture($course, 'Meervoudig verslag - zelfbeoordeling test', 3);
        $resourceNodeId = $course->getResourceNode()->getId();

        $teacherToken = $this->getUserTokenFromUser($teacher);
        $response = $this->createClientWithCredentials($teacherToken)->request(
            'POST',
            '/api/c_homework_assignment_evaluations',
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'assignment' => '/api/c_homework_assignments/'.$assignment->getIid(),
                    'user' => '/api/users/'.$student->getId(),
                    'score' => null,
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [
                        ['cid' => $course->getId(), 'visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()],
                    ],
                ],
            ]
        );
        $this->assertResponseStatusCodeSame(201);
        $evaluationIid = $this->extractIidFromResponseData($response->toArray());

        // Confirmed live (2026-09-11): unlike CHomeworkSubmission (always
        // created by the owning student themselves, so the student IS the
        // ResourceNode creator), this evaluation was created by the TEACHER
        // on the student's behalf - ResourceNodeVoter's ownership check is
        // creator-based (AbstractResource::$creator, not the ResourceLink's
        // `uid`), so the student is never the creator here and never gets
        // EDIT via ownership at all. The request is correctly rejected with
        // 403 at the security-expression layer, before ever reaching
        // CHomeworkAssignmentEvaluationPutStateProcessor's own self-grading
        // guard - a stronger boundary than CHomeworkSubmission's "let it
        // through, then silently revert the payload" pattern. That guard is
        // kept anyway as defense-in-depth (see that processor's docblock)
        // in case the security model ever changes to grant students
        // resourceLink-based EDIT.
        $studentToken = $this->getUserTokenFromUser($student);
        $this->createClientWithCredentials($studentToken)->request(
            'PUT',
            '/api/c_homework_assignment_evaluations/'.$evaluationIid,
            ['query' => ['cid' => $course->getId()], 'json' => ['score' => 20.0, 'feedback' => 'Ik geef mezelf een 20.']]
        );
        $this->assertResponseStatusCodeSame(403);

        $em->clear();
        /** @var CHomeworkAssignmentEvaluation $evaluation */
        $evaluation = $em->getRepository(CHomeworkAssignmentEvaluation::class)->find($evaluationIid);
        $this->assertNull($evaluation->getScore(), 'A student must never be able to self-grade their own final evaluation.');
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
                    'evaluationMode' => CHomeworkAssignment::EVALUATION_SCORE,
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

    /**
     * CHomeworkAssignment/CHomeworkAssignmentEvaluation never expose `iid` as
     * a serialized property (no Groups on that field - it's the identifier,
     * exposed only via the JSON-LD "@id" IRI). Confirmed live (2026-09-11):
     * `$response->toArray()['iid']` always throws "Undefined array key" -
     * extract the numeric id from "@id" instead.
     */
    private function extractIidFromResponseData(array $data): int
    {
        return (int) \substr((string) $data['@id'], \strrpos((string) $data['@id'], '/') + 1);
    }
}

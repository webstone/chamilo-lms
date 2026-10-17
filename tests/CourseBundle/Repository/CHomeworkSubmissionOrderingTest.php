<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

/**
 * Functional coverage for docs/superpowers/specs/2026-09-28-homework-round4-design.md,
 * section 3: CHomeworkSubmission's GetCollection is sortable server-side on
 * submittedAt, defaulting to DESC (most-recent-first) even when no order[]
 * param is sent. Modeled on CHomeworkAssignmentOrderingTest (round 3).
 */
final class CHomeworkSubmissionOrderingTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCollectionDefaultsToSubmittedAtDescending(): void
    {
        [$course, $assignment, $teacher] = $this->buildOrderingFixture('default');

        $earlierStudent = $this->createUser('homework_ordering_student_earlier');
        $laterStudent = $this->createUser('homework_ordering_student_later');

        $em = $this->getEntityManager();

        $earlier = $this->createSubmission($assignment, $course, $earlierStudent, new DateTime('2026-01-01 10:00:00'));
        $later = $this->createSubmission($assignment, $course, $laterStudent, new DateTime('2026-01-02 10:00:00'));
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions',
            ['query' => ['cid' => $course->getId(), 'assignment.iid' => $assignment->getIid()]]
        );

        $this->assertResponseIsSuccessful();

        $iris = array_map(static fn ($m) => $m['@id'] ?? null, $response->toArray()['hydra:member'] ?? []);

        $this->assertSame(
            ['/api/c_homework_submissions/'.$later->getIid(), '/api/c_homework_submissions/'.$earlier->getIid()],
            $iris,
            'Without an explicit order[] param, the collection must default to most-recently-submitted first.'
        );
    }

    public function testCollectionCanBeOrderedAscendingViaOrderParam(): void
    {
        [$course, $assignment, $teacher] = $this->buildOrderingFixture('ascending');

        $earlierStudent = $this->createUser('homework_ordering_asc_student_earlier');
        $laterStudent = $this->createUser('homework_ordering_asc_student_later');

        $em = $this->getEntityManager();

        $earlier = $this->createSubmission($assignment, $course, $earlierStudent, new DateTime('2026-01-01 10:00:00'));
        $later = $this->createSubmission($assignment, $course, $laterStudent, new DateTime('2026-01-02 10:00:00'));
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions',
            [
                'query' => [
                    'cid' => $course->getId(),
                    'assignment.iid' => $assignment->getIid(),
                    'order[submittedAt]' => 'asc',
                ],
            ]
        );

        $this->assertResponseIsSuccessful();

        $iris = array_map(static fn ($m) => $m['@id'] ?? null, $response->toArray()['hydra:member'] ?? []);

        $this->assertSame(
            ['/api/c_homework_submissions/'.$earlier->getIid(), '/api/c_homework_submissions/'.$later->getIid()],
            $iris
        );
    }

    /**
     * @return array{0: \Chamilo\CoreBundle\Entity\Course, 1: CHomeworkAssignment, 2: \Chamilo\CoreBundle\Entity\User}
     *               Element 2 is a course-level teacher, needed for
     *               cross-submission (cross-student) collection visibility -
     *               see CHomeworkSubmissionExtension.
     */
    private function buildOrderingFixture(string $suffix): array
    {
        $course = $this->createCourse('homework_ordering_course_'.$suffix);
        $teacher = $this->createUser('homework_ordering_teacher_'.$suffix);
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag sortering - '.$suffix)
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline(new DateTime('2030-01-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
            ->setCreator($teacher)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);
        $em->flush();

        return [$course, $assignment, $teacher];
    }

    private function createSubmission(
        CHomeworkAssignment $assignment,
        \Chamilo\CoreBundle\Entity\Course $course,
        \Chamilo\CoreBundle\Entity\User $student,
        DateTime $submittedAt,
    ): CHomeworkSubmission {
        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt($submittedAt)
            ->setCreator($student)
        ;
        $submission->setParent($course);
        $submission->addUserLink($student, $course);
        $this->getEntityManager()->persist($submission);

        return $submission;
    }
}

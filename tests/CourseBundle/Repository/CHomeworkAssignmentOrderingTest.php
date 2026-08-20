<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

/**
 * Functional (HTTP, full API-Platform pipeline) coverage for
 * CHomeworkAssignment's GetCollection default ordering and the opensOn
 * OrderFilter added for the "Beschikbaar vanaf" sortable column
 * (docs/superpowers/specs/2026-08-18-homework-round3-design.md, section 2).
 * Modeled on CHomeworkAssignmentPostStateProcessorTest for the
 * authenticated-request + course-teacher-subscription pattern.
 */
final class CHomeworkAssignmentOrderingTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCollectionDefaultsToDeadlineDescending(): void
    {
        $course = $this->createCourse('homework_ordering_course');
        $teacher = $this->createUser('homework_ordering_teacher');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $earlier = (new CHomeworkAssignment())
            ->setTitle('Verslag vroege deadline')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline(new DateTime('2026-08-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
        ;
        $earlier->setParent($course);
        $earlier->addCourseLink($course);

        $later = (new CHomeworkAssignment())
            ->setTitle('Verslag late deadline')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline(new DateTime('2026-09-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
        ;
        $later->setParent($course);
        $later->addCourseLink($course);

        $em->persist($earlier);
        $em->persist($later);
        $em->flush();
        $em->clear();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_assignments',
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];

        $this->assertSame('Verslag late deadline', $members[0]['title']);
        $this->assertSame('Verslag vroege deadline', $members[1]['title']);
    }

    public function testCollectionIsSortableByOpensOn(): void
    {
        $course = $this->createCourse('homework_ordering_opens_on_course');
        $teacher = $this->createUser('homework_ordering_opens_on_teacher');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $opensLate = (new CHomeworkAssignment())
            ->setTitle('Verslag opent laat')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setOpensOn(new DateTime('2026-08-15 00:00:00'))
            ->setDeadline(new DateTime('2026-09-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
        ;
        $opensLate->setParent($course);
        $opensLate->addCourseLink($course);

        $opensEarly = (new CHomeworkAssignment())
            ->setTitle('Verslag opent vroeg')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setOpensOn(new DateTime('2026-08-01 00:00:00'))
            ->setDeadline(new DateTime('2026-09-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
        ;
        $opensEarly->setParent($course);
        $opensEarly->addCourseLink($course);

        $em->persist($opensLate);
        $em->persist($opensEarly);
        $em->flush();
        $em->clear();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_assignments',
            ['query' => ['cid' => $course->getId(), 'order[opensOn]' => 'asc']]
        );

        $this->assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];

        $this->assertSame('Verslag opent vroeg', $members[0]['title']);
        $this->assertSame('Verslag opent laat', $members[1]['title']);
    }
}

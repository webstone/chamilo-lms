<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

final class CHomeworkAssignmentRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCreateAssignmentForWholeCourse(): void
    {
        $course = $this->createCourse('new');

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag les 3')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setDeadline(new DateTime('2026-08-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_STATUS_ONLY)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);

        $this->assertNull($assignment->getSessionId());

        $em = $this->getEntityManager();
        $em->persist($assignment);
        $em->flush();

        $this->assertNotNull($assignment->getIid());

        $em->clear();

        $repo = $em->getRepository(CHomeworkAssignment::class);
        $found = $repo->find($assignment->getIid());
        $this->assertSame('Verslag les 3', $found->getTitle());
        $this->assertSame(CHomeworkAssignment::TYPE_FORM, $found->getSubmissionType());
    }

    public function testAssignmentFormRelationRoundTrips(): void
    {
        $course = $this->createCourse('new');
        $teacher = $this->createUser('teacher');

        $form = (new CHomeworkForm())
            ->setTitle('Standaard lesverslag')
            ->setCreator($teacher)
            ->setParent($course)
            ->addCourseLink($course)
        ;

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag les 4')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setDeadline(new DateTime('2026-08-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_STATUS_ONLY)
            ->setForm($form)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);

        $em = $this->getEntityManager();
        $em->persist($form);
        $em->persist($assignment);
        $em->flush();

        $this->assertNotNull($assignment->getIid());
        $formIid = $form->getIid();

        $em->clear();

        $repo = $em->getRepository(CHomeworkAssignment::class);
        $found = $repo->find($assignment->getIid());

        $this->assertNotNull($found->getForm());
        $this->assertSame($formIid, $found->getForm()->getIid());

        // NOTE: templateDocument (ManyToOne to CDocument, referencedColumnName 'iid') round-trip
        // coverage is deferred. Constructing a CDocument fixture requires CDocumentRepository::create()
        // plus file-handling setup (see CDocumentRepositoryTest::testAddFileFromString) which is out
        // of scope for this quick FK-mapping regression test. The mapping was independently verified
        // via `doctrine:schema:validate --skip-sync` and a `doctrine:schema:update --dump-sql` diff
        // against the applied c_homework_assignment migration (no FK-target mismatch reported).
    }
}

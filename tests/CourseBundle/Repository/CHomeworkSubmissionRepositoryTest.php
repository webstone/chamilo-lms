<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkFormField;
use Chamilo\CourseBundle\Entity\CHomeworkFormPage;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\CourseBundle\Entity\CHomeworkSubmissionAnswer;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

class CHomeworkSubmissionRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCreateDraftSubmissionThenMarkSubmitted(): void
    {
        $course = $this->createCourse('new');
        $em = $this->getEntityManager();

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag les 4')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setDeadline(new DateTime('+1 day'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);

        $student = $this->createUser('student1');

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_DRAFT)
        ;
        $submission->setParent($course);
        $submission->addCourseLink($course);
        $em->persist($submission);
        $em->flush();

        $this->assertNotNull($submission->getIid());
        $this->assertSame(CHomeworkSubmission::STATUS_DRAFT, $submission->getStatus());

        $submission->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)->setSubmittedAt(new DateTime());
        $em->flush();
        $em->clear();

        $found = $em->getRepository(CHomeworkSubmission::class)->find($submission->getIid());
        $this->assertSame(CHomeworkSubmission::STATUS_SUBMITTED, $found->getStatus());
        $this->assertNotNull($found->getSubmittedAt());
        $this->assertSame($assignment->getIid(), $found->getAssignment()->getIid());
        $this->assertSame($student->getId(), $found->getUser()->getId());
    }

    public function testSubmissionAnswersRoundTrip(): void
    {
        $course = $this->createCourse('new');
        $em = $this->getEntityManager();

        $form = (new CHomeworkForm())->setTitle('Lesverslag');
        $form->setParent($course);
        $form->addCourseLink($course);

        $page = (new CHomeworkFormPage())->setTitle('Sectie 1')->setSortOrder(0);
        $form->addPage($page);

        $field = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_TEXTAREA)
            ->setLabel('Wat heb je geleerd?')
            ->setRequired(true)
            ->setSortOrder(0)
        ;
        $page->addField($field);
        $em->persist($form);

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag les 5')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setDeadline(new DateTime('+1 day'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
            ->setForm($form)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);

        $student = $this->createUser('student2');

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_DRAFT)
        ;
        $submission->setParent($course);
        $submission->addCourseLink($course);

        $answer = (new CHomeworkSubmissionAnswer())
            ->setField($field)
            ->setValue('Ik heb veel geleerd over Doctrine.')
        ;
        $submission->addAnswer($answer);

        $em->persist($submission);
        $em->flush();

        $submissionIid = $submission->getIid();
        $fieldIid = $field->getIid();

        $this->assertNotNull($answer->getIid());
        $this->assertCount(1, $submission->getAnswers());

        $em->clear();

        $found = $em->getRepository(CHomeworkSubmission::class)->find($submissionIid);
        $this->assertNotNull($found);

        $foundAnswers = $found->getAnswers();
        $this->assertCount(1, $foundAnswers);

        $foundAnswer = $foundAnswers->first();
        $this->assertSame('Ik heb veel geleerd over Doctrine.', $foundAnswer->getValue());
        $this->assertSame($fieldIid, $foundAnswer->getField()->getIid());
        $this->assertNull($foundAnswer->getFileDocument());
    }
}

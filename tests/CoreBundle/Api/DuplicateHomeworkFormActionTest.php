<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Api;

use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkFormField;
use Chamilo\CourseBundle\Entity\CHomeworkFormPage;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\CourseBundle\Entity\CHomeworkSubmissionAnswer;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;
use DateTime;

/**
 * Covers docs (OWC teacher-facing docs already tell teachers to "make a copy
 * first" before editing a form that might be in use - this is that feature):
 * duplicating a CHomeworkForm must never touch the original's rows, or any
 * CHomeworkSubmissionAnswer already pointing at its fields, and the copy must
 * be a fully independent entity afterwards.
 */
final class DuplicateHomeworkFormActionTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testDuplicateFormCreatesIndependentCopyWithSamePagesAndFields(): void
    {
        [$course, $teacher, $form] = $this->buildFormFixture('create_copy');

        $token = $this->getUserTokenFromUser($teacher);
        $response = $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_forms/'.$form->getIid().'/duplicate',
            ['query' => ['cid' => $course->getId()]],
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();
        $duplicateIri = (string) $data['@id'];
        $duplicateId = (int) substr($duplicateIri, strrpos($duplicateIri, '/') + 1);

        // Locale-agnostic: the exact wording ("copy"/"kopie") is a
        // translator concern, this only proves a suffixed, distinct title
        // was generated from the original.
        $this->assertNotSame('Voetreflexverslag', $data['title']);
        $this->assertStringStartsWith('Voetreflexverslag', $data['title']);
        $this->assertNotSame($form->getIid(), $duplicateId);
        $this->assertCount(1, $data['pages']);
        $this->assertCount(2, $data['pages'][0]['fields']);
        $this->assertSame('Naam', $data['pages'][0]['fields'][0]['label']);
        $this->assertSame(['Ja', 'Nee'], $data['pages'][0]['fields'][1]['options']);

        $em = $this->getEntityManager();
        $em->clear();
        $originalReloaded = $em->getRepository(CHomeworkForm::class)->find($form->getIid());

        $this->assertSame('Voetreflexverslag', $originalReloaded->getTitle());
        $this->assertCount(1, $originalReloaded->getPages());
        $this->assertCount(2, $originalReloaded->getPages()->first()->getFields());
    }

    public function testDuplicateFormDoesNotMoveOrTouchExistingSubmissionAnswers(): void
    {
        [$course, $teacher, $form, $field] = $this->buildFormFixture('with_answers');
        $student = $this->createUser('homework_form_duplicate_student_with_answers');
        $course->addUserAsStudent($student);

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag met sjabloon - with_answers')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setForm($form)
            ->setDeadline(new DateTime('2099-01-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
            ->setCreator($teacher)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);

        $em = $this->getEntityManager();
        $em->persist($assignment);
        $em->flush();

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

        $answer = (new CHomeworkSubmissionAnswer())
            ->setSubmission($submission)
            ->setField($field)
            ->setValue('Test User')
        ;
        $em->persist($answer);
        $em->flush();

        $originalFieldIid = $field->getIid();

        $token = $this->getUserTokenFromUser($teacher);
        $this->createClientWithCredentials($token)->request(
            'POST',
            '/api/c_homework_forms/'.$form->getIid().'/duplicate',
            ['query' => ['cid' => $course->getId()]],
        );

        $this->assertResponseIsSuccessful();

        $em->clear();
        $reloadedAnswer = $em->getRepository(CHomeworkSubmissionAnswer::class)->find($answer->getIid());

        $this->assertSame($originalFieldIid, $reloadedAnswer->getField()->getIid());
        $this->assertSame('Test User', $reloadedAnswer->getValue());
    }

    public function testEditingDuplicateDoesNotAffectOriginalForm(): void
    {
        [$course, $teacher, $form] = $this->buildFormFixture('edit_copy');

        $token = $this->getUserTokenFromUser($teacher);
        $client = $this->createClientWithCredentials($token);

        $duplicateResponse = $client->request(
            'POST',
            '/api/c_homework_forms/'.$form->getIid().'/duplicate',
            ['query' => ['cid' => $course->getId()]],
        );
        $duplicateIri = $duplicateResponse->toArray()['@id'];
        $duplicateId = (int) substr($duplicateIri, strrpos($duplicateIri, '/') + 1);

        // Simulates HomeworkFormBuilder.vue's destructive edit-mode PUT
        // (whole pages/fields tree replaced) - on the COPY, never the
        // original.
        $client->request(
            'PUT',
            '/api/c_homework_forms/'.$duplicateId,
            [
                'query' => ['cid' => $course->getId()],
                'json' => [
                    'title' => 'Volledig herschreven kopie',
                    'pages' => [
                        [
                            'title' => 'Nieuwe pagina',
                            'sortOrder' => 0,
                            'fields' => [
                                [
                                    'type' => CHomeworkFormField::TYPE_TEXT,
                                    'label' => 'Compleet ander veld',
                                    'required' => true,
                                    'sortOrder' => 0,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        );

        $this->assertResponseIsSuccessful();

        $em = $this->getEntityManager();
        $em->clear();
        $originalReloaded = $em->getRepository(CHomeworkForm::class)->find($form->getIid());

        $this->assertSame('Voetreflexverslag', $originalReloaded->getTitle());
        $this->assertCount(1, $originalReloaded->getPages());
        $this->assertCount(2, $originalReloaded->getPages()->first()->getFields());
    }

    /**
     * @return array{0: \Chamilo\CoreBundle\Entity\Course, 1: \Chamilo\CoreBundle\Entity\User, 2: CHomeworkForm, 3: CHomeworkFormField}
     *               Element 3 is the form's first field (for answer-fixture reuse).
     */
    private function buildFormFixture(string $suffix): array
    {
        $course = $this->createCourse('hw_form_dup_'.$suffix);
        $teacher = $this->createUser('homework_form_duplicate_teacher_'.$suffix);
        $course->addUserAsTeacher($teacher);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $page = (new CHomeworkFormPage())
            ->setTitle('Pagina 1')
            ->setSortOrder(0)
        ;

        $nameField = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_TEXT)
            ->setLabel('Naam')
            ->setRequired(true)
            ->setSortOrder(0)
        ;
        $page->addField($nameField);

        $checkboxField = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_CHECKBOX)
            ->setLabel('Akkoord?')
            ->setOptions(['Ja', 'Nee'])
            ->setSortOrder(1)
        ;
        $page->addField($checkboxField);

        $form = (new CHomeworkForm())
            ->setTitle('Voetreflexverslag')
            ->setCreator($teacher)
        ;
        $form->addPage($page);
        $form->setParent($course);
        $form->addCourseLink($course);

        $em->persist($form);
        $em->flush();

        return [$course, $teacher, $form, $nameField];
    }
}

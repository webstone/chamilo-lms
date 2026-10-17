<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CDocument;
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
 * Functional coverage for docs/superpowers/specs/2026-09-28-homework-round4-design.md,
 * section 6, phase 1: exporting a single CHomeworkSubmission.
 */
final class ExportHomeworkSubmissionActionTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testExportsTemplateAnswersAsPdf(): void
    {
        $course = $this->createCourse('homework_export_form_course');
        $teacher = $this->createUser('homework_export_form_teacher');
        $student = $this->createUser('homework_export_form_student');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);
        $course->addSubscriptionForUser($student, 0, null, CourseRelUser::STUDENT);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $form = (new CHomeworkForm())->setTitle('Sjabloon export test')->setCreator($teacher);
        $form->setParent($course);
        $form->addCourseLink($course);

        $page = (new CHomeworkFormPage())->setForm($form)->setSortOrder(0);
        $form->addPage($page);

        $field = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_TEXT)
            ->setLabel('Wat heb je geleerd?')
            ->setRequired(false)
            ->setSortOrder(0)
        ;
        $page->addField($field);

        // Regression coverage: a TYPE_CHECKBOX answer is stored as a
        // JSON-encoded array (see HomeworkCorrectAndRate.vue's
        // formatAnswerValue()) - exporting it must not crash, and the export
        // action must decode/join it rather than emit raw JSON into the PDF.
        $checkboxField = (new CHomeworkFormField())
            ->setType(CHomeworkFormField::TYPE_CHECKBOX)
            ->setLabel('Welke onderdelen heb je afgerond?')
            ->setRequired(false)
            ->setOptions(['Deel 1', 'Deel 2'])
            ->setSortOrder(1)
        ;
        $page->addField($checkboxField);

        $em->persist($form);
        $em->flush();

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag export test')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FORM)
            ->setForm($form)
            ->setDeadline(new DateTime('2030-01-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
            ->setCreator($teacher)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setCreator($student)
        ;
        $submission->setParent($course);
        $submission->addUserLink($student, $course);

        $answer = (new CHomeworkSubmissionAnswer())
            ->setField($field)
            ->setValue('Heel veel over PDF-export.')
        ;
        $submission->addAnswer($answer);

        $checkboxAnswer = (new CHomeworkSubmissionAnswer())
            ->setField($checkboxField)
            ->setValue(json_encode(['Deel 1', 'Deel 2']))
        ;
        $submission->addAnswer($checkboxAnswer);

        $em->persist($submission);
        $em->flush();

        $token = $this->getUserTokenFromUser($student);

        // A real browser's plain <a href> download click - not an API
        // client's Accept header - is what HomeworkCorrectAndRate.vue's
        // "Download as PDF" link actually sends in production.
        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid().'/export',
            [
                'query' => ['cid' => $course->getId()],
                'headers' => ['Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8'],
            ]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/pdf');
        // Confirmed via this exact assertion (live, before it existed here):
        // the header IS correctly "attachment" - a browser still rendering
        // the PDF inline instead of downloading it is a client-side link
        // behavior issue (see HomeworkCorrectAndRate.vue/HomeworkGradeSubmission.vue's
        // `download` attribute on the Download buttons), not a missing/wrong
        // server header.
        $this->assertStringStartsWith('attachment', $response->getHeaders()['content-disposition'][0] ?? '');
    }

    public function testExportsAlreadyUploadedFileAsIs(): void
    {
        $course = $this->createCourse('homework_export_file_course');
        $session = $this->createSession('homework_export_file_session');
        $student = $this->createUser('homework_export_file_student');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        $resourceNodeId = $course->getResourceNode()->getId();
        $courseId = $course->getId();
        $studentId = $student->getId();
        $studentToken = $this->getUserTokenFromUser($student);
        $file = $this->getUploadedFile();

        $uploadResponse = $this->createClientWithCredentials($studentToken)->request(
            'POST',
            '/api/documents/homework-submission-upload',
            [
                'query' => ['cid' => $course->getId(), 'sid' => $session->getId()],
                'headers' => ['Content-Type' => 'multipart/form-data'],
                'extra' => ['files' => ['uploadFile' => $file]],
                'json' => [
                    'filetype' => 'file',
                    'parentResourceNodeId' => $resourceNodeId,
                    'resourceLinkList' => [['visibility' => ResourceLink::VISIBILITY_PUBLISHED, 'uid' => $student->getId()]],
                ],
            ]
        );
        $this->assertResponseStatusCodeSame(201);
        $documentIri = $uploadResponse->toArray()['@id'];

        // The HTTP client call above may reboot the kernel/container,
        // producing a different EntityManager instance than the one that
        // created $course/$student - re-resolve both via the fresh $em
        // before persisting anything that references them, otherwise
        // Doctrine treats them as unpersisted new entities reachable through
        // the association graph ("Multiple non-persisted new entities were
        // found") - see project_test_database_setup's documented pitfall.
        $em = $this->getEntityManager();
        $course = $em->getRepository(Course::class)->find($courseId);
        $student = $em->getRepository(User::class)->find($studentId);

        $assignment = (new CHomeworkAssignment())
            ->setTitle('Verslag export test - bestand')
            ->setSubmissionType(CHomeworkAssignment::TYPE_FILE)
            ->setDeadline(new DateTime('2030-01-01 23:59:00'))
            ->setEvaluationMode(CHomeworkAssignment::EVALUATION_NONE)
            ->setCreator($student)
        ;
        $assignment->setParent($course);
        $assignment->addCourseLink($course);
        $em->persist($assignment);
        $em->flush();

        $documentIid = (int) substr($documentIri, strrpos($documentIri, '/') + 1);
        $document = $em->getRepository(CDocument::class)->find($documentIid);

        $submission = (new CHomeworkSubmission())
            ->setAssignment($assignment)
            ->setUser($student)
            ->setStatus(CHomeworkSubmission::STATUS_SUBMITTED)
            ->setSubmittedAt(new DateTime())
            ->setDocument($document)
            ->setCreator($student)
        ;
        $submission->setParent($course);
        $submission->addUserLink($student, $course);
        $em->persist($submission);
        $em->flush();

        $this->createClientWithCredentials($studentToken)->request(
            'GET',
            '/api/c_homework_submissions/'.$submission->getIid().'/export',
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'image/png');
    }
}

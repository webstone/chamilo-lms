<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Helpers\ResourceFileHelper;
use Chamilo\CoreBundle\Repository\ResourceNodeRepository;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Entity\CHomeworkFormField;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Chamilo\CourseBundle\Entity\CHomeworkSubmissionAnswer;
use PDF;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Phase 1 of docs/superpowers/specs/2026-09-28-homework-round4-design.md,
 * section 6: exports ONE CHomeworkSubmission. A template-answer submission
 * (assignment.submissionType === TYPE_FORM) is rendered to a real PDF via
 * mPDF, mirroring ExportCGlossaryAction's content_to_pdf() usage. A
 * file-upload submission is served exactly as uploaded, unconverted -
 * deliberately: converting arbitrary formats (docx, jpg, ...) to PDF would
 * need a new external dependency, out of scope for this phase (see the spec).
 */
readonly class ExportHomeworkSubmissionAction
{
    public const CONTENT_TYPE = 'application/pdf';

    // Only used as a fallback for the "serve the original upload as-is"
    // branch, when the stored ResourceFile has no mime type of its own - NOT
    // a claim that the file is actually a PDF, so a generic binary type is
    // more honest here than reusing self::CONTENT_TYPE.
    private const FALLBACK_UPLOAD_CONTENT_TYPE = 'application/octet-stream';

    public function __construct(
        private ResourceNodeRepository $resourceNodeRepository,
        private ResourceFileHelper $resourceFileHelper,
        private TranslatorInterface $translator,
    ) {}

    public function __invoke(CHomeworkSubmission $data): Response
    {
        $document = $data->getDocument();

        if (null !== $document) {
            return $this->exportUploadedFile($document);
        }

        return $this->exportTemplateAnswersAsPdf($data);
    }

    private function exportUploadedFile(CDocument $document): Response
    {
        $node = $document->getResourceNode();
        $resourceFile = $node ? $this->resourceFileHelper->resolveResourceFileByAccessUrl($node) : null;

        if (null === $resourceFile) {
            throw new NotFoundHttpException($this->translator->trans('No file was submitted for this report.'));
        }

        // ResourceNodeRepository::getFilename() returns the internal, sharded
        // STORAGE path (e.g. "/l/o/g/logo-....png" - resolveUri() on the
        // storage backend), not a display name - passing it straight into
        // Content-Disposition throws ("cannot contain the '/' and '\\'
        // characters", confirmed by live test execution). The human-readable
        // name is ResourceFile::getOriginalName() only.
        $filename = $resourceFile->getOriginalName() ?? 'verslag';
        $stream = $this->resourceNodeRepository->getResourceNodeFileStream($node, $resourceFile);

        $tmpPath = tempnam(sys_get_temp_dir(), 'homework_export_');
        file_put_contents($tmpPath, stream_get_contents($stream));

        $response = new BinaryFileResponse(new File($tmpPath));
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', $resourceFile->getMimeType() ?? self::FALLBACK_UPLOAD_CONTENT_TYPE);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    private function exportTemplateAnswersAsPdf(CHomeworkSubmission $submission): Response
    {
        $html = '<h1>'.htmlspecialchars($submission->getAssignment()->getTitle()).'</h1>';

        foreach ($submission->getAnswers() as $answer) {
            $field = $answer->getField();
            $html .= '<p><strong>'.htmlspecialchars($field->getLabel()).'</strong><br>';
            $html .= $this->formatAnswerHtml($answer, $field);
            $html .= '</p>';
        }

        $courseCode = $this->resolveCourseCode($submission);
        // Includes the submission's own iid: assignments on this branch allow
        // multiple submissions per student (allowMultipleSubmissions), and
        // content_to_pdf()'s 'F' output mode writes to a deterministic path
        // derived only from this name - without a per-submission identifier,
        // exporting two of the same student's submissions around the same
        // time would race on the same file path.
        $pdfName = \sprintf(
            'verslag_%s_%s_%d',
            $submission->getAssignment()->getTitle(),
            $submission->getUser()->getUsername(),
            $submission->getIid(),
        );

        $pdfPath = (new PDF())->content_to_pdf($html, null, $pdfName, $courseCode, 'F', false, null, false, true);

        $response = new BinaryFileResponse(new File($pdfPath));
        $response->deleteFileAfterSend(true);
        $response->headers->set('Content-Type', self::CONTENT_TYPE);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $pdfName.'.pdf');

        return $response;
    }

    /**
     * Mirrors HomeworkGradeSubmission.vue's formatAnswerValue()/isFileField()
     * client-side rendering exactly, so the exported PDF matches what the
     * teacher already sees on screen for the same answer - in particular,
     * CHomeworkFormField::TYPE_CHECKBOX stores its value as a JSON-encoded
     * array (e.g. '["Optie 1","Optie 2"]'), which must be decoded and
     * joined rather than printed as raw JSON.
     */
    private function formatAnswerHtml(CHomeworkSubmissionAnswer $answer, CHomeworkFormField $field): string
    {
        if (CHomeworkFormField::TYPE_FILE === $field->getType()) {
            $fileDocument = $answer->getFileDocument();

            return htmlspecialchars($fileDocument
                ? $this->translator->trans('Attachment: %s', ['%s' => $fileDocument->getTitle()])
                : $this->translator->trans('No attachment'));
        }

        if (CHomeworkFormField::TYPE_CHECKBOX === $field->getType()) {
            $decoded = json_decode($answer->getValue() ?? '[]', true);

            return htmlspecialchars(\is_array($decoded) ? implode(', ', $decoded) : ($answer->getValue() ?? '—'));
        }

        if (CHomeworkFormField::TYPE_DATE === $field->getType()) {
            return htmlspecialchars($this->formatDateAnswer($answer->getValue()));
        }

        return nl2br(htmlspecialchars($answer->getValue() ?? '—'));
    }

    // The date picker stores a full ISO-8601 UTC instant (e.g.
    // "2026-09-06T22:00:00.000Z" for the 7th of September, local time) -
    // printing that raw string looks like a timezone offset to a teacher
    // reading the PDF. Converting to the app's own timezone before
    // formatting recovers the calendar date the student actually picked.
    private function formatDateAnswer(?string $value): string
    {
        if (!$value) {
            return '—';
        }

        try {
            $date = (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

            return $date->format('d/m/Y');
        } catch (\Exception) {
            return $value;
        }
    }

    /**
     * Walks the SUBMISSION's own resource node (not the assignment's, unlike
     * HomeworkVoter::resolveCourse() for the same entity type) - submissions
     * always carry their own course-linked ResourceLink (see the
     * "resource_link cid+user gotcha" precedent elsewhere in this module),
     * so this is simpler than delegating to the assignment.
     */
    private function resolveCourseCode(CHomeworkSubmission $submission): ?string
    {
        $links = $submission->getResourceNode()?->getResourceLinks();
        foreach ($links ?? [] as $link) {
            $course = $link->getCourse();
            if ($course instanceof Course) {
                return $course->getCode();
            }
        }

        return null;
    }
}

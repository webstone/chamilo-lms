<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Helpers\CourseHelper;
use Chamilo\CoreBundle\Repository\Node\CourseRepository;
use Chamilo\CourseBundle\Entity\CDocument;
use Chamilo\CourseBundle\Repository\CDocumentRepository;
use Doctrine\ORM\EntityManager;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Dedicated homework-submission file upload endpoint.
 *
 * CDocument's general-purpose `/documents` Post operation
 * (CreateDocumentFileAction) is gated by
 * "is_granted('ROLE_CURRENT_COURSE_TEACHER') or
 * is_granted('ROLE_CURRENT_COURSE_SESSION_TEACHER')" - teachers only, by
 * design, for the shared course Documents library. HomeworkSubmit.vue
 * (student-facing) needs an authenticated STUDENT to upload their own
 * submission file/answer attachment, which the teacher-only endpoint
 * structurally rejects (confirmed live: a plain enrolled student gets a 403
 * "Access Denied" from CDocument's Post operation). This mirrors the
 * existing, already-in-production precedent for the analogous problem in the
 * Werk/Assignments module - CStudentPublication's own
 * `/c_student_publications/upload` operation
 * (CreateStudentPublicationFileAction) - which exists specifically because
 * the generic teacher-only document endpoint is not usable by students
 * either.
 *
 * Unlike that precedent (which forces the resource owner from the
 * authenticated user directly via `setUser()`), the file created here is a
 * plain CDocument referenced by CHomeworkSubmission::$document, so ownership
 * has to be expressed the same way HomeworkSubmit.vue already relies on for
 * privacy: a user-scoped ResourceLink (see
 * tests/CoreBundle/Api/HomeworkPermissionMatrixTest.php's class docblock).
 * buildResourceLinkListFromContext() only derives cid/sid/gid from the
 * session-resolved course context (IDOR-safe against a forged cid/sid), but
 * it does not know about `uid` - so this action adds `uid` itself, always
 * forced to the AUTHENTICATED user's id, never trusting whatever the request
 * body sent. Without that, a malicious student could set `uid` to another
 * student's id and create a document that appears to belong to someone else.
 */
final class CreateHomeworkSubmissionFileAction extends BaseResourceFileAction
{
    public function __invoke(
        Request $request,
        CDocumentRepository $repo,
        EntityManager $em,
        KernelInterface $kernel,
        TranslatorInterface $translator,
        CourseRepository $courseRepository,
        CourseHelper $courseHelper,
        Security $security,
    ): CDocument {
        $user = $security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('You must be authenticated to submit homework.');
        }

        $resourceLinkList = $this->buildResourceLinkListFromContext(
            $request,
            $this->extractResourceLinkListFromRequest($request),
            ResourceLink::VISIBILITY_PUBLISHED
        );

        foreach ($resourceLinkList as &$link) {
            $link['uid'] = $user->getId();
        }
        unset($link);

        $document = new CDocument();

        $result = $this->handleCreateFileRequest(
            $document,
            $repo,
            $request,
            $em,
            (string) $request->get('fileExistsOption', 'rename'),
            $translator,
            $courseRepository,
            $courseHelper,
            $resourceLinkList
        );

        $document->setTitle($result['title'] ?? $document->getResourceName());
        $document->setFiletype($result['filetype'] ?? 'file');
        $document->setComment($result['comment'] ?? '');

        $em->persist($document);
        $em->flush();

        return $document;
    }
}

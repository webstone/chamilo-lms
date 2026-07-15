<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Server-side authority for CHomeworkSubmission::$status. Owns two separate
 * client-trust problems around the same field:
 *
 * 1. The DRAFT -> SUBMITTED/LATE transition itself. $status has a
 *    `homework_submission:write` Groups entry (needed so the client can
 *    signal "I want to submit now"), which means a raw client value cannot be
 *    trusted for anything beyond that signal: the Huiswerk spec's hard
 *    requirement ("Na de deadline: indienen geblokkeerd tenzij
 *    allowLateSubmission = true") was previously enforced ONLY in
 *    HomeworkSubmit.vue (hiding the submission UI) - CHomeworkSubmission's Put
 *    security (`is_granted('EDIT', object.resourceNode) or is_granted('EDIT',
 *    object)`) grants EDIT via ResourceNodeVoter's plain ownership check, with
 *    no course-role or deadline check anywhere in that chain. A student could
 *    send `PUT {"status": 2}` (or include it directly in the create POST)
 *    against an assignment whose deadline had passed with
 *    allowLateSubmission=false and get a 200, fully bypassing the block -
 *    confirmed via live reproduction before this fix existed. Only re-derived
 *    on a genuine DRAFT -> non-DRAFT transition (see $previousStatus): a
 *    teacher grading an already-submitted row after the deadline (the normal
 *    case) must not be blocked by this same check, since nothing about that
 *    PUT is a submit attempt.
 *
 * 2. Rewriting an ALREADY-submitted status. Once a submission is
 *    SUBMITTED/LATE, the owning student still holds EDIT on it via
 *    ResourceNodeVoter's ownership check (same mechanism the deadline bypass
 *    above exploited) - without this second guard, a student could freely PUT
 *    any status value onto their own already-submitted row, e.g. setting it
 *    back to DRAFT to hide a graded/late submission from a status-filtered
 *    teacher list. This does NOT reopen the deadline bypass (a renewed submit
 *    attempt from that DRAFT state goes back through check #1 and is blocked
 *    again the same way), but the student's own status field must simply stop
 *    being client-writable once it has left DRAFT. Teachers/admins (checked
 *    the same way CHomeworkSubmissionExtension scopes collection visibility -
 *    HomeworkCourseTeacherChecker's course-wide, cross-session check, plus
 *    isSuperAdmin()) are exempt: they legitimately need to revise status for
 *    corrections (e.g. reopening a submission, overriding LATE back to
 *    SUBMITTED).
 *
 * The 422 thrown by #1 (CHomeworkSubmissionDeadlinePassedException) is a
 * plain {"error": "..."} message, not the `violations` array shape
 * ApiPlatform's Assert-based validation uses elsewhere in this codebase -
 * deliberate: this is a business-rule rejection computed from real time vs.
 * the assignment's deadline, not a per-property constraint on the submitted
 * payload, so there's no single property path to attach a violation to.
 */
final class CHomeworkSubmissionStatusResolver
{
    public function __construct(
        private readonly Security $security,
        private readonly HomeworkCourseTeacherChecker $teacherChecker,
    ) {}

    public function resolve(CHomeworkSubmission $submission, int $requestedStatus, int $previousStatus): int
    {
        $isSubmitAttempt = CHomeworkSubmission::STATUS_DRAFT === $previousStatus
            && CHomeworkSubmission::STATUS_DRAFT !== $requestedStatus;

        if ($isSubmitAttempt) {
            return $this->resolveSubmitAttempt($submission);
        }

        // Not a fresh submit attempt. If the submission is already
        // SUBMITTED/LATE, only a teacher/admin may change $status any further
        // - the owning student's request is silently ignored (the previous
        // status wins), never rejected outright: this is a lock on one field,
        // not a rejection of the whole PUT (e.g. the student may still be
        // legitimately updating other fields, or simply re-sending the same
        // status unchanged).
        if (CHomeworkSubmission::STATUS_DRAFT !== $previousStatus && !$this->isPrivileged($submission)) {
            return $previousStatus;
        }

        return $requestedStatus;
    }

    private function resolveSubmitAttempt(CHomeworkSubmission $submission): int
    {
        $assignment = $submission->getAssignment();
        $isPastDeadline = new DateTime() > $assignment->getDeadline();

        if ($isPastDeadline && !$assignment->isAllowLateSubmission()) {
            throw new CHomeworkSubmissionDeadlinePassedException();
        }

        // Ignores whatever status value the client actually sent beyond "not
        // DRAFT" - DRAFT/SUBMITTED/LATE is entirely server-derived from real
        // time vs. deadline, not client input.
        return $isPastDeadline ? CHomeworkSubmission::STATUS_LATE : CHomeworkSubmission::STATUS_SUBMITTED;
    }

    private function isPrivileged(CHomeworkSubmission $submission): bool
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        $course = $this->resolveCourse($submission);
        if (!$course instanceof Course) {
            // Fail closed: with no resolvable course, there's no way to
            // confirm a teacher relationship, so treat the requester as an
            // ordinary (locked-out) owner rather than silently allowing the change.
            return false;
        }

        return $this->teacherChecker->isTeacherOfCourse($user, $course);
    }

    /**
     * Used by CHomeworkSubmissionPutStateProcessor to stamp $evaluatedBy /
     * $evaluatedAt on a genuine grading PUT (score/feedback change). Reuses
     * this class's existing course-wide, cross-session teacher/admin check
     * (isPrivileged()) instead of duplicating it in the processor - returns
     * the acting User only when they are actually allowed to grade, null
     * otherwise (including when there is no authenticated user at all).
     */
    public function resolvePrivilegedGrader(CHomeworkSubmission $submission): ?User
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return null;
        }

        return $this->isPrivileged($submission) ? $user : null;
    }

    /**
     * Mirrors HomeworkVoter::resolveCourse() for the CHomeworkSubmission case
     * (course lives on the parent assignment's ResourceNode links, not the
     * submission's own user-scoped link).
     */
    private function resolveCourse(CHomeworkSubmission $submission): ?Course
    {
        $links = $submission->getAssignment()->getResourceNode()?->getResourceLinks();
        foreach ($links ?? [] as $link) {
            $course = $link->getCourse();
            if (null !== $course) {
                return $course;
            }
        }

        return null;
    }
}

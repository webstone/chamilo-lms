<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use DateTime;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Owns two things a client PUT must not be trusted with directly:
 *
 * 1. $status: only a genuine DRAFT -> non-DRAFT transition is subject to
 *    deadline enforcement (CHomeworkSubmissionStatusResolver decides
 *    SUBMITTED vs LATE vs "reject" from real time vs.
 *    assignment deadline/allowLateSubmission - never from the client's raw
 *    status value beyond "not DRAFT"). $context['previous_data'] is API
 *    Platform's already-fetched pre-write state, which is what makes it
 *    possible to tell a real submit attempt apart from an unrelated PUT
 *    (e.g. a teacher's grading-only PUT on an already-submitted row,
 *    typically sent well after the deadline - that must NOT be blocked by
 *    this same check).
 *
 * 2. $submittedAt: read-only from the API (`homework_submission:read` only -
 *    a student setting their own submission timestamp would be meaningless/
 *    spoofable), but nothing else populates it: HomeworkPermissionMatrixTest's
 *    fixtures set it directly via setSubmittedAt() when constructing
 *    CHomeworkSubmission entities by hand, which is not available to the real
 *    Put request HomeworkSubmit.vue's "Submit" action sends. Set once,
 *    guarded by `null === getSubmittedAt()`, so a later grading PUT never
 *    overwrites the original submission time.
 *
 * 3. $evaluatedBy / $evaluatedAt: also read-only from the API (same
 *    `homework_submission:read`-only Groups as $submittedAt - a client value
 *    here would be spoofable/meaningless), and likewise nothing else
 *    populates them. Unlike $submittedAt these ARE meant to update on every
 *    genuine grading action (a teacher re-grading a submission should move
 *    the "last evaluated" timestamp/grader forward, not freeze it at the
 *    first grade), so there is no "already set" guard here - only a check
 *    that (a) the requester is actually privileged to grade
 *    (CHomeworkSubmissionStatusResolver::resolvePrivilegedGrader() - the same
 *    course-wide, cross-session teacher/admin check used for the $status
 *    lock above) and (b) $score or $feedback genuinely changed in this
 *    request, so an unrelated PUT (e.g. HomeworkSubmit.vue's own save/submit
 *    flow, which never touches score/feedback) never stamps evaluation
 *    metadata onto a submission nobody actually graded.
 *
 * 4. $score / $feedback: UNLIKE $submittedAt/$evaluatedBy/$evaluatedAt, these
 *    two DO carry `homework_submission:write` (a teacher's grading PUT has to
 *    be able to set them) - which means, same as the $status exploit
 *    documented on CHomeworkSubmissionStatusResolver, the owning student also
 *    holds EDIT on their own submission via ResourceNodeVoter's ownership
 *    check and could otherwise PUT any score/feedback onto their own row
 *    directly - self-grading, confirmed via live reproduction
 *    (`PUT {"score":99}` from the owning student's session returned 200 and
 *    persisted) before this guard existed. Reuses the exact same
 *    resolvePrivilegedGrader() check as #3: when a grading change is
 *    detected but the requester is NOT privileged, $score/$feedback are
 *    reverted to their pre-request values - same "silently ignore, don't
 *    reject the whole request" semantics as the $status lock, so a student's
 *    otherwise-legitimate PUT (e.g. their own draft-saving flow, which never
 *    touches these fields anyway) is never hard-rejected over this.
 *
 * IMPORTANT: when the resolver rejects a submit attempt
 * (CHomeworkSubmissionDeadlinePassedException), $data is a Doctrine-MANAGED
 * entity (API Platform's provider already fetched it before this processor
 * runs), and by the time this code executes, the row has ALREADY been written
 * to the database with the client's rejected $status - confirmed via the
 * dev-DB verification harness: something earlier in the same kernel.view
 * chain (denormalization/validation/security, all ahead of WriteListener)
 * triggers a flush of the whole UnitOfWork before this processor ever runs,
 * so simply throwing (or even calling refresh(), which only reloads that
 * already-wrong value) is not enough. The catch block below actively writes
 * $previousStatus back and flushes that correction, so a rejected request can
 * never leave a bad status persisted.
 *
 * The catch is intentionally scoped to CHomeworkSubmissionDeadlinePassedException
 * specifically, not a broad `catch (Throwable)`: this recovery path
 * (mutate + flush again) must never run for an UNEXPECTED failure (a
 * TypeError, a DB error unrelated to this logic, etc.) - doing so would risk
 * a second failure inside the catch masking the original one, and would
 * incorrectly apply "restore previous status" recovery semantics to an error
 * that has nothing to do with the deadline check. The recovery flush is
 * further isolated in its own try/catch so that if IT fails, the original
 * CHomeworkSubmissionDeadlinePassedException still propagates (logged, not
 * silently swallowed) rather than being replaced by the recovery failure.
 *
 * @implements ProcessorInterface<CHomeworkSubmission, CHomeworkSubmission>
 */
final class CHomeworkSubmissionPutStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly CHomeworkSubmissionStatusResolver $statusResolver,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function process(
        $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): CHomeworkSubmission {
        $previousData = $context['previous_data'] ?? null;
        $previousStatus = $previousData instanceof CHomeworkSubmission
            ? $previousData->getStatus()
            : CHomeworkSubmission::STATUS_DRAFT;

        try {
            $resolvedStatus = $this->statusResolver->resolve($data, $data->getStatus(), $previousStatus);
        } catch (CHomeworkSubmissionDeadlinePassedException $exception) {
            try {
                $data->setStatus($previousStatus);
                $this->entityManager->flush();
            } catch (Throwable $recoveryFailure) {
                // Never let a recovery failure mask the original, expected
                // rejection - log it for diagnostics and still throw the
                // original $exception below.
                $this->logger->error(
                    'CHomeworkSubmissionPutStateProcessor: failed to restore previous status after a rejected submit attempt.',
                    ['submissionId' => $data->getIid(), 'exception' => $recoveryFailure]
                );
            }

            throw $exception;
        }

        $data->setStatus($resolvedStatus);

        if (
            \in_array($resolvedStatus, [CHomeworkSubmission::STATUS_SUBMITTED, CHomeworkSubmission::STATUS_LATE], true)
            && null === $data->getSubmittedAt()
        ) {
            $data->setSubmittedAt(new DateTime());
        }

        $previousScore = $previousData instanceof CHomeworkSubmission ? $previousData->getScore() : null;
        $previousFeedback = $previousData instanceof CHomeworkSubmission ? $previousData->getFeedback() : null;
        $isGradingChange = $data->getScore() !== $previousScore || $data->getFeedback() !== $previousFeedback;

        if ($isGradingChange) {
            $grader = $this->statusResolver->resolvePrivilegedGrader($data);
            if (null !== $grader) {
                $data->setEvaluatedBy($grader);
                $data->setEvaluatedAt(new DateTime());
            } else {
                // Not a privileged grader (e.g. the submission's own owner) -
                // block self-grading by reverting to the pre-request values.
                $data->setScore($previousScore);
                $data->setFeedback($previousFeedback);
            }
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}

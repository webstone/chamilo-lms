<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use DateTime;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Exception\UnexpectedValueException;

/**
 * Sets CHomeworkSubmission::$user to the authenticated student on create, and
 * enforces the same server-side deadline authority over $status as
 * CHomeworkSubmissionPutStateProcessor.
 *
 * $user has no `homework_submission:write` Groups entry (deliberately - a
 * student must never be able to create a submission "as" another user, an
 * IDOR the entity avoids by not exposing the property for writes at all), but
 * the column is NOT NULL and nothing else populates it: unlike $creator
 * (UserCreatorTrait, auto-filled by ResourceListener::prePersist()), $user is
 * a separate domain field the generic resource lifecycle knows nothing about.
 * Without this processor, every POST to /api/c_homework_submissions fails
 * with a NOT NULL constraint violation on `user_id` - confirmed via a
 * temporary dev-DB script (Task 15) before this processor existed.
 *
 * $status IS writable (`homework_submission:write`), so a POST could include
 * `"status": 2` directly and create-and-submit in one call, bypassing the
 * hard-deadline block the exact same way a bare PUT could (see
 * CHomeworkSubmissionStatusResolver's docblock for the full story). A brand
 * new submission has no prior persisted state, so the "previous status" fed
 * to the resolver is always STATUS_DRAFT here - any non-DRAFT status on
 * create is therefore always treated as a genuine submit attempt.
 *
 * Only wired to the Post operation (see CHomeworkSubmission's ApiResource
 * attribute) - $user is set once at creation and never revisited on Put.
 *
 * @implements ProcessorInterface<CHomeworkSubmission, CHomeworkSubmission>
 */
final class CHomeworkSubmissionPostStateProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $persistProcessor,
        private readonly Security $security,
        private readonly CHomeworkSubmissionStatusResolver $statusResolver,
    ) {}

    public function process(
        $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): CHomeworkSubmission {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new UnexpectedValueException('CHomeworkSubmission cannot be created without an authenticated user.');
        }

        $data->setUser($user);

        $resolvedStatus = $this->statusResolver->resolve($data, $data->getStatus(), CHomeworkSubmission::STATUS_DRAFT);
        $data->setStatus($resolvedStatus);

        if (
            \in_array($resolvedStatus, [CHomeworkSubmission::STATUS_SUBMITTED, CHomeworkSubmission::STATUS_LATE], true)
            && null === $data->getSubmittedAt()
        ) {
            $data->setSubmittedAt(new DateTime());
        }

        return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
    }
}

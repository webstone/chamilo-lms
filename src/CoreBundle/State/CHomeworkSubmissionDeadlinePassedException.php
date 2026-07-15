<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Thrown by CHomeworkSubmissionStatusResolver when a genuine submit attempt
 * (DRAFT -> SUBMITTED/LATE) is rejected because the assignment's deadline has
 * passed and allowLateSubmission is false.
 *
 * A dedicated class (rather than throwing the generic
 * UnprocessableEntityHttpException directly) so CHomeworkSubmissionPutStateProcessor
 * can catch exactly this condition - and only this condition - to run its
 * "write the previous status back" recovery path, without also silently
 * swallowing unrelated failures (a TypeError, a DB error, anything else) that
 * a broad `catch (Throwable)` would otherwise mask behind the same recovery
 * logic.
 *
 * The 422 response body is a plain {"error": "..."} message, NOT the
 * `violations` array shape ApiPlatform's own Assert-based validation errors
 * use elsewhere in this codebase (see toServiceError() in
 * assets/vue/services/api.js, which specifically looks for
 * `data.violations`) - deliberate, not an omission: this is a business-rule
 * rejection computed from real time vs. the assignment's deadline, not a
 * per-property constraint violation on the submitted payload, so there is no
 * single property path to attach a violation to.
 */
final class CHomeworkSubmissionDeadlinePassedException extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('The deadline for this assignment has passed and late submissions are not allowed.');
    }
}

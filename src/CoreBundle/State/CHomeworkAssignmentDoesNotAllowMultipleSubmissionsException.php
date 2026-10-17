<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CHomeworkAssignmentDoesNotAllowMultipleSubmissionsException extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('This assignment does not allow multiple submissions; a final evaluation cannot be created for it.');
    }
}

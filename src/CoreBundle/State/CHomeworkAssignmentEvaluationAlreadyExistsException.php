<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CHomeworkAssignmentEvaluationAlreadyExistsException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('A final evaluation already exists for this student on this assignment.');
    }
}

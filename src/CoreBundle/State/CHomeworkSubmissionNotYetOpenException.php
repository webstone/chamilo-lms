<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CHomeworkSubmissionNotYetOpenException extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('This assignment is not open for submissions yet.');
    }
}

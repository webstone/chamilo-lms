<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class CHomeworkTooManyActiveDraftsException extends UnprocessableEntityHttpException
{
    public function __construct()
    {
        parent::__construct('Finish (submit) your current report before starting a new one.');
    }
}

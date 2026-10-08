<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class CourseRelUserNotStudentException extends ConflictHttpException
{
    public function __construct()
    {
        parent::__construct('Only a student course subscription can be deleted through the API.');
    }
}

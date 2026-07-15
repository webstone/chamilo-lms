<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Tool;

use Chamilo\CoreBundle\Tool\Homework;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use PHPUnit\Framework\TestCase;

final class HomeworkToolTest extends TestCase
{
    public function testRegistersAllThreeResourceTypes(): void
    {
        $tool = new Homework();

        $this->assertSame('huiswerk', $tool->getTitle());
        $this->assertSame(
            [
                'homework_forms' => CHomeworkForm::class,
                'homework_assignments' => CHomeworkAssignment::class,
                'homework_submissions' => CHomeworkSubmission::class,
            ],
            $tool->getResourceTypes()
        );
    }
}

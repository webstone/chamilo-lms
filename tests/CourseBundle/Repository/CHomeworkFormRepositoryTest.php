<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

class CHomeworkFormRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testCreateAndFindForm(): void
    {
        $course = $this->createCourse('new');
        $teacher = $this->createUser('teacher');

        $form = (new CHomeworkForm())
            ->setTitle('Standaard lesverslag')
            ->setCreator($teacher)
            ->setParent($course)
            ->addCourseLink($course)
        ;

        $em = $this->getEntityManager();
        $em->persist($form);
        $em->flush();

        $this->assertNotNull($form->getIid());

        $repo = $em->getRepository(CHomeworkForm::class);
        $found = $repo->find($form->getIid());

        $this->assertSame('Standaard lesverslag', $found->getTitle());
    }
}

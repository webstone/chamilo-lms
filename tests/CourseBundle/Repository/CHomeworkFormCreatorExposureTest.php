<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CourseBundle\Repository;

use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

/**
 * Functional coverage for docs/superpowers/specs/2026-09-28-homework-round4-design.md,
 * section 1: CHomeworkForm exposes its ResourceNode's creator/createdAt under
 * dedicated homework_form:read-only properties, without widening the shared
 * AbstractResource/ResourceNode Groups arrays (see that entity for why).
 */
final class CHomeworkFormCreatorExposureTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testFormExposesCreatorFullNameAndCreatedAt(): void
    {
        $course = $this->createCourse('homework_form_creator_course');
        $teacher = $this->createUser('homework_form_creator_teacher');
        $course->addSubscriptionForUser($teacher, 0, null, CourseRelUser::TEACHER);

        $em = $this->getEntityManager();
        $em->persist($course);
        $em->flush();

        $form = (new CHomeworkForm())
            ->setTitle('Lesverslag sjabloon - creator exposure')
            ->setCreator($teacher)
        ;
        $form->setParent($course);
        $form->addCourseLink($course);
        $em->persist($form);
        $em->flush();

        $token = $this->getUserTokenFromUser($teacher);

        $response = $this->createClientWithCredentials($token)->request(
            'GET',
            '/api/c_homework_forms/'.$form->getIid(),
            ['query' => ['cid' => $course->getId()]]
        );

        $this->assertResponseIsSuccessful();

        $data = $response->toArray();
        $this->assertSame($teacher->getFullName(), $data['creatorFullName'] ?? null);
        $this->assertNotNull($data['createdAt'] ?? null, 'createdAt must be exposed under homework_form:read.');
    }
}

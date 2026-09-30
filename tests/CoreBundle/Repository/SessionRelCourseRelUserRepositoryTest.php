<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CoreBundle\Repository;

use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Repository\SessionRelCourseRelUserRepository;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

final class SessionRelCourseRelUserRepositoryTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    public function testGetSessionsWithCourseByUserReturnsEnrolledSessionAndCourse(): void
    {
        $course = $this->createCourse('scru_repo_course');
        $session = $this->createSession('scru_repo_session');
        $student = $this->createUser('scru_repo_student');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $student, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        /** @var SessionRelCourseRelUserRepository $repo */
        $repo = self::getContainer()->get(SessionRelCourseRelUserRepository::class);

        $pairs = $repo->getSessionsWithCourseByUser($student, $this->getAccessUrl());

        $this->assertCount(1, $pairs);
        $this->assertSame($session->getId(), $pairs[0]['session']->getId());
        $this->assertSame($course->getId(), $pairs[0]['course']->getId());
    }

    public function testGetSessionsWithCourseByUserReturnsEmptyForUnrelatedUser(): void
    {
        $course = $this->createCourse('scru_repo_course_unrelated');
        $session = $this->createSession('scru_repo_session_unrelated');
        $otherStudent = $this->createUser('scru_repo_other_student');
        $outsider = $this->createUser('scru_repo_outsider');

        $session->addCourse($course);
        $session->addUserInCourse(Session::STUDENT, $otherStudent, $course);

        $em = $this->getEntityManager();
        $em->persist($session);
        $em->flush();

        /** @var SessionRelCourseRelUserRepository $repo */
        $repo = self::getContainer()->get(SessionRelCourseRelUserRepository::class);

        $pairs = $repo->getSessionsWithCourseByUser($outsider, $this->getAccessUrl());

        $this->assertCount(0, $pairs);
    }
}

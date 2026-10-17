<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\Tests\CoreBundle\Api;

use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelCourseRelUser;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\Tests\AbstractApiTest;
use Chamilo\Tests\ChamiloTestTrait;

class SessionRelCourseRelUserTest extends AbstractApiTest
{
    use ChamiloTestTrait;

    private function createAdminUser(string $suffix): User
    {
        return $this->createUser('srcru_admin_'.$suffix, 'srcru_admin_'.$suffix, '', 'ROLE_ADMIN');
    }

    // -------------------------------------------------------------------------
    // GET /api/session_rel_course_rel_users - status filter
    // -------------------------------------------------------------------------

    public function testGetCollectionFilterByStatusExcludesCoaches(): void
    {
        $course = $this->createCourse('SRCRU Filter Status Course');
        $session = $this->createSession('SRCRU Filter Status Session');
        $student = $this->createUser('srcru_student_filter');
        $coach = $this->createUser('srcru_coach_filter');
        $admin = $this->createAdminUser('filter_status');

        $em = $this->getEntityManager();

        $studentSub = (new SessionRelCourseRelUser())
            ->setSession($session)
            ->setCourse($course)
            ->setUser($student)
            ->setStatus(Session::STUDENT)
        ;
        $em->persist($studentSub);

        $coachSub = (new SessionRelCourseRelUser())
            ->setSession($session)
            ->setCourse($course)
            ->setUser($coach)
            ->setStatus(Session::COURSE_COACH)
        ;
        $em->persist($coachSub);
        $em->flush();

        $tokenAdmin = $this->getUserTokenFromUser($admin);

        $response = $this->createClientWithCredentials($tokenAdmin)->request(
            'GET',
            '/api/session_rel_course_rel_users?session='.$session->getId().'&course='.$course->getId().'&status='.Session::STUDENT,
        );

        $this->assertResponseIsSuccessful();
        $data = $response->toArray();

        // Only the student's subscription must be returned - the course
        // coach's row must be excluded by the status filter.
        $this->assertSame(1, $data['hydra:totalItems']);
    }
}

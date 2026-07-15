<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Security;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\CourseRelUser;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Entity\SessionRelCourseRelUser;
use Chamilo\CoreBundle\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Determines whether a user is a teacher of a course for Huiswerk purposes,
 * counting course-level teacher links AND session-level teacher links across
 * ALL sessions of the course — not just the currently-selected session.
 *
 * This is a deliberate broadening beyond the default ROLE_CURRENT_COURSE_SESSION_TEACHER
 * check, per the Huiswerk spec: teachers spread across sessions of the same course
 * must see and grade each other's sessions' assignments.
 */
class HomeworkCourseTeacherChecker
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {}

    public function isTeacherOfCourse(User $user, Course $course): bool
    {
        $courseRelUser = $this->entityManager->getRepository(CourseRelUser::class)->findOneBy([
            'user' => $user,
            'course' => $course,
            'status' => CourseRelUser::TEACHER,
        ]);

        if (null !== $courseRelUser) {
            return true;
        }

        $sessionRelCourseRelUser = $this->entityManager->getRepository(SessionRelCourseRelUser::class)->findOneBy([
            'user' => $user,
            'course' => $course,
            'status' => Session::COURSE_COACH,
        ]);

        return null !== $sessionRelCourseRelUser;
    }
}

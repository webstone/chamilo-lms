<?php

/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Framework\Container;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;

/**
 * Sends the "new homework assignment" email alert to every student targeted
 * by the assignment. Mirrors sendEmailToStudentsOnHomeworkCreation() in
 * public/main/work/work.lib.php (same student-list lookup and translation
 * pattern), but points the link at the Huiswerk Vue SPA route
 * (/resources/homework/:nodeId) registered by
 * Chamilo\CoreBundle\Tool\Homework::getLink() instead of the legacy
 * work/work_list.php page.
 */
function sendEmailToStudentsOnHomeworkAssignmentCreation(int $assignmentId, int $courseId, ?int $sessionId = 0): void
{
    $courseInfo = api_get_course_info_by_id($courseId);
    if (empty($courseInfo)) {
        return;
    }
    $courseCode = $courseInfo['code'];

    /** @var CHomeworkAssignment|null $assignment */
    $assignment = Container::getEntityManager()->getRepository(CHomeworkAssignment::class)->find($assignmentId);
    if (null === $assignment || null === $assignment->getResourceNode()) {
        return;
    }

    // Get the students of the course
    if (empty($sessionId)) {
        $students = CourseManager::get_student_list_from_course_code($courseCode);
    } else {
        $students = CourseManager::get_student_list_from_course_code($courseCode, true, $sessionId);
    }

    if (empty($students)) {
        return;
    }

    $emailsubject = '['.api_get_setting('siteName').'] '.get_lang('An assignment was created');
    $currentUser = api_get_user_info(api_get_user_id());

    $link = api_get_path(WEB_PATH).'resources/homework/'.$assignment->getResourceNode()->getId()
        .'?'.http_build_query(['cid' => $courseId, 'sid' => $sessionId]);

    foreach ($students as $student) {
        $user_info = api_get_user_info($student['user_id']);
        if (empty($user_info)) {
            continue;
        }

        $emailbody = get_lang('Dear').' '.$user_info['complete_name'].",\n\n";
        $emailbody .= get_lang('An assignment has been added to course').' '.$courseCode.'. '."\n\n".
            '<a href="'.$link.'">'.get_lang('Please check the assignments page.').'</a>';
        $emailbody .= "\n\n".$currentUser['complete_name'];

        MessageManager::send_message_simple(
            $student['user_id'],
            $emailsubject,
            $emailbody
        );
    }
}

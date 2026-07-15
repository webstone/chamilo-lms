<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\Tests\CoreBundle\Security\Authorization\Voter;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\ResourceNode;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Security\Authorization\Voter\HomeworkVoter;
use Chamilo\CoreBundle\Security\HomeworkCourseTeacherChecker;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class HomeworkVoterTest extends TestCase
{
    /**
     * Builds a ResourceNode with a ResourceLink pointing at the given Course,
     * the same way ResourceNodeVoter expects to be able to walk
     * resourceNode -> resourceLinks -> course.
     */
    private function resourceNodeLinkedToCourse(Course $course): ResourceNode
    {
        $resourceLink = new ResourceLink();
        $resourceLink->setCourse($course);

        // Deliberately NOT using ResourceNode::addResourceLink() here: it calls
        // ResourceLink::setResourceNode(), which dereferences
        // $resourceNode->getResourceType()->getId() - a typed property that is
        // never initialized on a bare `new ResourceNode()` and would throw
        // "must not be accessed before initialization". Populating the
        // collection directly via the public setResourceLinks() setter is
        // sufficient for HomeworkVoter::resolveCourse(), which only reads
        // getResourceLinks() and never touches the link's resourceNode/resourceType.
        $resourceNode = new ResourceNode();
        $resourceNode->setResourceLinks(new ArrayCollection([$resourceLink]));

        return $resourceNode;
    }

    private function assignmentLinkedToCourse(Course $course): CHomeworkAssignment
    {
        $assignment = new CHomeworkAssignment();
        $assignment->setResourceNode($this->resourceNodeLinkedToCourse($course));

        return $assignment;
    }

    public function testGrantsEditWhenCourseTeacherCheckerReturnsTrue(): void
    {
        $user = $this->createMock(User::class);
        $course = new Course();
        $assignment = $this->assignmentLinkedToCourse($course);

        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->once())
            ->method('isTeacherOfCourse')
            ->with($user, $course)
            ->willReturn(true);

        $voter = new HomeworkVoter($checker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $assignment, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testGrantsEditForCHomeworkForm(): void
    {
        $user = $this->createMock(User::class);
        $course = new Course();

        $form = new CHomeworkForm();
        $form->setResourceNode($this->resourceNodeLinkedToCourse($course));

        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->once())
            ->method('isTeacherOfCourse')
            ->with($user, $course)
            ->willReturn(true);

        $voter = new HomeworkVoter($checker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $form, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testGrantsEditForCHomeworkSubmissionByResolvingCourseFromItsAssignment(): void
    {
        $user = $this->createMock(User::class);
        $course = new Course();
        $assignment = $this->assignmentLinkedToCourse($course);

        // The submission itself is deliberately left without any ResourceNode.
        // resolveCourse() must resolve the course via the submission's
        // assignment, not via the submission's own (non-existent) resource
        // node - submissions don't carry a course-linked ResourceNode the way
        // assignments/forms do.
        $submission = new CHomeworkSubmission();
        $submission->setAssignment($assignment);

        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->once())
            ->method('isTeacherOfCourse')
            ->with($user, $course)
            ->willReturn(true);

        $voter = new HomeworkVoter($checker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $submission, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    public function testDeniesWhenCourseTeacherCheckerReturnsFalse(): void
    {
        $user = $this->createMock(User::class);
        $course = new Course();
        $assignment = $this->assignmentLinkedToCourse($course);

        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->once())
            ->method('isTeacherOfCourse')
            ->with($user, $course)
            ->willReturn(false);

        $voter = new HomeworkVoter($checker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $assignment, ['EDIT']);

        // Symfony's base Voter::vote() only ever returns ACCESS_ABSTAIN when
        // none of the requested attributes are supported at all (see
        // vendor/symfony/security-core/Authorization/Voter/Voter.php). Once
        // supports() matches, the outcome is either GRANTED or DENIED, never
        // ABSTAIN - so a checker returning false must translate to DENIED.
        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testDeniesWithoutCallingCheckerWhenNoCourseCanBeResolved(): void
    {
        // No ResourceNode set at all, so resolveCourse() must short-circuit
        // to null before ever reaching the checker.
        $assignment = new CHomeworkAssignment();

        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->never())->method('isTeacherOfCourse');

        $voter = new HomeworkVoter($checker);

        $user = $this->createMock(User::class);
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $result = $voter->vote($token, $assignment, ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    public function testAbstainsForUnsupportedSubjectType(): void
    {
        $checker = $this->createMock(HomeworkCourseTeacherChecker::class);
        $checker->expects($this->never())->method('isTeacherOfCourse');

        $voter = new HomeworkVoter($checker);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($this->createMock(User::class));

        $result = $voter->vote($token, new \stdClass(), ['EDIT']);

        $this->assertSame(VoterInterface::ACCESS_ABSTAIN, $result);
    }
}

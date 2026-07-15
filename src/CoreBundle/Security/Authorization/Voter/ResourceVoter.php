<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\Security\Authorization\Voter;

use Chamilo\CoreBundle\Entity\AbstractResource;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\Usergroup;
use Chamilo\CourseBundle\Entity\CCalendarEvent;
use Chamilo\CourseBundle\Entity\CGroup;
use Chamilo\CourseBundle\Entity\CHomeworkAssignment;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkSubmission;
use Symfony\Component\Security\Acl\Permission\MaskBuilder;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * @extends Voter<'CREATE'|'VIEW'|'EDIT'|'DELETE'|'EXPORT', AbstractResource>
 */
class ResourceVoter extends Voter
{
    public const VIEW = 'VIEW';
    public const CREATE = 'CREATE';
    public const EDIT = 'EDIT';
    public const DELETE = 'DELETE';
    public const EXPORT = 'EXPORT';

    public function __construct(
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
    ) {}

    public static function getReaderMask(): int
    {
        $builder = (new MaskBuilder())
            ->add(self::VIEW)
        ;

        return $builder->get();
    }

    public static function getEditorMask(): int
    {
        $builder = (new MaskBuilder())
            ->add(self::VIEW)
            ->add(self::EDIT)
        ;

        return $builder->get();
    }

    protected function supports(string $attribute, $subject): bool
    {
        $options = [
            self::VIEW,
            self::CREATE,
            self::EDIT,
            self::DELETE,
            self::EXPORT,
        ];

        // if the attribute isn't one we support, return false
        if (!\in_array($attribute, $options, true)) {
            return false;
        }

        // These are AbstractResource subclasses, but each is authorized by its
        // own dedicated voter (CourseVoter, GroupVoter, CCalendarEventVoter,
        // UsergroupVoter, HomeworkVoter). This voter must abstain on them so
        // it does not co-decide under the unanimous strategy.
        //
        // The Homework* entities are a load-bearing case: their API Platform
        // operations declare security as
        // "is_granted(ATTR, object.resourceNode) or is_granted(ATTR, object)"
        // specifically so that HomeworkVoter's course-wide, cross-session
        // teacher grant (via HomeworkCourseTeacherChecker) can succeed even
        // when ResourceNodeVoter's own (CURRENT session-scoped) decision on
        // object.resourceNode would deny. Without this exemption,
        // "is_granted(ATTR, object)" would ALSO recurse back into
        // ResourceNodeVoter below (this voter delegates to it for any
        // AbstractResource with no dedicated voter), so a session-A-only
        // teacher's request for a session-B submission/assignment would
        // collect one GRANT vote (HomeworkVoter) and one DENY vote (this
        // voter, mirroring ResourceNodeVoter) for the SAME is_granted() call -
        // and the 'unanimous' AccessDecisionManager strategy denies as soon
        // as any voter says no, silently defeating the entire cross-session
        // "volledige inzage en nakijkrecht" requirement from the Huiswerk
        // design spec.
        if ($subject instanceof Course
            || $subject instanceof CGroup
            || $subject instanceof CCalendarEvent
            || $subject instanceof Usergroup
            || $subject instanceof CHomeworkAssignment
            || $subject instanceof CHomeworkForm
            || $subject instanceof CHomeworkSubmission
        ) {
            return false;
        }

        // only vote on AbstractResource objects inside this voter
        return $subject instanceof AbstractResource;
    }

    protected function voteOnAttribute(string $attribute, $subject, TokenInterface $token): bool
    {
        // Delegate the decision to the resource node ACL (ResourceNodeVoter),
        // which performs the real course/session/group/owner scoping. Failing
        // closed when there is no node (e.g. a not-yet-persisted resource on
        // CREATE) replaces the previous unconditional grant.
        if (!$subject instanceof AbstractResource) {
            return false;
        }

        $resourceNode = $subject->getResourceNode();
        if (null === $resourceNode) {
            return false;
        }

        // Use the AccessDecisionManager (not Security::isGranted) so the nested
        // decision runs against the exact token passed to this voter, per the
        // Symfony voter docs ("Checking for Roles inside a Voter").
        return $this->accessDecisionManager->decide($token, [$attribute], $resourceNode);
    }
}

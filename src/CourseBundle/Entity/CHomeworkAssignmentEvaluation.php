<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Chamilo\CoreBundle\Entity\AbstractResource;
use Chamilo\CoreBundle\Entity\ResourceInterface;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Filter\CidFilter;
use Chamilo\CoreBundle\State\CHomeworkAssignmentEvaluationPostStateProcessor;
use Chamilo\CoreBundle\State\CHomeworkAssignmentEvaluationPutStateProcessor;
use Chamilo\CourseBundle\Repository\CHomeworkAssignmentEvaluationRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Table(name: 'c_homework_assignment_evaluation')]
#[ORM\UniqueConstraint(name: 'UNIQ_HOMEWORK_EVALUATION_ASSIGNMENT_USER', columns: ['assignment_id', 'user_id'])]
#[ORM\Entity(repositoryClass: CHomeworkAssignmentEvaluationRepository::class)]
#[ApiResource(
    operations: [
        // Same "or is_granted('VIEW', object)" cross-session pattern as
        // CHomeworkAssignment/CHomeworkSubmission - see those entities'
        // docblocks.
        // Bypass branch mirrors Put's own "object.resourceNode or object" EDIT
        // pair below - is_granted('EDIT', object) ALONE is not enough here:
        // HomeworkVoter (which decides EDIT/VIEW on this entity - see
        // ResourceVoter's abstain exemption) has no ROLE_ADMIN shortcut of
        // its own, so without the object.resourceNode half a superadmin with
        // no course relation would be denied by HomeworkVoter and then also
        // fail the deadline-gated VIEW branch, producing a 403 before the
        // deadline for platform admins - a real access-control regression,
        // not just a style choice. object.resourceNode routes through
        // ResourceNodeVoter's own unconditional ROLE_ADMIN grant instead.
        new Get(
            security: "is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object) or ((is_granted('VIEW', object.resourceNode) or is_granted('VIEW', object)) and object.isVisibleToStudent())",
        ),
        // Narrowed to the requester's own row for non-privileged users by
        // CHomeworkAssignmentEvaluationExtension, same as CHomeworkSubmission
        // - see that extension.
        new GetCollection(security: "is_granted('ROLE_CURRENT_COURSE_STUDENT') or is_granted('ROLE_CURRENT_COURSE_SESSION_STUDENT')"),
        // Only a teacher creates an evaluation (it targets a specific
        // student, chosen by the teacher - see $user below).
        new Post(
            security: "is_granted('ROLE_CURRENT_COURSE_TEACHER') or is_granted('ROLE_CURRENT_COURSE_SESSION_TEACHER')",
            processor: CHomeworkAssignmentEvaluationPostStateProcessor::class,
        ),
        new Put(
            security: "is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object)",
            processor: CHomeworkAssignmentEvaluationPutStateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['homework_assignment_evaluation:read']],
    denormalizationContext: ['groups' => ['homework_assignment_evaluation:write']],
)]
#[ApiFilter(filterClass: SearchFilter::class, properties: ['assignment.iid' => 'exact'])]
#[ApiFilter(filterClass: CidFilter::class)]
// Deliberately no SidFilter - identical reasoning to CHomeworkSubmission (see
// that entity's docblock): an unconditional session match would block a
// cross-session course-wide teacher from evaluations outside their own
// session's context.
class CHomeworkAssignmentEvaluation extends AbstractResource implements ResourceInterface, Stringable
{
    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[ORM\ManyToOne(targetEntity: CHomeworkAssignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'iid', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['homework_assignment_evaluation:write', 'homework_assignment_evaluation:read'])]
    protected CHomeworkAssignment $assignment;

    // Unlike CHomeworkSubmission::$user (always the authenticated caller,
    // read-only), this IS writable on create: the teacher explicitly targets
    // one student per evaluation. CHomeworkAssignmentEvaluationPutStateProcessor
    // forces this back to its original value on every PUT, making it
    // effectively immutable after creation despite the write Group - see that
    // processor.
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['homework_assignment_evaluation:write', 'homework_assignment_evaluation:read'])]
    protected User $user;

    #[ORM\Column(name: 'score', type: 'float', nullable: true)]
    #[Groups(['homework_assignment_evaluation:write', 'homework_assignment_evaluation:read'])]
    protected ?float $score = null;

    #[ORM\Column(name: 'feedback', type: 'text', nullable: true)]
    #[Groups(['homework_assignment_evaluation:write', 'homework_assignment_evaluation:read'])]
    protected ?string $feedback = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'evaluated_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_assignment_evaluation:read'])]
    protected ?User $evaluatedBy = null;

    #[ORM\Column(name: 'evaluated_at', type: 'datetime', nullable: true)]
    #[Groups(['homework_assignment_evaluation:read'])]
    protected ?DateTime $evaluatedAt = null;

    public function __toString(): string
    {
        return (string) $this->iid;
    }

    public function getIid(): ?int
    {
        return $this->iid;
    }

    public function getAssignment(): CHomeworkAssignment
    {
        return $this->assignment;
    }

    public function setAssignment(CHomeworkAssignment $assignment): self
    {
        $this->assignment = $assignment;

        return $this;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getScore(): ?float
    {
        return $this->score;
    }

    public function setScore(?float $score): self
    {
        $this->score = $score;

        return $this;
    }

    public function getFeedback(): ?string
    {
        return $this->feedback;
    }

    public function setFeedback(?string $feedback): self
    {
        $this->feedback = $feedback;

        return $this;
    }

    public function getEvaluatedBy(): ?User
    {
        return $this->evaluatedBy;
    }

    public function setEvaluatedBy(?User $evaluatedBy): self
    {
        $this->evaluatedBy = $evaluatedBy;

        return $this;
    }

    public function getEvaluatedAt(): ?DateTime
    {
        return $this->evaluatedAt;
    }

    public function setEvaluatedAt(?DateTime $evaluatedAt): self
    {
        $this->evaluatedAt = $evaluatedAt;

        return $this;
    }

    /**
     * Gates student-facing visibility on the assignment's deadline (docs/superpowers/specs/2026-09-28-homework-round4-design.md,
     * section 4). Teachers/cross-session-teachers/superadmins bypass this
     * entirely via the Get security expression's two EDIT branches
     * ("is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object)"
     * - both are needed, see that expression's own comment) and
     * CHomeworkAssignmentEvaluationExtension's own early returns - this
     * method is only ever consulted for a non-privileged caller.
     */
    public function isVisibleToStudent(): bool
    {
        return new DateTime() > $this->assignment->getDeadline();
    }

    public function getResourceIdentifier(): int
    {
        return $this->getIid();
    }

    public function getResourceName(): string
    {
        return \sprintf('evaluation-%s', $this->getAssignment()->getTitle());
    }

    public function setResourceName(string $name): self
    {
        return $this;
    }
}

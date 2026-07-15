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
use Chamilo\CoreBundle\State\CHomeworkSubmissionPostStateProcessor;
use Chamilo\CoreBundle\State\CHomeworkSubmissionPutStateProcessor;
use Chamilo\CourseBundle\Repository\CHomeworkSubmissionRepository;
use DateTime;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Table(name: 'c_homework_submission')]
#[ORM\UniqueConstraint(name: 'UNIQ_HOMEWORK_SUBMISSION_ASSIGNMENT_USER', columns: ['assignment_id', 'user_id'])]
#[ORM\Entity(repositoryClass: CHomeworkSubmissionRepository::class)]
#[ApiResource(
    operations: [
        // See CHomeworkAssignment for why "or is_granted('VIEW', object)" is
        // required here too: without it, a course-linked teacher of a
        // DIFFERENT session than the one a submission belongs to has no way
        // to view (only to edit/grade, via Put below) that submission -
        // contradicting the spec's "volledige inzage EN nakijkrecht over alle
        // sessies" requirement.
        new Get(security: "is_granted('VIEW', object.resourceNode) or is_granted('VIEW', object)"),
        new GetCollection(security: "is_granted('ROLE_CURRENT_COURSE_STUDENT') or is_granted('ROLE_CURRENT_COURSE_SESSION_STUDENT')"),
        new Post(
            security: "is_granted('ROLE_CURRENT_COURSE_STUDENT') or is_granted('ROLE_CURRENT_COURSE_SESSION_STUDENT')",
            processor: CHomeworkSubmissionPostStateProcessor::class,
        ),
        new Put(
            security: "is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object)",
            processor: CHomeworkSubmissionPutStateProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['homework_submission:read']],
    denormalizationContext: ['groups' => ['homework_submission:write']],
)]
#[ApiFilter(filterClass: SearchFilter::class, properties: [
    'assignment.iid' => 'exact',
    // Lets HomeworkCorrectAndRate.vue's status filter (draft/submitted/late)
    // be applied server-side, in combination with real pagination, instead
    // of filtering client-side over a possibly-truncated page of results.
    'status' => 'exact',
])]
#[ApiFilter(filterClass: CidFilter::class)]
// Deliberately NO SidFilter here (unlike most course-scoped resources): a
// bare `resource_links.session = :sid` restriction (CHomeworkSubmission does
// not implement ResourceShowCourseResourcesInSessionInterface) applies
// UNCONDITIONALLY, regardless of role, and stacks with (does not merge with)
// CHomeworkSubmissionExtension's own privilege check below. Confirmed via a
// live reproduction: a teacher who is a course-wide coach via
// session B ONLY correctly gets cross-session item-level VIEW/EDIT on a
// session-A submission (HomeworkVoter), but every GetCollection call made
// from within session B's context (sid=B, which is what
// chomeworksubmission.js's buildCidParams() always sends whenever the current
// page has a session in its URL) was silently filtered down to "session B
// only" by SidFilter BEFORE CHomeworkSubmissionExtension's broader access
// grant ever had a chance to matter - hiding every other session's
// submissions from the module's own "volledige inzage over alle sessies"
// cross-session grading list, the module's core requirement. Removing
// SidFilter is safe for the student case too: a student's own submissions
// are already fully scoped by the user-scoped ResourceLink (`uid`) +
// CHomeworkSubmissionExtension's `resource_links.user = :currentUser`
// fallback, which has no dependency on session at all. An invalid/unknown
// `sid` still 404s regardless of this filter's removal - see
// CHomeworkAssignment's equivalent comment for why (CidReqListener already
// validates it earlier in the request, independent of any API Platform
// filter).
class CHomeworkSubmission extends AbstractResource implements ResourceInterface, Stringable
{
    public const STATUS_DRAFT = 1;
    public const STATUS_SUBMITTED = 2;
    public const STATUS_LATE = 3;

    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[ORM\ManyToOne(targetEntity: CHomeworkAssignment::class)]
    #[ORM\JoinColumn(name: 'assignment_id', referencedColumnName: 'iid', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected CHomeworkAssignment $assignment;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['homework_submission:read'])]
    protected User $user;

    #[ORM\Column(name: 'submitted_at', type: 'datetime', nullable: true)]
    #[Groups(['homework_submission:read'])]
    protected ?DateTime $submittedAt = null;

    #[ORM\ManyToOne(targetEntity: CDocument::class)]
    #[ORM\JoinColumn(name: 'document_id', referencedColumnName: 'iid', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected ?CDocument $document = null;

    #[ORM\Column(name: 'status', type: 'smallint', nullable: false)]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected int $status = self::STATUS_DRAFT;

    #[ORM\Column(name: 'score', type: 'float', nullable: true)]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected ?float $score = null;

    #[ORM\Column(name: 'feedback', type: 'text', nullable: true)]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected ?string $feedback = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'evaluated_by', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_submission:read'])]
    protected ?User $evaluatedBy = null;

    #[ORM\Column(name: 'evaluated_at', type: 'datetime', nullable: true)]
    #[Groups(['homework_submission:read'])]
    protected ?DateTime $evaluatedAt = null;

    /**
     * @var Collection<int, CHomeworkSubmissionAnswer>
     */
    #[ORM\OneToMany(mappedBy: 'submission', targetEntity: CHomeworkSubmissionAnswer::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected Collection $answers;

    public function __construct()
    {
        $this->answers = new ArrayCollection();
    }

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

    public function getSubmittedAt(): ?DateTime
    {
        return $this->submittedAt;
    }

    public function setSubmittedAt(?DateTime $submittedAt): self
    {
        $this->submittedAt = $submittedAt;

        return $this;
    }

    public function getDocument(): ?CDocument
    {
        return $this->document;
    }

    public function setDocument(?CDocument $document): self
    {
        $this->document = $document;

        return $this;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

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
     * @return Collection<int, CHomeworkSubmissionAnswer>
     */
    public function getAnswers(): Collection
    {
        return $this->answers;
    }

    public function addAnswer(CHomeworkSubmissionAnswer $answer): self
    {
        if (!$this->answers->contains($answer)) {
            $this->answers->add($answer);
            $answer->setSubmission($this);
        }

        return $this;
    }

    // Symfony's PropertyAccessor only treats a Collection property as writable
    // via the add*/remove* pattern when BOTH methods exist (see
    // CHomeworkForm::removePage()/CHomeworkFormPage::removeField() for the
    // same fix already applied there in Task 14): without this, $answers was
    // silently skipped on every denormalization, so a submission's answers
    // could never actually be written through the API.
    public function removeAnswer(CHomeworkSubmissionAnswer $answer): self
    {
        $this->answers->removeElement($answer);

        return $this;
    }

    public function getResourceIdentifier(): int
    {
        return $this->getIid();
    }

    public function getResourceName(): string
    {
        // Submissions have no intrinsic title, and $this->iid is still null at the
        // point ResourceListener::prePersist() calls this (before the INSERT runs),
        // so it cannot be used here. Derive a synthetic name from the assignment instead.
        return \sprintf('submission-%s', $this->getAssignment()->getTitle());
    }

    public function setResourceName(string $name): self
    {
        return $this;
    }
}

<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Doctrine\Common\Filter\OrderFilterInterface;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Chamilo\CoreBundle\Entity\AbstractResource;
use Chamilo\CoreBundle\Entity\ResourceInterface;
use Chamilo\CoreBundle\Entity\Session;
use Chamilo\CoreBundle\Filter\CidFilter;
use Chamilo\CoreBundle\State\CHomeworkAssignmentDeleteProcessor;
use Chamilo\CoreBundle\State\CHomeworkAssignmentPostStateProcessor;
use Chamilo\CourseBundle\Repository\CHomeworkAssignmentRepository;
use DateTime;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'c_homework_assignment')]
#[ORM\Entity(repositoryClass: CHomeworkAssignmentRepository::class)]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['homework_assignment:read', 'homework_assignment:item:get']],
            // "or is_granted('VIEW', object)" is what actually delivers the
            // spec's cross-session "volledige inzage" requirement via
            // HomeworkVoter/HomeworkCourseTeacherChecker - without it, a
            // teacher linked to the course only through a different session
            // than the one the assignment targets has no way to pass this
            // check (ResourceNodeVoter's own course/session role resolution
            // is scoped to the CURRENT session context only). Mirrors the
            // same "or is_granted('EDIT', object)" pattern already used on
            // Put/Delete below.
            security: "is_granted('VIEW', object.resourceNode) or is_granted('VIEW', object)",
        ),
        new GetCollection(
            security: "is_granted('ROLE_CURRENT_COURSE_STUDENT') or is_granted('ROLE_CURRENT_COURSE_SESSION_STUDENT')",
        ),
        new Post(
            security: "is_granted('ROLE_CURRENT_COURSE_TEACHER') or is_granted('ROLE_CURRENT_COURSE_SESSION_TEACHER')",
            processor: CHomeworkAssignmentPostStateProcessor::class,
        ),
        new Put(
            security: "is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object)",
            processor: CHomeworkAssignmentPostStateProcessor::class,
        ),
        // Reuses HomeworkVoter's EDIT (not a dedicated DELETE) intentionally:
        // the Huiswerk spec has no "can delete but not edit" role, so
        // course-wide teacher rights cover deletion too.
        new Delete(
            security: "is_granted('DELETE', object.resourceNode) or is_granted('EDIT', object)",
            processor: CHomeworkAssignmentDeleteProcessor::class,
        ),
    ],
    normalizationContext: ['groups' => ['homework_assignment:read']],
    denormalizationContext: ['groups' => ['homework_assignment:write']],
    order: ['deadline' => 'ASC'],
)]
#[ApiFilter(OrderFilter::class, properties: ['title', 'deadline' => ['nulls_comparison' => OrderFilterInterface::NULLS_SMALLEST]])]
#[ApiFilter(filterClass: CidFilter::class)]
// Deliberately NO SidFilter here - session-aware collection scoping (session
// match OR whole-course, with a course-wide-teacher bypass) is hand-implemented
// in CHomeworkAssignmentExtension instead. See that class's docblock for why:
// SidFilter's own unconditional `resource_links.session = :sid` restriction
// (CHomeworkAssignment does not implement
// ResourceShowCourseResourcesInSessionInterface) stacks with, rather than
// merges into, any privilege check an extension adds, so a privileged
// cross-session teacher stayed blocked by SidFilter alone regardless of the
// extension's own decision - confirmed via a live reproduction (a session-B-only
// coach's own submission/assignment collection requests, which always carry
// `sid`, came back empty for anything scoped to a different session).
// An invalid/unknown `sid` still 404s ("Session not found") regardless of this
// filter's removal: CidReqListener validates it at kernel.request time,
// before any API Platform filter/extension ever runs (cid is a required query
// parameter on this resource, so that validation branch always executes).
class CHomeworkAssignment extends AbstractResource implements ResourceInterface, Stringable
{
    public const TYPE_FILE = 1;
    public const TYPE_FORM = 2;

    public const EVALUATION_NONE = 1;
    public const EVALUATION_STATUS_ONLY = 2;
    public const EVALUATION_SCORE = 3;

    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[Assert\NotBlank]
    #[ORM\Column(name: 'title', type: 'string', length: 255, nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected string $title = '';

    #[ORM\Column(name: 'description', type: 'text', nullable: true)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected ?string $description = null;

    #[ORM\ManyToOne(targetEntity: Session::class)]
    #[ORM\JoinColumn(name: 'session_id', referencedColumnName: 'id', nullable: true, onDelete: 'CASCADE')]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected ?Session $session = null;

    #[Assert\Choice(choices: [self::TYPE_FILE, self::TYPE_FORM])]
    #[ORM\Column(name: 'submission_type', type: 'smallint', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected int $submissionType;

    #[ORM\Column(name: 'opens_on', type: 'datetime', nullable: true)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected ?DateTime $opensOn = null;

    #[Assert\NotNull]
    #[ORM\Column(name: 'deadline', type: 'datetime', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected DateTime $deadline;

    #[ORM\Column(name: 'allow_late_submission', type: 'boolean', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected bool $allowLateSubmission = false;

    #[ORM\ManyToOne(targetEntity: CDocument::class)]
    #[ORM\JoinColumn(name: 'template_document_id', referencedColumnName: 'iid', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected ?CDocument $templateDocument = null;

    #[ORM\ManyToOne(targetEntity: CHomeworkForm::class)]
    #[ORM\JoinColumn(name: 'form_id', referencedColumnName: 'iid', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected ?CHomeworkForm $form = null;

    #[Assert\Choice(choices: [self::EVALUATION_NONE, self::EVALUATION_STATUS_ONLY, self::EVALUATION_SCORE])]
    #[ORM\Column(name: 'evaluation_mode', type: 'smallint', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected int $evaluationMode;

    #[ORM\Column(name: 'add_to_gradebook', type: 'boolean', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected bool $addToGradebook = false;

    #[ORM\Column(name: 'gradebook_category_id', type: 'integer', nullable: false)]
    #[Groups(['homework_assignment:write'])]
    protected int $gradebookCategoryId = 0;

    #[ORM\Column(name: 'weight', type: 'float', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected float $weight = 0;

    #[ORM\Column(name: 'add_to_calendar', type: 'boolean', nullable: false)]
    #[Groups(['homework_assignment:write', 'homework_assignment:read'])]
    protected bool $addToCalendar = false;

    #[ORM\Column(name: 'event_calendar_id', type: 'integer', nullable: false)]
    #[Groups(['homework_assignment:item:get'])]
    protected int $eventCalendarId = 0;

    public function __toString(): string
    {
        return $this->title;
    }

    public function getIid(): ?int
    {
        return $this->iid;
    }

    public function getResourceIdentifier(): int
    {
        return $this->getIid();
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function getSession(): ?Session
    {
        return $this->session;
    }

    public function setSession(?Session $session): self
    {
        $this->session = $session;

        return $this;
    }

    public function getSessionId(): ?int
    {
        return $this->session?->getId();
    }

    public function getSubmissionType(): int
    {
        return $this->submissionType;
    }

    public function setSubmissionType(int $submissionType): self
    {
        $this->submissionType = $submissionType;

        return $this;
    }

    public function getOpensOn(): ?DateTime
    {
        return $this->opensOn;
    }

    public function setOpensOn(?DateTime $opensOn): self
    {
        $this->opensOn = $opensOn;

        return $this;
    }

    public function getDeadline(): DateTime
    {
        return $this->deadline;
    }

    public function setDeadline(DateTime $deadline): self
    {
        $this->deadline = $deadline;

        return $this;
    }

    public function isAllowLateSubmission(): bool
    {
        return $this->allowLateSubmission;
    }

    public function setAllowLateSubmission(bool $allowLateSubmission): self
    {
        $this->allowLateSubmission = $allowLateSubmission;

        return $this;
    }

    public function getTemplateDocument(): ?CDocument
    {
        return $this->templateDocument;
    }

    public function setTemplateDocument(?CDocument $templateDocument): self
    {
        $this->templateDocument = $templateDocument;

        return $this;
    }

    public function getForm(): ?CHomeworkForm
    {
        return $this->form;
    }

    public function setForm(?CHomeworkForm $form): self
    {
        $this->form = $form;

        return $this;
    }

    public function getEvaluationMode(): int
    {
        return $this->evaluationMode;
    }

    public function setEvaluationMode(int $evaluationMode): self
    {
        $this->evaluationMode = $evaluationMode;

        return $this;
    }

    public function isAddToGradebook(): bool
    {
        return $this->addToGradebook;
    }

    public function setAddToGradebook(bool $addToGradebook): self
    {
        $this->addToGradebook = $addToGradebook;

        return $this;
    }

    public function getGradebookCategoryId(): int
    {
        return $this->gradebookCategoryId;
    }

    public function setGradebookCategoryId(int $gradebookCategoryId): self
    {
        $this->gradebookCategoryId = $gradebookCategoryId;

        return $this;
    }

    public function getWeight(): float
    {
        return $this->weight;
    }

    public function setWeight(float $weight): self
    {
        $this->weight = $weight;

        return $this;
    }

    public function isAddToCalendar(): bool
    {
        return $this->addToCalendar;
    }

    public function setAddToCalendar(bool $addToCalendar): self
    {
        $this->addToCalendar = $addToCalendar;

        return $this;
    }

    public function getEventCalendarId(): int
    {
        return $this->eventCalendarId;
    }

    public function setEventCalendarId(int $eventCalendarId): self
    {
        $this->eventCalendarId = $eventCalendarId;

        return $this;
    }

    public function getResourceName(): string
    {
        return $this->getTitle();
    }

    public function setResourceName(string $name): self
    {
        return $this->setTitle($name);
    }
}

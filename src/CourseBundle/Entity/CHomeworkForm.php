<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Chamilo\CoreBundle\Entity\AbstractResource;
use Chamilo\CoreBundle\Entity\ResourceInterface;
use Chamilo\CoreBundle\Filter\CidFilter;
use Chamilo\CourseBundle\Repository\CHomeworkFormRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'c_homework_form')]
#[ORM\Entity(repositoryClass: CHomeworkFormRepository::class)]
#[ApiResource(
    operations: [
        // See CHomeworkAssignment/CHomeworkSubmission for why the "or
        // is_granted('VIEW', object)" clause is required: it is what wires
        // HomeworkCourseTeacherChecker's cross-session course-wide "volledige
        // inzage" into this operation; ResourceNodeVoter alone only resolves
        // roles for the CURRENTLY selected session context.
        new Get(security: "is_granted('VIEW', object.resourceNode) or is_granted('VIEW', object)"),
        new GetCollection(
            security: "is_granted('ROLE_CURRENT_COURSE_STUDENT') or is_granted('ROLE_CURRENT_COURSE_SESSION_STUDENT')",
        ),
        new Post(
            security: "is_granted('ROLE_CURRENT_COURSE_TEACHER') or is_granted('ROLE_CURRENT_COURSE_SESSION_TEACHER')",
        ),
        new Put(security: "is_granted('EDIT', object.resourceNode) or is_granted('EDIT', object)"),
        // Reuses HomeworkVoter's EDIT (not a dedicated DELETE) intentionally:
        // the Huiswerk spec has no "can delete but not edit" role, so
        // course-wide teacher rights cover deletion too.
        new Delete(security: "is_granted('DELETE', object.resourceNode) or is_granted('EDIT', object)"),
    ],
    normalizationContext: ['groups' => ['homework_form:read']],
    denormalizationContext: ['groups' => ['homework_form:write']],
)]
#[ApiFilter(filterClass: CidFilter::class)]
// Deliberately NO SidFilter here - see CHomeworkFormExtension's docblock:
// SidFilter's unconditional `resource_links.session = :sid` restriction had
// no course-wide-teacher bypass, blocking the cross-session teacher
// requirement whenever `sid` was present. Session-match-OR-whole-course
// scoping (the actual student-privacy requirement - a session-B form must
// stay invisible to a session-A student) is hand-implemented in
// CHomeworkFormExtension instead, same as CHomeworkAssignment. An
// invalid/unknown `sid` still 404s regardless (CidReqListener, not this
// filter, is what validates it - see CHomeworkAssignment's equivalent note).
class CHomeworkForm extends AbstractResource implements ResourceInterface, Stringable
{
    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[Assert\NotBlank]
    #[ORM\Column(name: 'title', type: 'string', length: 255, nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected string $title = '';

    /**
     * @var Collection<int, CHomeworkFormPage>
     */
    #[ORM\OneToMany(mappedBy: 'form', targetEntity: CHomeworkFormPage::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    #[Assert\Valid]
    protected Collection $pages;

    public function __construct()
    {
        $this->pages = new ArrayCollection();
    }

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

    /**
     * @return Collection<int, CHomeworkFormPage>
     */
    public function getPages(): Collection
    {
        return $this->pages;
    }

    public function addPage(CHomeworkFormPage $page): self
    {
        if (!$this->pages->contains($page)) {
            $this->pages->add($page);
            $page->setForm($this);
        }

        return $this;
    }

    public function removePage(CHomeworkFormPage $page): self
    {
        $this->pages->removeElement($page);

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

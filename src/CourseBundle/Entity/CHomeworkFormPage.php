<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Action\NotFoundAction;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Chamilo\CourseBundle\Repository\CHomeworkFormPageRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'c_homework_form_page')]
#[ORM\Entity(repositoryClass: CHomeworkFormPageRepository::class)]
#[ApiResource(operations: [new Get(controller: NotFoundAction::class, output: false, read: false)])]
class CHomeworkFormPage implements Stringable
{
    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[Assert\NotBlank]
    #[ORM\Column(name: 'title', type: 'string', length: 255, nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected string $title = '';

    #[ORM\Column(name: 'sort_order', type: 'integer', nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: CHomeworkForm::class, inversedBy: 'pages')]
    #[ORM\JoinColumn(name: 'form_id', referencedColumnName: 'iid', onDelete: 'CASCADE')]
    protected CHomeworkForm $form;

    /** @var Collection<int, CHomeworkFormField> */
    #[ORM\OneToMany(mappedBy: 'page', targetEntity: CHomeworkFormField::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['sortOrder' => 'ASC'])]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    #[Assert\Valid]
    protected Collection $fields;

    public function __construct()
    {
        $this->fields = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title;
    }

    public function getIid(): ?int
    {
        return $this->iid;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function getForm(): CHomeworkForm
    {
        return $this->form;
    }

    public function setForm(CHomeworkForm $form): self
    {
        $this->form = $form;

        return $this;
    }

    /** @return Collection<int, CHomeworkFormField> */
    public function getFields(): Collection
    {
        return $this->fields;
    }

    public function addField(CHomeworkFormField $field): self
    {
        if (!$this->fields->contains($field)) {
            $this->fields->add($field);
            $field->setPage($this);
        }

        return $this;
    }

    public function removeField(CHomeworkFormField $field): self
    {
        $this->fields->removeElement($field);

        return $this;
    }
}

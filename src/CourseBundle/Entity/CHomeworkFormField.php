<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Action\NotFoundAction;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Chamilo\CourseBundle\Repository\CHomeworkFormFieldRepository;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Table(name: 'c_homework_form_field')]
#[ORM\Entity(repositoryClass: CHomeworkFormFieldRepository::class)]
#[ApiResource(operations: [new Get(controller: NotFoundAction::class, output: false, read: false)])]
class CHomeworkFormField implements Stringable
{
    public const TYPE_TEXT = 1;
    public const TYPE_TEXTAREA = 2;
    public const TYPE_NUMBER = 3;
    public const TYPE_DATE = 4;
    public const TYPE_SELECT = 5;
    public const TYPE_CHECKBOX = 6;
    public const TYPE_FILE = 7;

    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[Assert\Choice(choices: [self::TYPE_TEXT, self::TYPE_TEXTAREA, self::TYPE_NUMBER, self::TYPE_DATE, self::TYPE_SELECT, self::TYPE_CHECKBOX, self::TYPE_FILE])]
    #[ORM\Column(name: 'type', type: 'smallint', nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected int $type;

    #[Assert\NotBlank]
    #[ORM\Column(name: 'label', type: 'string', length: 255, nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected string $label = '';

    #[ORM\Column(name: 'help_text', type: 'text', nullable: true)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected ?string $helpText = null;

    #[ORM\Column(name: 'required', type: 'boolean', nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected bool $required = false;

    /** @var string[]|null */
    #[ORM\Column(name: 'options', type: 'json', nullable: true)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected ?array $options = null;

    // TYPE_TEXTAREA only: visible height in text rows. Null falls back to
    // HomeworkSubmit.vue's own default (see DEFAULT_TEXTAREA_ROWS there).
    // Physical column is "textarea_rows", not "rows" - the latter is a
    // reserved word in MySQL/MariaDB.
    #[Assert\Range(min: 2, max: 30)]
    #[ORM\Column(name: 'textarea_rows', type: 'smallint', nullable: true)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected ?int $rows = null;

    #[ORM\Column(name: 'sort_order', type: 'integer', nullable: false)]
    #[Groups(['homework_form:write', 'homework_form:read'])]
    protected int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: CHomeworkFormPage::class, inversedBy: 'fields')]
    #[ORM\JoinColumn(name: 'page_id', referencedColumnName: 'iid', onDelete: 'CASCADE')]
    protected CHomeworkFormPage $page;

    public function __toString(): string
    {
        return $this->label;
    }

    public function getIid(): ?int
    {
        return $this->iid;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function setType(int $type): self
    {
        $this->type = $type;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getHelpText(): ?string
    {
        return $this->helpText;
    }

    public function setHelpText(?string $helpText): self
    {
        $this->helpText = $helpText;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): self
    {
        $this->required = $required;

        return $this;
    }

    /** @return string[]|null */
    public function getOptions(): ?array
    {
        return $this->options;
    }

    public function setOptions(?array $options): self
    {
        $this->options = $options;

        return $this;
    }

    public function getRows(): ?int
    {
        return $this->rows;
    }

    public function setRows(?int $rows): self
    {
        $this->rows = $rows;

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

    public function getPage(): CHomeworkFormPage
    {
        return $this->page;
    }

    public function setPage(CHomeworkFormPage $page): self
    {
        $this->page = $page;

        return $this;
    }
}

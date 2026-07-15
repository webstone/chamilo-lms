<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CourseBundle\Entity;

use ApiPlatform\Action\NotFoundAction;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use Chamilo\CourseBundle\Repository\CHomeworkSubmissionAnswerRepository;
use Doctrine\ORM\Mapping as ORM;
use Stringable;
use Symfony\Component\Serializer\Attribute\Groups;

#[ORM\Table(name: 'c_homework_submission_answer')]
#[ORM\Entity(repositoryClass: CHomeworkSubmissionAnswerRepository::class)]
#[ApiResource(operations: [new Get(controller: NotFoundAction::class, output: false, read: false)])]
class CHomeworkSubmissionAnswer implements Stringable
{
    #[ORM\Column(name: 'iid', type: 'integer')]
    #[ORM\Id]
    #[ORM\GeneratedValue]
    protected ?int $iid = null;

    #[ORM\ManyToOne(targetEntity: CHomeworkSubmission::class, inversedBy: 'answers')]
    #[ORM\JoinColumn(name: 'submission_id', referencedColumnName: 'iid', nullable: false, onDelete: 'CASCADE')]
    protected CHomeworkSubmission $submission;

    #[ORM\ManyToOne(targetEntity: CHomeworkFormField::class)]
    #[ORM\JoinColumn(name: 'field_id', referencedColumnName: 'iid', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected CHomeworkFormField $field;

    #[ORM\Column(name: 'value', type: 'text', nullable: true)]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected ?string $value = null;

    #[ORM\ManyToOne(targetEntity: CDocument::class)]
    #[ORM\JoinColumn(name: 'file_document_id', referencedColumnName: 'iid', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['homework_submission:write', 'homework_submission:read'])]
    protected ?CDocument $fileDocument = null;

    public function __toString(): string
    {
        return (string) $this->iid;
    }

    public function getIid(): ?int
    {
        return $this->iid;
    }

    public function getSubmission(): CHomeworkSubmission
    {
        return $this->submission;
    }

    public function setSubmission(CHomeworkSubmission $submission): self
    {
        $this->submission = $submission;

        return $this;
    }

    public function getField(): CHomeworkFormField
    {
        return $this->field;
    }

    public function setField(CHomeworkFormField $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function getValue(): ?string
    {
        return $this->value;
    }

    public function setValue(?string $value): self
    {
        $this->value = $value;

        return $this;
    }

    public function getFileDocument(): ?CDocument
    {
        return $this->fileDocument;
    }

    public function setFileDocument(?CDocument $fileDocument): self
    {
        $this->fileDocument = $fileDocument;

        return $this;
    }
}

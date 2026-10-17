<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\User;
use Chamilo\CourseBundle\Entity\CHomeworkForm;
use Chamilo\CourseBundle\Entity\CHomeworkFormField;
use Chamilo\CourseBundle\Entity\CHomeworkFormPage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deep-copies a CHomeworkForm (its pages and fields) into a brand new
 * CHomeworkForm, so a teacher can edit a copy instead of the original -
 * HomeworkFormBuilder.vue's PUT is destructive (it replaces every page/field
 * of the form being edited), which is unsafe once a form is already in use
 * by submissions. The original's rows, and any CHomeworkSubmissionAnswer
 * pointing at its fields, are never touched - only new rows are created.
 */
readonly class DuplicateHomeworkFormAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private Security $security,
        private TranslatorInterface $translator,
    ) {}

    public function __invoke(CHomeworkForm $data): CHomeworkForm
    {
        $original = $data;

        $copy = (new CHomeworkForm())
            ->setTitle($this->translator->trans('%s (copy)', ['%s' => $original->getTitle()]))
        ;

        $creator = $this->security->getUser();
        if ($creator instanceof User) {
            $copy->setCreator($creator);
        }

        // addCourseLink() requires setParent() (a ResourceInterface, not just
        // a raw node id) to already be set - mirrors CHomeworkFormRepositoryTest's
        // working fixture pattern (parent the copy directly under the Course,
        // same as a freshly-created form would be), rather than trying to
        // replicate the original's exact resource_node depth.
        $links = $original->getResourceNode()?->getResourceLinks() ?? [];
        foreach ($links as $link) {
            $course = $link->getCourse();
            if (null === $course) {
                continue;
            }

            if (null === $copy->getParent()) {
                $copy->setParent($course);
            }

            $copy->addCourseLink($course, $link->getSession(), $link->getGroup(), $link->getVisibility());
        }

        foreach ($original->getPages() as $page) {
            $newPage = (new CHomeworkFormPage())
                ->setTitle($page->getTitle())
                ->setSortOrder($page->getSortOrder())
            ;

            foreach ($page->getFields() as $field) {
                $newField = (new CHomeworkFormField())
                    ->setType($field->getType())
                    ->setLabel($field->getLabel())
                    ->setHelpText($field->getHelpText())
                    ->setRequired($field->isRequired())
                    ->setOptions($field->getOptions())
                    ->setRows($field->getRows())
                    ->setSortOrder($field->getSortOrder())
                ;
                $newPage->addField($newField);
            }

            $copy->addPage($newPage);
        }

        $this->entityManager->persist($copy);
        $this->entityManager->flush();

        return $copy;
    }
}

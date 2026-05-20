<?php

declare(strict_types=1);

/* For licensing terms, see /license.txt */

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\CourseCategory;
use Chamilo\CoreBundle\Helpers\AccessUrlHelper;
use Doctrine\ORM\EntityManagerInterface;

/**
 * @implements ProcessorInterface<CourseCategory, CourseCategory>
 *
 * Ensures every CourseCategory created or updated via the API Platform endpoint
 * has a row in access_url_rel_course_category. Without this, categories POSTed
 * to /api/course_categories are invisible to subsequent filtered reads on the
 * same access URL. The Patch wiring also self-heals categories created before
 * this processor existed.
 */
final readonly class CourseCategoryStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private AccessUrlHelper $accessUrlHelper,
        private EntityManagerInterface $entityManager,
    ) {}

    public function process($data, Operation $operation, array $uriVariables = [], array $context = []): CourseCategory
    {
        \assert($data instanceof CourseCategory);

        /** @var CourseCategory $category */
        $category = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $accessUrl = $this->accessUrlHelper->getCurrent();
        if ($accessUrl !== null && $category->getUrls()->isEmpty()) {
            $category->addUrl($accessUrl);
            $this->entityManager->flush();
        }

        return $category;
    }
}

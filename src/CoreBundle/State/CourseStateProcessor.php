<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Helpers\AccessUrlHelper;
use Doctrine\ORM\EntityManagerInterface;

final readonly class CourseStateProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private AccessUrlHelper $accessUrlHelper,
        private EntityManagerInterface $entityManager,
    ) {}

    public function process($data, Operation $operation, array $uriVariables = [], array $context = []): Course
    {
        \assert($data instanceof Course);

        $course = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        $accessUrl = $this->accessUrlHelper->getCurrent();
        if ($accessUrl === null) {
            return $course;
        }

        // Course has no hasAccessUrl() helper --- iterate $urls and compare ids.
        $alreadyLinked = false;
        foreach ($course->getUrls() as $rel) {
            $linkedUrl = $rel->getUrl();
            if ($linkedUrl !== null && $linkedUrl->getId() === $accessUrl->getId()) {
                $alreadyLinked = true;
                break;
            }
        }

        if (!$alreadyLinked) {
            $course->addAccessUrl($accessUrl);
            $this->entityManager->flush();
        }

        return $course;
    }
}

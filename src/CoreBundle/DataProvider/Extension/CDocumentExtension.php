<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\DataProvider\Extension;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Chamilo\CourseBundle\Entity\CDocument;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * NOTE: effectively unreachable for CDocument today. CDocument's only
 * GetCollection operation declares its own `provider:` (see
 * DocumentCollectionStateProvider), which replaces API Platform's default
 * CollectionProvider entirely - the one that would normally invoke every
 * registered QueryCollectionExtensionInterface, this one included. Row-level
 * scoping for that operation (course/session/group/visibility, plus the
 * user-scoped-document ownership check) lives directly in
 * DocumentCollectionStateProvider::provide() instead. Left in place (rather
 * than deleted) only because removing it is out of scope for the change that
 * prompted this note; do not extend or rely on this class for CDocument
 * without first confirming it actually runs for whatever operation you're
 * touching.
 */
final class CDocumentExtension implements QueryCollectionExtensionInterface
{
    use CourseLinkExtensionTrait;

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        if (CDocument::class !== $resourceClass) {
            return;
        }

        if (null === $this->security->getUser()) {
            throw new AccessDeniedException('Access Denied.');
        }

        $request = $this->requestStack->getCurrentRequest();

        // Listing documents must contain the resource node parent (resourceNode.parent) and the course (cid)
        // At least the cid so the CidReqListener can be called.
        $resourceParentId = $request->query->get('resourceNode_parent');
        $courseId = $request->query->getInt('cid');

        if (empty($resourceParentId)) {
            throw new AccessDeniedException('resourceNode.parent is required');
        }

        if (empty($courseId)) {
            throw new AccessDeniedException('cid is required');
        }

        $this->addCourseLinkWithVisibilityConditions($queryBuilder, true);
    }
}

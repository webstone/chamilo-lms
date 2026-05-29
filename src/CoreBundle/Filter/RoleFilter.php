<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;

/**
 * Filters API users by one or more security roles.
 *
 * The `roles` column is a JSON-encoded array; we match with LIKE on
 * the quoted role token (e.g. '%"ROLE_TEACHER"%').
 *
 * Usage:
 *   /api/users?roles=ROLE_TEACHER
 *   /api/users?roles[]=ROLE_TEACHER&roles[]=ROLE_STUDENT  (OR-combined)
 *
 * Admin-role variants ({PLATFORM_,GLOBAL_,}ADMIN, with or without the
 * ROLE_ prefix) are normalised to ROLE_ADMIN OR ROLE_GLOBAL_ADMIN, matching
 * the behaviour of UserListController::list().
 */
final class RoleFilter extends AbstractFilter
{
    private const ADMIN_VARIANTS = [
        'ROLE_PLATFORM_ADMIN', 'PLATFORM_ADMIN',
        'ROLE_GLOBAL_ADMIN', 'GLOBAL_ADMIN',
        'ROLE_ADMIN', 'ADMIN',
    ];

    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if (!$this->isPropertyEnabled($property, $resourceClass)) {
            return;
        }

        // Accept ?roles=X and ?roles[]=X&roles[]=Y alike.
        $values = \is_array($value) ? $value : [$value];
        $values = array_values(array_filter(
            array_map(
                static fn ($v): string => \is_string($v) ? strtoupper(trim($v)) : '',
                $values
            ),
            static fn (string $v): bool => '' !== $v
        ));

        if ([] === $values) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $expr = $queryBuilder->expr();
        $ors = [];

        foreach ($values as $role) {
            if (\in_array($role, self::ADMIN_VARIANTS, true)) {
                $paramA = $queryNameGenerator->generateParameterName('role');
                $paramB = $queryNameGenerator->generateParameterName('role');
                $ors[] = $expr->orX(
                    $expr->like("$alias.$property", ":$paramA"),
                    $expr->like("$alias.$property", ":$paramB")
                );
                $queryBuilder
                    ->setParameter($paramA, '%"ROLE_ADMIN"%')
                    ->setParameter($paramB, '%"ROLE_GLOBAL_ADMIN"%')
                ;

                continue;
            }

            // Refuse arbitrary input — only well-formed ROLE_* tokens.
            if (!preg_match('/^ROLE_[A-Z][A-Z0-9_]*$/', $role)) {
                continue;
            }

            $param = $queryNameGenerator->generateParameterName('role');
            $ors[] = $expr->like("$alias.$property", ":$param");
            $queryBuilder->setParameter($param, '%"'.$role.'"%');
        }

        if ([] !== $ors) {
            $queryBuilder->andWhere($expr->orX(...$ors));
        }
    }

    public function getDescription(string $resourceClass): array
    {
        $description = [];

        foreach (array_keys($this->properties ?? []) as $property) {
            $description[$property] = [
                'property' => $property,
                'type' => 'string',
                'required' => false,
                'description' => \sprintf(
                    'Filter %s by one or more security roles. Repeat the parameter to OR-combine (e.g. ?%s[]=ROLE_TEACHER&%s[]=ROLE_STUDENT). Admin variants (PLATFORM_ADMIN, GLOBAL_ADMIN, ADMIN) are normalised to ROLE_ADMIN OR ROLE_GLOBAL_ADMIN.',
                    $resourceClass,
                    $property,
                    $property
                ),
                'openapi' => [
                    'example' => 'ROLE_TEACHER',
                ],
            ];
            $description[$property.'[]'] = [
                'property' => $property,
                'type' => 'string',
                'required' => false,
                'is_collection' => true,
                'description' => 'OR-combined list of roles.',
            ];
        }

        return $description;
    }
}

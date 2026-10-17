<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\ApiResource;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use Chamilo\CoreBundle\State\HomeworkAssignmentProgressStateProvider;

#[ApiResource(
    shortName: 'HomeworkAssignmentProgress',
    operations: [
        new GetCollection(
            uriTemplate: '/homework_assignments_progress',
            openapi: new Operation(
                summary: 'Per-student on-time/total report counts for a multi-report Homework assignment',
                parameters: [
                    new Parameter(name: 'assignmentId', in: 'query', required: true, schema: ['type' => 'integer']),
                ],
            ),
            paginationEnabled: false,
            security: "is_granted('ROLE_CURRENT_COURSE_TEACHER') or is_granted('ROLE_CURRENT_COURSE_SESSION_TEACHER')",
            name: 'homework_assignments_progress',
            provider: HomeworkAssignmentProgressStateProvider::class,
        ),
    ],
)]
final class HomeworkAssignmentProgress
{
    public function __construct(
        public int $userId,
        public string $userFullName,
        public int $onTimeCount,
        public int $totalCount,
        public ?int $requiredCount,
    ) {}

    #[ApiProperty(readable: false, writable: false, identifier: true)]
    public function getId(): string
    {
        return 'homework-assignment-progress-'.$this->userId;
    }
}

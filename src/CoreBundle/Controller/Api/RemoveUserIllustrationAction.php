<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Repository\Node\IllustrationRepository;
use Symfony\Component\HttpFoundation\Response;

final class RemoveUserIllustrationAction
{
    public function __construct(
        private readonly IllustrationRepository $illustrationRepository,
    ) {}

    public function __invoke(User $data): Response
    {
        $this->illustrationRepository->deleteIllustration($data);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}

<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Controller\Api;

use Chamilo\CoreBundle\Entity\Course;
use Chamilo\CoreBundle\Entity\User;
use Chamilo\CoreBundle\Repository\Node\IllustrationRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class SetCourseIllustrationAction
{
    public function __construct(
        private readonly IllustrationRepository $illustrationRepository,
        private readonly Security $security,
    ) {}

    public function __invoke(Request $request, Course $data): Course
    {
        $uploadedFile = $request->files->get('uploadFile');
        if ($uploadedFile === null) {
            throw new BadRequestHttpException('"uploadFile" is required');
        }

        $creator = $this->security->getUser();
        if (!$creator instanceof User) {
            throw new BadRequestHttpException('Authenticated user required');
        }

        // Replace-semantics: addIllustration on an existing ResourceNode appends a
        // ResourceFile rather than replacing the previous one, and the illustration
        // URL stays pinned to the original node UUID (so clients keep seeing the old
        // image). Delete the existing illustration first to guarantee a fresh node,
        // a single file, and a new URL on every upload. Idempotent if none exists.
        $this->illustrationRepository->deleteIllustration($data);
        $this->illustrationRepository->addIllustration($data, $creator, $uploadedFile);

        return $data;
    }
}

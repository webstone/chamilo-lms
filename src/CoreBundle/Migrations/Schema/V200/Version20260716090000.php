<?php

/* For licensing terms, see /license.txt */

declare(strict_types=1);

namespace Chamilo\CoreBundle\Migrations\Schema\V200;

use Chamilo\CoreBundle\Entity\ResourceLink;
use Chamilo\CoreBundle\Entity\Tool;
use Chamilo\CoreBundle\Migrations\AbstractMigrationChamilo;
use Chamilo\CoreBundle\Tool\ToolChain;
use Chamilo\CourseBundle\Entity\CTool;
use Doctrine\DBAL\Schema\Schema;

/**
 * Fixes Version20260715163000: that migration assumed the "huiswerk" Tool
 * row already existed (it's normally seeded by ToolChain::createTools(),
 * called by several earlier migrations for their own new tools - e.g.
 * Version20250928100200 for "blog" - a step this one should have included
 * too but didn't). On any environment where createTools() hadn't run yet by
 * the time Version20260715163000 executed, that migration's own guard
 * silently skipped the whole backfill (it still marked itself as
 * successfully executed, so a plain re-run via `doctrine:migrations:migrate`
 * does nothing).
 *
 * This migration calls createTools() first (idempotent - safe even if it
 * already ran), then repeats the exact same backfill Version20260715163000
 * performed. On an environment where the original migration already
 * succeeded, every course already has the tool, so this is a no-op.
 */
final class Version20260716090000 extends AbstractMigrationChamilo
{
    public function getDescription(): string
    {
        return 'Seeds the tool catalog (ToolChain::createTools()) and re-runs the huiswerk backfill from Version20260715163000, for environments where that migration silently no-op\'d because the tool catalog wasn\'t seeded yet.';
    }

    public function up(Schema $schema): void
    {
        /** @var ToolChain $toolChain */
        $toolChain = $this->container->get(ToolChain::class);
        $toolChain->createTools();

        $em = $this->entityManager;
        $huiswerkTool = $em->getRepository(Tool::class)->findOneBy(['title' => 'huiswerk']);

        if (!$huiswerkTool) {
            error_log('[MIGRATION] Tool "huiswerk" still not found after createTools() - the Homework module code itself may not be deployed. Skipping backfill.');

            return;
        }

        $courseIds = $this->connection->fetchFirstColumn('SELECT id FROM course ORDER BY id');
        $added = 0;

        foreach ($courseIds as $rawCourseId) {
            $courseId = (int) $rawCourseId;

            $alreadyHasIt = $this->connection->fetchOne(
                'SELECT 1 FROM c_tool WHERE c_id = ? AND title = ? LIMIT 1',
                [$courseId, 'huiswerk']
            );
            if (false !== $alreadyHasIt) {
                continue;
            }

            $course = $this->findCourse($courseId);
            if (!$course) {
                continue;
            }

            $courseTool = (new CTool())
                ->setTool($huiswerkTool)
                ->setTitle('huiswerk')
                ->setParent($course)
                ->setCreator($course->getCreator())
                ->addCourseLink($course, null, null, ResourceLink::VISIBILITY_DRAFT)
            ;
            $course->addTool($courseTool);
            $em->persist($courseTool);
            $em->flush();

            $dropboxPosition = $this->connection->fetchOne(
                'SELECT position FROM c_tool WHERE c_id = ? AND title = ? LIMIT 1',
                [$courseId, 'dropbox']
            );
            $targetPosition = false !== $dropboxPosition
                ? (int) $dropboxPosition + 1
                : (int) $this->connection->fetchOne(
                    'SELECT COALESCE(MIN(position), 0) FROM c_tool WHERE c_id = ? AND title = ?',
                    [$courseId, 'huiswerk']
                );

            if (false !== $dropboxPosition) {
                $this->connection->executeStatement(
                    'UPDATE c_tool SET position = position + 1 WHERE c_id = ? AND title != ? AND position >= ? ORDER BY position DESC',
                    [$courseId, 'huiswerk', $targetPosition]
                );
                $this->connection->executeStatement(
                    'UPDATE c_tool SET position = ? WHERE c_id = ? AND title = ?',
                    [$targetPosition, $courseId, 'huiswerk']
                );
            }

            ++$added;
        }

        error_log("[MIGRATION] Added huiswerk tool to {$added} course(s) (catch-up run).");
    }

    public function down(Schema $schema): void
    {
        // Same rationale as Version20260715163000: not safely reversible.
    }
}

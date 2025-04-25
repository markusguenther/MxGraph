<?php

namespace Sandstorm\MxGraph\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\Factory\CommandHookFactoryInterface;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Psr\Log\LoggerInterface;

class DiagramCommandHookFactory implements CommandHookFactoryInterface
{
    public function __construct(protected LoggerInterface $logger)
    {
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        return new DiagramCommandHook(
            $commandHooksFactoryDependencies->contentRepositoryId,
            $commandHooksFactoryDependencies->contentGraphReadModel,
            $this->logger,
        );
    }
}

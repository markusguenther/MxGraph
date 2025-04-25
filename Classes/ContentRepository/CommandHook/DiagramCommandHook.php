<?php

namespace Sandstorm\MxGraph\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingDirection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\TimestampField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEquals;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Psr\Log\LoggerInterface;

class DiagramCommandHook implements CommandHookInterface {
    public function __construct(
        protected ContentRepositoryId $contentRepositoryId,
        protected ContentGraphReadModelInterface $contentGraphReadModel,
        protected LoggerInterface $logger,
    )
    {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        if ($command instanceof SetNodeProperties) {
            $subgraph = $this->contentGraphReadModel
                ->getContentGraph($command->workspaceName)
                ->getSubgraph($command->originDimensionSpacePoint->toDimensionSpacePoint(), VisibilityConstraints::default());

            $sourceNode = $subgraph->findNodeById($command->nodeAggregateId);

            if ($sourceNode === null) {
                return Commands::createEmpty();
            }

            if (!$sourceNode->nodeTypeName->equals(NodeTypeName::fromString('Sandstorm.MxGraph:Diagram'))) {
                return Commands::createEmpty();
            }

            // diagramIdentifier was updated -> copy over the latest changes into this node
            if (array_key_exists('diagramIdentifier', $command->propertyValues->values)) {
                return $this->handleDiagramIdentifierChange($command, $subgraph);
            }

            // diagram data was updated -> update all diagrams with the same diagramIdentifier
            if (
                array_key_exists('diagramSvgText', $command->propertyValues->values)
                && array_key_exists('diagramSource', $command->propertyValues->values)
            ) {
                return $this->handleDiagramDataChange($command, $subgraph);
            }
        }

        return Commands::createEmpty();
    }

    private function handleDiagramIdentifierChange(SetNodeProperties $command, ContentSubgraphInterface $subgraph): Commands
    {
        $diagramIdentifier = $command->propertyValues->values['diagramIdentifier'];
        $this->logger->info("DiagramCommandHook::handleDiagramIdentifierChange for '$diagramIdentifier'");

        // ignore empty diagramIdentifier
        if ($diagramIdentifier === null || $diagramIdentifier === '') {
            return Commands::createEmpty();
        }

        // get diagram with the latest changes
        $siteNode = $subgraph->findClosestNode(
            $command->nodeAggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );

        $diagramNodesOrdered = $subgraph->findDescendantNodes(
            $siteNode->aggregateId,
            FindDescendantNodesFilter::create(
                nodeTypes: 'Sandstorm.MxGraph:Diagram',
                propertyValue: PropertyValueEquals::create(PropertyName::fromString('diagramIdentifier'), $diagramIdentifier, true),
                ordering: [
                    OrderingField::byTimestampField(TimestampField::LAST_MODIFIED, OrderingDirection::DESCENDING),
                    OrderingField::byTimestampField(TimestampField::CREATED, OrderingDirection::DESCENDING),
                ]
            ),
        );

        $diagramWithLatestChange = $diagramNodesOrdered->first();

        if ($diagramWithLatestChange === null) {
            return Commands::createEmpty();
        }

        // TODO: does this trigger a new hook with that ends in an infinitive loop?
        return Commands::create(SetNodeProperties::create(
            workspaceName: $command->workspaceName,
            nodeAggregateId: $command->nodeAggregateId,
            originDimensionSpacePoint: $command->originDimensionSpacePoint,
            propertyValues: PropertyValuesToWrite::fromArray([
                'diagramSvgText' => $diagramWithLatestChange->getProperty('diagramSvgText'),
                'diagramSource' => $diagramWithLatestChange->getProperty('diagramSource'),
            ])
        ));
    }

    private function handleDiagramDataChange(SetNodeProperties $command, ContentSubgraphInterface $subgraph): Commands
    {
        // get all diagrams with the same diagramIdentifier
        $siteNode = $subgraph->findClosestNode(
            $command->nodeAggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );

        $sourceNode = $subgraph->findNodeById($command->nodeAggregateId);
        $diagramIdentifier = $sourceNode->getProperty('diagramIdentifier');

        $this->logger->info("DiagramCommandHook::handleDiagramDataChange for '$diagramIdentifier");

        $nodesWithDiagramIdentifier = $subgraph->findDescendantNodes(
            $siteNode->aggregateId,
            FindDescendantNodesFilter::create(
                nodeTypes: 'Sandstorm.MxGraph:Diagram',
                propertyValue: PropertyValueEquals::create(
                    PropertyName::fromString('diagramIdentifier'),
                    $diagramIdentifier,
                    true
                ),
            )
        );

        $commands = Commands::createEmpty();

        foreach ($nodesWithDiagramIdentifier as $diagramNode) {
            if ($diagramNode->aggregateId->equals($command->nodeAggregateId)) {
                // ignore source node of the change
                continue;
            }

            // TODO: does this create a infinite loop because we handle a new change?
            $commands->append(SetNodeProperties::create(
                workspaceName: $diagramNode->workspaceName,
                nodeAggregateId: $diagramNode->aggregateId,
                originDimensionSpacePoint: $diagramNode->originDimensionSpacePoint,
                propertyValues: PropertyValuesToWrite::fromArray([
                    'diagramSvgText' => $sourceNode->getProperty('diagramSvgText'),
                    'diagramSource' => $sourceNode->getProperty('diagramSource'),
                ])
            ));
        }

        return $commands;
    }
}

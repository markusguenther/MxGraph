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
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\AndCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\NegateCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEquals;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Psr\Log\LoggerInterface;

final class DiagramCommandHook implements CommandHookInterface {
    private const DIAGRAM_NODE_TYPE_NAME = 'Sandstorm.MxGraph:Diagram';
    private const DIAGRAM_IDENTIFIER_PROPERTY_NAME = 'diagramIdentifier';
    private const DIAGRAM_SVG_TEXT_PROPERTY_NAME = 'diagramSvgText';
    private const DIAGRAM_SOURCE_PROPERTY_NAME = 'diagramSource';

    public function __construct(
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly LoggerInterface                $logger,
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
                ->getSubgraph(
                    $command->originDimensionSpacePoint->toDimensionSpacePoint(),
                    VisibilityConstraints::default()
                );

            $sourceNode = $subgraph->findNodeById($command->nodeAggregateId);

            if ($sourceNode === null) {
                return Commands::createEmpty();
            }

            if (!$sourceNode->nodeTypeName->equals(NodeTypeName::fromString(self::DIAGRAM_NODE_TYPE_NAME))) {
                return Commands::createEmpty();
            }

            // diagramIdentifier was updated -> copy over the latest changes into this node
            if (array_key_exists(self::DIAGRAM_IDENTIFIER_PROPERTY_NAME, $command->propertyValues->values)) {
                return $this->handleDiagramIdentifierChange($command, $subgraph);
            }

            // diagram data was updated -> update all diagrams with the same diagramIdentifier
            if (
                array_key_exists(self::DIAGRAM_SVG_TEXT_PROPERTY_NAME, $command->propertyValues->values)
                && array_key_exists(self::DIAGRAM_SOURCE_PROPERTY_NAME, $command->propertyValues->values)
            ) {
                return $this->handleDiagramDataChange($command, $subgraph);
            }
        }

        return Commands::createEmpty();
    }

    private function handleDiagramIdentifierChange(SetNodeProperties $command, ContentSubgraphInterface $subgraph): Commands
    {
        $diagramIdentifier = $command->propertyValues->values[self::DIAGRAM_IDENTIFIER_PROPERTY_NAME];

        $this->logger->debug("DiagramCommandHook::handleDiagramIdentifierChange: Diagram node '$command->nodeAggregateId' changed property nodeIdentifier to '$diagramIdentifier'");

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
                nodeTypes: self::DIAGRAM_NODE_TYPE_NAME,
                propertyValue: PropertyValueEquals::create(
                    PropertyName::fromString(self::DIAGRAM_IDENTIFIER_PROPERTY_NAME), $diagramIdentifier, true
                ),
                ordering: [
                    OrderingField::byTimestampField(TimestampField::LAST_MODIFIED, OrderingDirection::DESCENDING),
                    OrderingField::byTimestampField(TimestampField::CREATED, OrderingDirection::DESCENDING),
                ]
            ),
        );

        $diagramWithLatestChange = null;

        // get first node that is not the node the command executes on
        foreach ($diagramNodesOrdered as $node) {
            if ($node->aggregateId->equals($command->nodeAggregateId)) {
                continue;
            }

            $diagramWithLatestChange = $node;
            break;
        }

        if ($diagramWithLatestChange === null) {
            return Commands::createEmpty();
        }

        return Commands::create(SetNodeProperties::create(
            workspaceName: $command->workspaceName,
            nodeAggregateId: $command->nodeAggregateId,
            originDimensionSpacePoint: $command->originDimensionSpacePoint,
            propertyValues: PropertyValuesToWrite::fromArray([
                self::DIAGRAM_SVG_TEXT_PROPERTY_NAME => $diagramWithLatestChange->getProperty(self::DIAGRAM_SVG_TEXT_PROPERTY_NAME),
                self::DIAGRAM_SOURCE_PROPERTY_NAME => $diagramWithLatestChange->getProperty(self::DIAGRAM_SOURCE_PROPERTY_NAME),
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
        $diagramIdentifier = $sourceNode->getProperty(self::DIAGRAM_IDENTIFIER_PROPERTY_NAME);

        $this->logger->debug("DiagramCommandHook::handleDiagramDataChange: Diagram node '$command->nodeAggregateId' with diagramIdentifier '$diagramIdentifier' updated it's data");

        $nodesWithDiagramIdentifier = $subgraph->findDescendantNodes(
            $siteNode->aggregateId,
            FindDescendantNodesFilter::create(
                nodeTypes: self::DIAGRAM_NODE_TYPE_NAME,
                propertyValue: AndCriteria::create(
                    PropertyValueEquals::create(
                        PropertyName::fromString(self::DIAGRAM_IDENTIFIER_PROPERTY_NAME),
                        $diagramIdentifier,
                        true
                    ),
                    NegateCriteria::create(
                        AndCriteria::create(
                            PropertyValueEquals::create(
                                PropertyName::fromString(self::DIAGRAM_SVG_TEXT_PROPERTY_NAME),
                                $sourceNode->getProperty(self::DIAGRAM_SVG_TEXT_PROPERTY_NAME),
                                true
                            ),
                            PropertyValueEquals::create(
                                PropertyName::fromString(self::DIAGRAM_SOURCE_PROPERTY_NAME),
                                $sourceNode->getProperty(self::DIAGRAM_SOURCE_PROPERTY_NAME),
                                true
                            ),
                        ),
                    )
                ),
            )
        );

        $commands = Commands::createEmpty();

        $propertiesToCopyFromSourceNode = PropertyValuesToWrite::fromArray([
            self::DIAGRAM_SVG_TEXT_PROPERTY_NAME => $sourceNode->getProperty(self::DIAGRAM_SVG_TEXT_PROPERTY_NAME),
            self::DIAGRAM_SOURCE_PROPERTY_NAME => $sourceNode->getProperty(self::DIAGRAM_SOURCE_PROPERTY_NAME),
        ]);

        foreach ($nodesWithDiagramIdentifier as $diagramNode) {
            if ($diagramNode->aggregateId->equals($command->nodeAggregateId)) {
                // ignore source node of the change
                continue;
            }

            $this->logger->debug("DiagramCommandHook::handleDiagramDataChange: -> Also trigger data update for related diagram node: '$diagramNode->aggregateId");

            $commands = $commands->append(SetNodeProperties::create(
                workspaceName: $diagramNode->workspaceName,
                nodeAggregateId: $diagramNode->aggregateId,
                originDimensionSpacePoint: $diagramNode->originDimensionSpacePoint,
                propertyValues: $propertiesToCopyFromSourceNode,
            ));
        }

        return $commands;
    }
}

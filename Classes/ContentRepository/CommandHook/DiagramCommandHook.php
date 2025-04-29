<?php

namespace Sandstorm\MxGraph\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingDirection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\OrderingField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\Ordering\TimestampField;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueEquals;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;
use Psr\Log\LoggerInterface;
use Sandstorm\MxGraph\MxGraphConstants;

final class DiagramCommandHook implements CommandHookInterface {
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

            if (!$sourceNode->nodeTypeName->equals(NodeTypeName::fromString(MxGraphConstants::getNodeTypeName()))) {
                return Commands::createEmpty();
            }

            // diagramIdentifier was updated -> copy over the latest changes into this node
            // TODO: a CommandInstance->hasValue(PropertyName $propertyName): bool API would be nice
            if (array_key_exists(MxGraphConstants::getDiagramIdentifierPropertyName()->value, $command->propertyValues->values)) {
                return $this->handleDiagramIdentifierChange($command, $subgraph);
            }
        }

        return Commands::createEmpty();
    }

    // TODO: use DiagramContentRepositoryService
    private function handleDiagramIdentifierChange(SetNodeProperties $command, ContentSubgraphInterface $subgraph): Commands
    {
        $diagramIdentifier = $command->propertyValues->values[MxGraphConstants::getDiagramIdentifierPropertyName()->value];

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
                nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(NodeTypeNames::with(MxGraphConstants::getNodeTypeName())),
                propertyValue: PropertyValueEquals::create(
                    propertyName: MxGraphConstants::getDiagramIdentifierPropertyName(),
                    value: $diagramIdentifier,
                    caseSensitive: true,
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

        $diagramSourcePropertyName = MxGraphConstants::getDiagramSourcePropertyName();
        $diagramSvgTextPropertyName = MxGraphConstants::getDiagramSvgTextPropertyName();

        return Commands::create(SetNodeProperties::create(
            workspaceName: $command->workspaceName,
            nodeAggregateId: $command->nodeAggregateId,
            originDimensionSpacePoint: $command->originDimensionSpacePoint,
            propertyValues: PropertyValuesToWrite::fromArray([
                $diagramSourcePropertyName->value => $diagramWithLatestChange->getProperty($diagramSourcePropertyName),
                $diagramSvgTextPropertyName->value => $diagramWithLatestChange->getProperty($diagramSvgTextPropertyName),
            ])
        ));
    }
}

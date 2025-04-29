<?php

namespace Sandstorm\MxGraph;

use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\PropertyValue\Criteria\PropertyValueContains;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Neos\Domain\Service\NodeTypeNameFactory;

#[Flow\Scope('singleton')]
class DiagramIdentifierSearchService
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    /**
     * @return string[]
     * @throws AccessDenied
     */
    public function findInIdentifier(string $searchTerm, Node $node): array
    {
        $searchTerm = trim($searchTerm);
        $diagramIdentifierPropertyName = PropertyName::fromString('diagramIdentifier');
        $diagramNodeTypeName = NodeTypeName::fromString('Sandstorm.MxGraph:Diagram');

        $results = [];

        $contentRepository = $this->contentRepositoryRegistry->get($node->contentRepositoryId);
        $subgraph = $contentRepository->getContentSubgraph(
            $node->workspaceName,
            $node->dimensionSpacePoint
        );
        $siteNode = $subgraph->findClosestNode(
            $node->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );

        $possibleResults = $subgraph->findDescendantNodes(
            $siteNode->aggregateId,
            FindDescendantNodesFilter::create(
                nodeTypes: $diagramNodeTypeName,
                propertyValue: PropertyValueContains::create($diagramIdentifierPropertyName, $searchTerm, false),
            ),
        );

        foreach ($possibleResults as $possibleResult) {
            assert($possibleResult instanceof Node);
            $possibleDiagramIdentifier = $possibleResult->getProperty($diagramIdentifierPropertyName);
            if (!isset($results[$possibleDiagramIdentifier])) {
                assert(is_string($possibleDiagramIdentifier));
                $results[$possibleDiagramIdentifier] = $possibleDiagramIdentifier;
            }
        }

        return array_values($results);
    }

    /**
     * @return Node[]
     * @throws AccessDenied
     */
    public function findRelatedDiagramsWithIdentifierExcludingOwn(string $diagramIdentifier, Node $contextNode): array
    {
        $diagramIdentifierPropertyName = PropertyName::fromString('diagramIdentifier');
        $diagramNodeTypeName = NodeTypeName::fromString('Sandstorm.MxGraph:Diagram');
        $results = [];

        $contentRepository = $this->contentRepositoryRegistry->get($contextNode->contentRepositoryId);
        $subgraph = $contentRepository->getContentGraph($contextNode->workspaceName)->getSubgraph(
            $contextNode->dimensionSpacePoint,
            VisibilityConstraints::default()
        );
        $siteNode = $subgraph->findClosestNode(
            $contextNode->aggregateId,
            FindClosestNodeFilter::create(nodeTypes: NodeTypeNameFactory::NAME_SITE)
        );

        $propertyConstraint = PropertyValueContains::create($diagramIdentifierPropertyName, $diagramIdentifier, true);
        $possibleResults = $subgraph->findDescendantNodes(
            $siteNode->aggregateId,
            FindDescendantNodesFilter::create(
                nodeTypes: $diagramNodeTypeName,
                propertyValue: $propertyConstraint
            ),
        );

        foreach ($possibleResults as $node) {
            if ($contextNode->equals($node)) {
                // we skip ourselves
                continue;
            }

            $results[] = $node;
        }

        return $results;
    }
}

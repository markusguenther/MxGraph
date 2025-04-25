<?php

namespace Sandstorm\MxGraph;

use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\I18n\Translator;
use Neos\Neos\Service\DataSource\AbstractDataSource;
use Sandstorm\LazyDataSource\LazyDataSourceTrait;

class DiagramIdentifierDataSource extends AbstractDataSource
{
    use LazyDataSourceTrait;

    protected static $identifier = 'drawio-diagram-identifier';

    #[Flow\Inject]
    protected Translator $translator;

    #[Flow\Inject]
    protected DiagramIdentifierSearchService $diagramIdentifierSearchService;

    protected function getDataForIdentifiers(array $identifiers, Node $node = null, array $arguments = [])
    {
        // all identifiers will be returned as is (with a label containing usage count)
        $options = [];
        foreach ($identifiers as $id) {
            $options[$id] = ['label' => $this->getLabelFor($id, $node)];
        }
        return $options;
    }

    protected function searchData(string $searchTerm, Node $node = null, array $arguments = [])
    {
        $options = [];
        if ($node !== null) {
            $diagramIdentifiers = $this->diagramIdentifierSearchService->findInIdentifier($searchTerm, $node);
            foreach ($diagramIdentifiers as $diagramIdentifier) {
                $options[$diagramIdentifier] = ['label' => $this->getLabelFor($diagramIdentifier, $node)];
            }
        }

        $options[$searchTerm] = ['label' => $searchTerm, 'icon' => 'plus'];
        return $options;
    }

    protected function getLabelFor(string $diagramIdentifier, $node): string
    {
        $relatedCount = count($this->diagramIdentifierSearchService->findRelatedDiagramsWithIdentifierExcludingOwn($diagramIdentifier, $node));
        $label = $diagramIdentifier;
        if ($relatedCount > 0) {
            // TODO: this shows "2 Usages" when it's actually 1
            $label .= $this->translator->translateById('diagramIdentifierUsageLabel', [$relatedCount+1], null, null, 'Main', 'Sandstorm.MxGraph');
        }
        return $label;
    }
}

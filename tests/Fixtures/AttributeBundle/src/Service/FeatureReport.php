<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Service;

use SymPress\Kernel\Tests\Fixtures\AttributeBundle\Src\Message\AutoconfiguredNoteProvider;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class FeatureReport
{
    public function __construct(
        private readonly FormatterSelection $formatterSelection,
        private readonly LazySummary $lazySummary,
        private readonly LocatorSummary $locatorSummary,
        private readonly PanelSummary $panelSummary,
        private readonly ParameterMessage $parameterMessage,
        private readonly RequiredSummary $requiredSummary,
        #[AutowireIterator('kernel_fixture.note_provider')]
        private readonly iterable $notes,
        #[AutowireIterator('kernel_fixture.status_provider', indexAttribute: 'index')]
        private readonly iterable $statuses,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $notes = [];
        $statuses = [];

        foreach ($this->notes as $note) {
            if ($note instanceof AutoconfiguredNoteProvider) {
                $notes[] = $note->message();
            }
        }

        foreach ($this->statuses as $status) {
            $statuses[] = $status->label();
        }

        return [
            'parameter' => $this->parameterMessage->value(),
            'target' => $this->formatterSelection->formats(),
            'locator' => $this->locatorSummary->formats(),
            'panels' => $this->panelSummary->titles(),
            'required' => $this->requiredSummary->isInjected(),
            'lazy_before' => $this->lazySummary->instances(),
            'lazy_value' => $this->lazySummary->touch(),
            'lazy_after' => $this->lazySummary->instances(),
            'notes' => $notes,
            'statuses' => $statuses,
        ];
    }
}

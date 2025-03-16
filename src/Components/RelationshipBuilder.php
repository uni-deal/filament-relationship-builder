<?php

namespace UniDeal\FilamentRelationshipBuilder\Components;

use \Closure;
use Filament\Forms\ComponentContainer;
use Filament\Forms\Components\Builder;
use Filament\Forms\Components\Concerns\HasName;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;

class RelationshipBuilder extends Builder
{
    protected ?Collection $cachedExistingRecords = null;

    protected string|Closure|null $relationship = null;
    protected ?Closure $modifyRelationshipQueryUsing = null;
    protected ?Closure $mutateRelationshipDataBeforeCreateUsing = null;
    protected ?Closure $mutateRelationshipDataBeforeFillUsing = null;
    protected ?Closure $mutateRelationshipDataBeforeSaveUsing = null;

    protected string|Closure $orderColumn;
    protected string|Closure $typeColumn;
    protected string|Closure $dataColumn;

    public function relationship(
        string|Closure|null $name = null,
        string|Closure      $orderColumn = 'order',
        string|Closure      $typeColumn = 'type',
        string|Closure      $dataColumn = 'data',
        ?Closure            $modifyQueryUsing = null
    ): static
    {
        $this->relationship = $name ?? $this->getName();

        $this->orderColumn = $orderColumn;
        $this->typeColumn = $typeColumn;
        $this->dataColumn = $dataColumn;

        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;


        $this->loadStateFromRelationshipsUsing(static function (RelationshipBuilder $component) {
            $component->clearCachedExistingRecords();

            $component->fillFromRelationship();
        });

        // Disable auto-generated UUID
        $this->afterStateHydrated(null);

        $this->saveRelationshipsUsing(static function (RelationshipBuilder $component, HasForms $livewire, ?array $state) {
            if (!is_array($state)) {
                $state = [];
            }

            $relationship = $component->getRelationship();

            $existingRecords = $component->getCachedExistingRecords();

            $recordsToDelete = [];

            foreach ($existingRecords->pluck($relationship->getRelated()->getKeyName()) as $keyToCheckForDeletion) {
                if (array_key_exists("record-{$keyToCheckForDeletion}", $state)) {
                    continue;
                }

                $recordsToDelete[] = $keyToCheckForDeletion;
                $existingRecords->forget("record-{$keyToCheckForDeletion}");
            }

            $relationship
                ->whereKey($recordsToDelete)
                ->get()
                ->each(static fn(Model $record) => $record->delete());


            $childComponentContainers = $component->getChildComponentContainers(
                withHidden: $component->shouldSaveRelationshipsWhenHidden(),
            );

            $itemOrder = 0;
            $orderColumn = $component->getOrderColumn();
            $typeColumn = $component->getTypeColumn();
            $dataColumn = $component->getDataColumn();


            $translatableContentDriver = $livewire->makeFilamentTranslatableContentDriver();

            foreach ($childComponentContainers as $itemKey => $item) {
                $data = $item->getState(shouldCallHooksBefore: false);
                $order = $itemOrder;
                $itemOrder++;

                /** @var HasName $block */
                $block = $item->getParentComponent();
                $type = $block->getName();


                $itemData = [
                    $orderColumn => $order,
                    $typeColumn => $type,
                    $dataColumn => $data,
                ];


                if ($record = ($existingRecords[$itemKey] ?? null)) {
                    $itemData = $component->mutateRelationshipDataBeforeSave($itemData, record: $record);
                    if ($itemData === null) {
                        continue;
                    }

                    $translatableContentDriver ?
                        $translatableContentDriver->updateRecord($record, $itemData) :
                        $record->fill($itemData)->save();

                    continue;
                }


                $itemData = $component->mutateRelationshipDataBeforeCreate($itemData);
                if ($itemData === null) {
                    continue;
                }
                $relatedModel = $component->getRelatedModel();

                if ($translatableContentDriver) {

                    // TODO Translate nested data instead of record
                    // Option 1: Create fake model, with data flatten as attributes
                    // Option 2: The translatable driver implement his own logic for Builder nested 'data'
                    // Option 3: Ignore translation for builder and disable support
                    $record = $translatableContentDriver->makeRecord($relatedModel, $itemData);
                } else {
                    $record = new $relatedModel;
                    $record->fill($itemData);
                }


                $record = $relationship->save($record);
                $item->model($record)->saveRelationships();
                $existingRecords->push($record);
            }

            $component->getRecord()->setRelation($component->getRelationshipName(), $existingRecords);
        });

        $this->dehydrated(false);

        return $this;
    }

    #[\Override]
    public function getChildComponentContainers(bool $withHidden = false): array
    {
        if ((!$withHidden) && $this->isHidden()) {
            return [];
        }

        $relationship = $this->getRelationship();
        $records = $relationship ? $this->getCachedExistingRecords() : null;

        return collect($this->getState())
            ->filter(fn(array $itemData): bool => filled($itemData['type'] ?? null) && $this->hasBlock($itemData['type']))
            ->map(
                fn(array $itemData, $itemKey): ComponentContainer => $this
                    ->getBlock($itemData['type'])
                    ->getChildComponentContainer()
                    ->statePath("{$itemKey}.data")
                    ->model($relationship ? $records[$itemKey] ?? $this->getRelatedModel() : null)
                    ->inlineLabel(false)
                    ->getClone(),
            )
            ->all();
    }

    public function getOrderColumn(): string
    {
        return $this->evaluate($this->orderColumn);
    }

    public function getTypeColumn(): string
    {
        return $this->evaluate($this->typeColumn);
    }

    public function getDataColumn(): string
    {
        return $this->evaluate($this->dataColumn);
    }

    public function fillFromRelationship(): void
    {
        $this->state(
            $this->getStateFromRelatedRecords($this->getCachedExistingRecords()),
        );
    }


    /**
     * @return array<array<string, mixed>>
     */
    protected function getStateFromRelatedRecords(Collection $records): array
    {
        if (!$records->count()) {
            return [];
        }

        $translatableContentDriver = $this->getLivewire()->makeFilamentTranslatableContentDriver();

        $dataColumn = $this->getDataColumn();
        $typeColumn = $this->getTypeColumn();

        return $records
            ->map(function (Model $record) use ($translatableContentDriver, $dataColumn, $typeColumn): array {

                // TODO Translate nested data instead of record
                // Option 1: Create fake model, with data flatten as attributes
                // Option 2: The translatable driver implement his own logic for Builder nested 'data'
                // Option 3: Ignore translation for builder and disable support
                $data = $translatableContentDriver ?
                    $translatableContentDriver->getRecordAttributesToArray($record) :
                    $record->attributesToArray();

                $itemState = [
                    'type' => $data[$typeColumn],
                    'data' => $data[$dataColumn] ?? null,
                ];

                return $this->mutateRelationshipDataBeforeFill($itemState);
            })
            ->toArray();
    }

    public function getRelationship(): HasOneOrMany|BelongsToMany|null
    {
        if (!$this->hasRelationship()) {
            return null;
        }

        return $this->getModelInstance()->{$this->getRelationshipName()}();
    }


    public function getRelationshipName(): ?string
    {
        return $this->evaluate($this->relationship);
    }

    public function getCachedExistingRecords(): Collection
    {
        if ($this->cachedExistingRecords) {
            return $this->cachedExistingRecords;
        }

        $relationship = $this->getRelationship();
        $relatedKeyName = $relationship->getRelated()->getKeyName();

        $relationshipName = $this->getRelationshipName();
        $orderColumn = $this->getOrderColumn();

        if (
            $this->getModelInstance()->relationLoaded($relationshipName) &&
            (!$this->modifyRelationshipQueryUsing)
        ) {
            return $this->cachedExistingRecords = $this->getRecord()->getRelationValue($relationshipName)
                ->when(filled($orderColumn), fn(Collection $records) => $records->sortBy($orderColumn))
                ->mapWithKeys(
                    fn(Model $item): array => ["record-{$item[$relatedKeyName]}" => $item],
                );
        }

        $relationshipQuery = $relationship->getQuery();

        if ($relationship instanceof BelongsToMany) {
            $relationshipQuery->select([
                $relationship->getTable() . '.*',
                $relationshipQuery->getModel()->getTable() . '.*',
            ]);
        }

        if ($this->modifyRelationshipQueryUsing) {
            $relationshipQuery = $this->evaluate($this->modifyRelationshipQueryUsing, [
                'query' => $relationshipQuery,
            ]) ?? $relationshipQuery;
        }

        if (filled($orderColumn)) {
            $relationshipQuery->orderBy($orderColumn);
        }

        return $this->cachedExistingRecords = $relationshipQuery->get()->mapWithKeys(
            fn(Model $item): array => ["record-{$item[$relatedKeyName]}" => $item],
        );
    }

    public function clearCachedExistingRecords(): void
    {
        $this->cachedExistingRecords = null;
    }

    public function getRelatedModel(): string
    {
        return $this->getRelationship()->getModel()::class;
    }

    public function hasRelationship(): bool
    {
        return filled($this->getRelationshipName());
    }


    public function mutateRelationshipDataBeforeCreateUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeCreateUsing = $callback;

        return $this;
    }

    /**
     * @param array<array<string, mixed>> $data
     * @return array<array<string, mixed>> | null
     */
    public function mutateRelationshipDataBeforeCreate(array $data): ?array
    {
        if ($this->mutateRelationshipDataBeforeCreateUsing instanceof Closure) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeCreateUsing, [
                'data' => $data,
            ]);
        }

        return $data;
    }

    public function mutateRelationshipDataBeforeSaveUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeSaveUsing = $callback;

        return $this;
    }

    /**
     * @param array<array<string, mixed>> $data
     * @return array<array<string, mixed>>
     */
    public function mutateRelationshipDataBeforeFill(array $data): array
    {
        if ($this->mutateRelationshipDataBeforeFillUsing instanceof Closure) {
            $data = $this->evaluate($this->mutateRelationshipDataBeforeFillUsing, [
                'data' => $data,
            ]);
        }

        return $data;
    }

    public function mutateRelationshipDataBeforeFillUsing(?Closure $callback): static
    {
        $this->mutateRelationshipDataBeforeFillUsing = $callback;

        return $this;
    }

    /**
     * @param array<array<string, mixed>> $data
     * @return array<array<string, mixed>> | null
     */
    public function mutateRelationshipDataBeforeSave(array $data, Model $record): ?array
    {
        if ($this->mutateRelationshipDataBeforeSaveUsing instanceof Closure) {
            $data = $this->evaluate(
                $this->mutateRelationshipDataBeforeSaveUsing,
                namedInjections: [
                    'data' => $data,
                    'record' => $record,
                ],
                typedInjections: [
                    Model::class => $record,
                    $record::class => $record,
                ],
            );
        }

        return $data;
    }

}

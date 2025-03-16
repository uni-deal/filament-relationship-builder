# Filament Relationship Builder

A simple package to register a FilamentPHP builder via an Eloquent relationship with columns: `order`, `type`, and `data`.

## Installation

To install this package, you can use Composer directly from the GitHub repository.

Run the following command in your terminal:

```bash
composer config repositories.uni-deal vcs https://github.com/uni-deal/filament-relationship-builder
composer require uni-deal/filament-relationship-builder:dev-main --prefer-dist
```

Once installed, you can start using the `RelationshipBuilder` component as shown in the example above.

## Example Usage

```php
use UniDeal\FilamentRelationshipBuilder\Components\RelationshipBuilder;

RelationshipBuilder::make('blocks')
    ->relationship()
    ->blocks([
        Forms\Components\Builder\Block::make('Heading')->schema([
            Forms\Components\TextInput::make('content')
        ])
    ]),
```

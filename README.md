# Filament Relationship Builder

A simple package to register a FilamentPHP builder via an Eloquent relationship with columns: `order`, `type`, and `data`.

## Installation

To install this package, you can use Composer directly from the GitHub repository.

Run the following command in your terminal:

```bash
composer require uni-deal/filament-relationship-builder
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

## Model Requirements

The targeted model must contain a cast on the `data` column of type array:

```php
class Block extends \Illuminate\Database\Eloquent\Model {
    // Before Laravel 11
    protected $casts = [
        'data' => 'array',
    ];

    // For Laravel 11+
    protected function casts(): array {
        return [
            'data' => 'array',    
        ];
    }
}
```

Register the `blocks` relation on the initial model:

```php
class Post extends \Illuminate\Database\Eloquent\Model {
    public function blocks() {
        return $this->hasMany(Block::class);
    }
}
```


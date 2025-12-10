<?php

namespace Rejoose\ModelCounter\Filament\Resources;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Rejoose\ModelCounter\Enums\Interval;
use Rejoose\ModelCounter\Filament\Resources\ModelCounterResource\Pages;
use Rejoose\ModelCounter\Models\ModelCounter;

class ModelCounterResource extends Resource
{
    protected static ?string $model = ModelCounter::class;

    public static string|null|\BackedEnum $navigationIcon = 'heroicon-o-chart-bar';

    public static string|null|\UnitEnum $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Model Counters';

    protected static ?string $modelLabel = 'Counter';

    protected static ?string $pluralModelLabel = 'Counters';

    public static ?int $navigationSort = 100;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('owner_type')
                    ->label('Owner Type')
                    ->required()
                    ->disabled()
                    ->dehydrated(),

                TextInput::make('owner_id')
                    ->label('Owner ID')
                    ->required()
                    ->disabled()
                    ->dehydrated(),

                TextInput::make('key')
                    ->label('Counter Key')
                    ->required()
                    ->maxLength(100),

                Select::make('interval')
                    ->label('Interval')
                    ->options(
                        collect(Interval::cases())
                            ->mapWithKeys(fn (Interval $interval) => [$interval->value => $interval->label()])
                            ->toArray()
                    )
                    ->native(false)
                    ->placeholder('None (Total)')
                    ->nullable(),

                DatePicker::make('period_start')
                    ->label('Period Start')
                    ->nullable()
                    ->visible(fn ($get) => $get('interval') !== null),

                TextInput::make('count')
                    ->label('Count')
                    ->numeric()
                    ->required()
                    ->default(0)
                    ->minValue(0),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('owner_type')
                    ->label('Owner Type')
                    ->formatStateUsing(fn (string $state): string => class_basename($state))
                    ->searchable()
                    ->sortable(),

                TextColumn::make('owner_id')
                    ->label('Owner ID')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('key')
                    ->label('Key')
                    ->searchable()
                    ->sortable()
                    ->badge()
                    ->color('primary'),

                TextColumn::make('interval')
                    ->label('Interval')
                    ->badge()
                    ->formatStateUsing(fn (?Interval $state): string => $state?->label() ?? 'Total')
                    ->color(fn (?Interval $state): string => match ($state) {
                        Interval::Day => 'success',
                        Interval::Week => 'info',
                        Interval::Month => 'warning',
                        Interval::Quarter => 'danger',
                        Interval::Year => 'gray',
                        null => 'primary',
                    })
                    ->sortable(),

                TextColumn::make('period_start')
                    ->label('Period')
                    ->date()
                    ->placeholder('-')
                    ->sortable(),

                TextColumn::make('count')
                    ->label('Count')
                    ->numeric()
                    ->sortable()
                    ->color('success')
                    ->weight('bold'),

                TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('interval')
                    ->label('Interval')
                    ->options([
                        '' => 'Total (No Interval)',
                        ...collect(Interval::cases())
                            ->mapWithKeys(fn (Interval $interval) => [$interval->value => $interval->label()])
                            ->toArray(),
                    ])
                    ->query(function ($query, array $data) {
                        if ($data['value'] === '') {
                            return $query->whereNull('interval');
                        }

                        if ($data['value']) {
                            return $query->where('interval', $data['value']);
                        }

                        return $query;
                    }),

                SelectFilter::make('owner_type')
                    ->label('Owner Type')
                    ->options(fn () => ModelCounter::query()
                        ->distinct()
                        ->pluck('owner_type')
                        ->mapWithKeys(fn ($type) => [$type => class_basename($type)])
                        ->toArray()
                    ),

                SelectFilter::make('key')
                    ->label('Counter Key')
                    ->options(fn () => ModelCounter::query()
                        ->distinct()
                        ->pluck('key', 'key')
                        ->toArray()
                    )
                    ->searchable(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('updated_at', 'desc');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListModelCounters::route('/'),
            'edit' => Pages\EditModelCounter::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        return static::getModel()::count();
    }
}

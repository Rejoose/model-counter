<?php

namespace Rejoose\ModelCounter\Filament;

use Filament\Contracts\Plugin;
use Filament\Panel;
use Rejoose\ModelCounter\Filament\Resources\ModelCounterResource;

class ModelCounterPlugin implements Plugin
{
    protected bool $hasResource = true;

    protected ?string $navigationGroup = null;

    protected ?string $navigationIcon = null;

    protected ?int $navigationSort = null;

    public static function make(): static
    {
        return app(static::class);
    }

    public static function get(): static
    {
        /** @var static $plugin */
        $plugin = filament(app(static::class)->getId());

        return $plugin;
    }

    public function getId(): string
    {
        return 'model-counter';
    }

    public function register(Panel $panel): void
    {
        if ($this->hasResource) {
            $panel->resources([
                ModelCounterResource::class,
            ]);
        }
    }

    public function boot(Panel $panel): void
    {
        if ($this->navigationGroup !== null) {
            ModelCounterResource::$navigationGroup = $this->navigationGroup;
        }

        if ($this->navigationIcon !== null) {
            ModelCounterResource::$navigationIcon = $this->navigationIcon;
        }

        if ($this->navigationSort !== null) {
            ModelCounterResource::$navigationSort = $this->navigationSort;
        }
    }

    /**
     * Check if the resource is enabled.
     */
    public function hasResource(): bool
    {
        return $this->hasResource;
    }

    /**
     * Enable or disable the resource.
     */
    public function resource(bool $condition = true): static
    {
        $this->hasResource = $condition;

        return $this;
    }

    /**
     * Set the navigation group for the resource.
     */
    public function navigationGroup(?string $group): static
    {
        $this->navigationGroup = $group;

        return $this;
    }

    /**
     * Set the navigation icon for the resource.
     */
    public function navigationIcon(?string $icon): static
    {
        $this->navigationIcon = $icon;

        return $this;
    }

    /**
     * Set the navigation sort order for the resource.
     */
    public function navigationSort(?int $sort): static
    {
        $this->navigationSort = $sort;

        return $this;
    }
}


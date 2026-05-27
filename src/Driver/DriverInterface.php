<?php

declare(strict_types=1);

namespace G\Image\Driver;

use G\Image\Animation\AnimatableInterface;
use G\Image\Composition\CompositionInterface;
use G\Image\ImageInterface;
use G\Image\Io\InputInterface;
use G\Image\Io\OutputInterface;
use G\Image\Metadata\MetadataInterface;
use G\Image\Operation\ConvertibleInterface;
use G\Image\Operation\CroppableInterface;
use G\Image\Operation\FilterableInterface;
use G\Image\Operation\ResizableInterface;
use G\Image\Operation\TransformableInterface;

/**
 * Aggregate contract for a full image-processing driver.
 *
 * A driver is the central object that consumers interact with. It combines
 * every capability of the library into a single fluent API:
 *
 *  - Identity    : {@see ImageInterface} — marks the object as a compositable image
 *  - I/O         : {@see InputInterface}, {@see OutputInterface}
 *  - Operations  : {@see ResizableInterface}, {@see CroppableInterface},
 *                  {@see TransformableInterface}, {@see FilterableInterface},
 *                  {@see ConvertibleInterface}
 *  - Composition : {@see CompositionInterface}
 *  - Metadata    : {@see MetadataInterface}
 *  - Animation   : {@see AnimatableInterface}
 *  - Introspection: {@see CapabilityInterface}
 *  - Lifecycle   : {@see ImageStateInterface}
 *
 * Drivers are instantiated through {@see \G\Image\Factory\ImageFactoryInterface}
 * or the static facade {@see \G\Image\Factory\Image}.
 *
 * Example usage:
 * ```php
 * $image = Image::create(GDDriver::class)
 *     ->fromFile('/path/to/photo.jpg')
 *     ->resize(800, null)
 *     ->toWebp(85)
 *     ->save('/path/to/output.webp');
 * ```
 */
interface DriverInterface extends
    ImageInterface,
    InputInterface,
    OutputInterface,
    ResizableInterface,
    CroppableInterface,
    TransformableInterface,
    FilterableInterface,
    ConvertibleInterface,
    CompositionInterface,
    MetadataInterface,
    AnimatableInterface,
    CapabilityInterface,
    ImageStateInterface
{
}

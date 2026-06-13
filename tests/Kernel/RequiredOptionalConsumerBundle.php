<?php

declare(strict_types=1);

namespace SymPress\Kernel\Tests\Kernel;

use SymPress\Kernel\Bundle\AbstractBundle;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;

#[RequiredBundle('SymPress\Kernel\Tests\Kernel\MissingOptionalBundle', ignoreOnInvalid: true)]
final class RequiredOptionalConsumerBundle extends AbstractBundle
{
}

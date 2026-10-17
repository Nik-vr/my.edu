<?php

declare(strict_types=1);

namespace MyEdu\Http\Controller\Panel;

use RuntimeException;

/** Плагин недоступен, поэтому удаление его данных нельзя выполнить безопасно. */
final class PluginRemovalUnavailable extends RuntimeException
{
}

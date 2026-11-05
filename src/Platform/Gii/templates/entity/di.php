<?php

declare(strict_types=1);

/**
 * Template: module DI file (src/Modules/<Module>/config/di.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

/**
 * {{label}} module DI. Every service of the module is autowired ({{Entity}}Service, {{Entity}}Repository,
 * jobs, consumers, connector); this file is the place for module-specific bindings.
 */
return [];

PHP);

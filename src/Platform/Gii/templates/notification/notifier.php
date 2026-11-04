<?php

declare(strict_types=1);

/**
 * Template: Notifier call site (src/Modules/<Module>/Application/<Entity>Notifier.php).
 *
 * @var App\Platform\Gii\Spec\ModuleSpec $m
 */

$status = $m->hasStatus();
$recipients = array_map(static fn($f) => "\$entity->{$f->camel()}()", $m->ofType('user'));
$recipients[] = '$entity->createdBy';

$method = $status ? <<<'PHP'
    /** Status change: informs the referenced users (and the creator) except the actor. */
    public function statusChanged(User $actor, {{Entity}} $entity, {{Entity}}Status $to): int
    {
        return $this->send($actor, $entity, ['status' => $to->label()]);
    }
PHP : <<<'PHP'
    /** Creation: informs the referenced users (and the creator) except the actor. */
    public function created(User $actor, {{Entity}} $entity): int
    {
        return $this->send($actor, $entity, []);
    }
PHP;

echo $m->fill(<<<'PHP'
<?php

{{HEADER}}

declare(strict_types=1);

namespace App\Modules\{{Module}}\Application;

use App\Modules\{{Module}}\Domain\{{Entity}};__STATUS_USE__
use App\Platform\Identity\Domain\User;
use App\Platform\Notifications\Notifier;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Notifications of the {{label}} module through the platform Notifier (template "__TEMPLATE__", seeded in
 * uz/ru/en; in-app immediately, e-mail asynchronously; users may opt out of category "{{code}}").
 * Delivery problems never break the business transaction.
 */
final class {{Entity}}Notifier
{
    public const TEMPLATE = '__TEMPLATE__';
    public const CATEGORY = '{{code}}';
    public const CHANNELS = ['inapp', 'email'];

    public function __construct(
        private readonly Notifier $notifier,
        private readonly LoggerInterface $logger,
    ) {}

__METHOD__

    /** @return int number of users notified */
    private function send(User $actor, {{Entity}} $entity, array $params): int
    {
        $recipients = array_values(array_unique(array_filter([__RECIPIENTS__], static fn($id) => is_string($id) && $id !== '' && $id !== $actor->id)));
        $sent = 0;
        foreach ($recipients as $userId) {
            try {
                $this->notifier->notify($userId, self::TEMPLATE, $params + [
                    'record' => $entity->displayTitle(),
                    'actor' => $actor->displayName(),
                ], self::CHANNELS, self::CATEGORY);
                $sent++;
            } catch (Throwable $e) {
                $this->logger->warning('{{entityLabel}} notification failed: {e}', ['e' => $e->getMessage()]);
            }
        }
        return $sent;
    }
}

PHP, [
    '__STATUS_USE__' => $status ? "\nuse App\\Modules\\{{Module}}\\Domain\\{{Entity}}Status;" : '',
    '__TEMPLATE__' => $m->notifyTemplate(),
    '__METHOD__' => $method,
    '__RECIPIENTS__' => implode(', ', $recipients),
]);

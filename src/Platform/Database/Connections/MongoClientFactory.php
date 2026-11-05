<?php

declare(strict_types=1);

namespace App\Platform\Database\Connections;

use MongoDB\Client;

/**
 * Builds a MongoDB client (mongodb/mongodb, ext-mongodb) from a named connection. Credentials go into URI options,
 * never into the URI string, so they cannot leak through error messages that echo the URI.
 *
 * Author: ShokirjonMK
 */
final class MongoClientFactory
{
    public function create(ConnectionDefinition $d): Client
    {
        $srv = (bool) $d->option('srv', false);
        $uri = ($srv ? 'mongodb+srv://' : 'mongodb://') . $d->host . ($srv ? '' : ':' . $d->port()) . '/';
        $timeoutMs = max(1, (int) $d->option('timeout', 5)) * 1000;

        $options = [
            'serverSelectionTimeoutMS' => $timeoutMs,
            'connectTimeoutMS' => $timeoutMs,
            'appname' => 'yii3-platform:' . $d->name,
        ];
        if ($d->username !== '') {
            $options['username'] = $d->username;
            $options['password'] = $d->password;
            $options['authSource'] = (string) $d->option('authSource', 'admin');
        }
        foreach (['replicaSet', 'readPreference', 'authMechanism', 'w'] as $k) {
            if ($d->option($k) !== null && $d->option($k) !== '') {
                $options[$k] = (string) $d->option($k);
            }
        }
        if ($d->readOnly && !isset($options['readPreference'])) {
            $options['readPreference'] = 'secondaryPreferred';
        }
        if ($d->option('tls') !== null) {
            $options['tls'] = (bool) $d->option('tls');
        }

        return new Client($uri, $options, ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }
}

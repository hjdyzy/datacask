<?php

namespace App\Enums;

enum DatabaseSelectionMode: string
{
    case All = 'all';
    case Selected = 'selected';
    case Pattern = 'pattern';

    /**
     * Back up every enumerable database except the listed names.
     *
     * The list is exact and case-sensitive: it is typed by hand, so a
     * near-miss has to stay visible instead of silently skipping a database.
     */
    case Excluded = 'excluded';

    /**
     * Whether the target list is only known after querying the server, which
     * forces a remote agent to run discovery before any snapshot is created.
     * Only Selected carries a complete list that the web app already knows.
     */
    public function requiresServerDiscovery(): bool
    {
        return $this !== self::Selected;
    }
}

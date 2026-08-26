<?php
namespace App;

use App\Repository\ChangeLogRepository;

final class ChangeLog
{
    /** @return array{0:string,1:?int} [actor, actor_user_id] */
    public static function resolveActor(bool $isCli, bool $loggedIn, ?array $user): array
    {
        if ($isCli) {
            return ['系統', null];
        }
        if ($loggedIn && $user) {
            return [$user['name'], (int) $user['id']];
        }
        return ['前台（未登入）', null];
    }

    public static function record(\PDO $pdo, string $page, string $action, string $summary): void
    {
        [$actor, $actorId] = self::resolveActor(PHP_SAPI === 'cli', Auth::check(), Auth::user());
        (new ChangeLogRepository($pdo))->insert($page, $action, $summary, $actor, $actorId);
    }
}

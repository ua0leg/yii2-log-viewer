<?php

namespace Ua0leg\Yii2LogViewer;

use Yii;
use yii\base\InvalidConfigException;
use yii\base\Module as BaseModule;

/**
 * Smart Yii2 FileTarget log viewer module.
 *
 * Config example:
 *
 * ```php
 * 'modules' => [
 *     'log' => [
 *         'class' => \Ua0leg\Yii2LogViewer\Module::class,
 *         'aliases' => [
 *             'App' => '@runtime/logs/app.log',
 *             'Console' => '@runtime/logs/app.log', // or console path
 *         ],
 *         'allowedUserIds' => [1],
 *         'canClear' => true,
 *         'canDownload' => true,
 *         'userResolver' => static function (array $ids): array { ... },
 *         'userUrlCallback' => static function (string $userId) {
 *             return ['/staff/index', 'StaffSearch' => ['id' => $userId]];
 *         },
 *     ],
 * ],
 * ```
 */
class Module extends BaseModule
{
    public $controllerNamespace = 'Ua0leg\\Yii2LogViewer\\controllers';

    /**
     * Named log files. Keys are display names, values are path aliases or absolute paths.
     * @var array<string, string>
     */
    public array $aliases = [
        'App' => '@runtime/logs/app.log',
    ];

    /**
     * If non-empty, only these user IDs may access the module.
     * @var list<int>
     */
    public array $allowedUserIds = [1];

    /**
     * If non-empty, user must have at least one of these RBAC roles/permissions.
     * Checked in addition to allowedUserIds when both are set (OR logic: either matches).
     * @var list<string>
     */
    public array $accessRoles = [];

    /**
     * Optional custom access check: function(): bool
     * When set, takes precedence over allowedUserIds / accessRoles.
     * @var callable|null
     */
    public $accessCallback = null;

    public bool $canClear = true;
    public bool $canDownload = true;

    /**
     * Resolve user IDs found in logs to display names.
     * Signature: function(int[] $ids): array<string, array{id:int,name:string,email?:?string}>
     * @var callable|null
     */
    public $userResolver = null;

    /**
     * Build a URL for a user id link in the UI.
     * Signature: function(string $userId): string|array|null
     * @var callable|null
     */
    public $userUrlCallback = null;

    /** Default entries per page */
    public int $defaultLimit = 40;

    public function init(): void
    {
        parent::init();
        $this->setViewPath(__DIR__ . '/views');

        if ($this->aliases === []) {
            throw new InvalidConfigException('Ua0leg\Yii2LogViewer\Module::$aliases must not be empty.');
        }
    }

    public function checkAccess(): bool
    {
        if ($this->accessCallback !== null) {
            return (bool)call_user_func($this->accessCallback);
        }

        if (Yii::$app->user->isGuest) {
            return false;
        }

        $uid = (int)Yii::$app->user->id;
        if ($this->allowedUserIds !== [] && in_array($uid, $this->allowedUserIds, true)) {
            return true;
        }

        foreach ($this->accessRoles as $role) {
            if (Yii::$app->user->can($role)) {
                return true;
            }
        }

        // If both lists empty and no callback — deny by default
        return false;
    }

    /**
     * @return list<LogFile>
     */
    public function getLogs(): array
    {
        $logs = [];
        foreach ($this->aliases as $name => $alias) {
            $logs[] = new LogFile((string)$name, (string)$alias);
        }
        return $logs;
    }

    public function findLog(string $slug, ?string $stamp = null): ?LogFile
    {
        foreach ($this->aliases as $name => $alias) {
            if (LogFile::slug((string)$name) === $slug) {
                return new LogFile((string)$name, (string)$alias, $stamp);
            }
        }
        return null;
    }

    public function defaultSlug(): string
    {
        $first = array_key_first($this->aliases);
        return LogFile::slug((string)$first);
    }
}

# yii2-log-viewer

Smart Yii2 **FileTarget** log viewer: reads from the **end** of large files, parses request context (`$_GET` / `$_POST` / `REQUEST_URI` / user), filters, multi-file aliases, download, clear, and rotated history.

## Requirements

- PHP 8.2+
- Yii2 ~2.0.45

## Installation

### Composer (VCS)

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/ua0leg/yii2-log-viewer.git"
        }
    ],
    "require": {
        "ua0leg/yii2-log-viewer": "@dev"
    }
}
```

### Local path (symlink)

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "../yii2-log-viewer",
            "options": { "symlink": true }
        }
    ],
    "require": {
        "ua0leg/yii2-log-viewer": "@dev"
    }
}
```

Then: `composer update ua0leg/yii2-log-viewer`

## Configuration

```php
'modules' => [
    'log' => [
        'class' => \Ua0leg\Yii2LogViewer\Module::class,
        'aliases' => [
            'App' => '@runtime/logs/app.log',
            // 'Console' => '@runtime/logs/console.log',
        ],
        'allowedUserIds' => [1],          // and/or:
        // 'accessRoles' => ['admin'],
        // 'accessCallback' => fn() => Yii::$app->user->can('viewLogs'),
        'canClear' => true,
        'canDownload' => true,
        'userResolver' => static function (array $ids): array {
            $out = [];
            foreach (\app\models\User::find()->where(['id' => $ids])->all() as $u) {
                $out[(string)$u->id] = [
                    'id' => (int)$u->id,
                    'name' => trim($u->firstLastName ?: $u->username),
                    'email' => $u->email,
                ];
            }
            return $out;
        },
        'userUrlCallback' => static function (string $userId) {
            return ['/staff/index', 'StaffSearch' => ['id' => $userId]];
        },
    ],
],
```

Open: `/log` (pretty URL) or `?r=log/default/index`

## Features

| Feature | Notes |
|---|---|
| Tail from end | Chunked reverse read — safe for large `app.log` |
| Yii context parse | Attaches GET/POST/URI/user from `[info][application]` dumps |
| Filters | level, userId, URI, category, free text, hide 4xx |
| Multi-file aliases | Switch App / Console / … |
| History | Lists `app.log.*` rotated/dated siblings |
| Download / Clear | Optional flags |
| Access | `allowedUserIds`, `accessRoles`, or `accessCallback` |

## License

MIT

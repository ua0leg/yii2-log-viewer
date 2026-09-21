<?php

namespace Ua0leg\Yii2LogViewer\controllers;

use Ua0leg\Yii2LogViewer\LogFile;
use Ua0leg\Yii2LogViewer\LogReader;
use Ua0leg\Yii2LogViewer\Module;
use Yii;
use yii\filters\AccessControl;
use yii\helpers\Url;
use yii\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * @property-read Module $module
 */
class DefaultController extends Controller
{
    public function behaviors(): array
    {
        return [
            'access' => [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'matchCallback' => fn () => $this->module->checkAccess(),
                    ],
                ],
                'denyCallback' => static function () {
                    throw new ForbiddenHttpException('You are not allowed to view logs.');
                },
            ],
        ];
    }

    public function actionIndex(?string $slug = null, ?string $stamp = null)
    {
        $log = $this->findLog($slug, $stamp);
        $request = Yii::$app->request;

        $filters = [
            'limit' => (int)$request->get('limit', $this->module->defaultLimit),
            'before' => $request->get('before'),
            'level' => (string)$request->get('level', ''),
            'q' => (string)$request->get('q', ''),
            'userId' => (string)$request->get('userId', ''),
            'category' => (string)$request->get('category', ''),
            'uri' => (string)$request->get('uri', ''),
            'excludeHttp' => (bool)$request->get('excludeHttp', false),
            'includeInfo' => (bool)$request->get('includeInfo', false),
        ];

        $reader = new LogReader($log->getPath());
        $result = $reader->read($filters);
        $users = $this->resolveUsers($result['entries']);

        if ($request->get('format') === 'json') {
            Yii::$app->response->format = Response::FORMAT_JSON;
            return [
                'meta' => $reader->meta(),
                'nextBefore' => $result['nextBefore'],
                'exhausted' => $result['exhausted'],
                'scannedBytes' => $result['scannedBytes'],
                'entries' => $result['entries'],
                'users' => $users,
                'slug' => $log->getSlug(),
                'stamp' => $log->stamp,
            ];
        }

        return $this->render('index', [
            'log' => $log,
            'logs' => $this->module->getLogs(),
            'history' => $log->history(),
            'meta' => $reader->meta(),
            'entries' => $result['entries'],
            'nextBefore' => $result['nextBefore'],
            'exhausted' => $result['exhausted'],
            'scannedBytes' => $result['scannedBytes'],
            'filters' => $filters,
            'users' => $users,
            'module' => $this->module,
        ]);
    }

    public function actionRaw(string $slug, int $offset, ?string $stamp = null)
    {
        Yii::$app->response->format = Response::FORMAT_RAW;
        Yii::$app->response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        $log = $this->findLog($slug, $stamp);
        $reader = new LogReader($log->getPath());
        $result = $reader->read([
            'limit' => 1,
            'before' => $offset + 1,
            'includeInfo' => true,
        ]);

        if ($result['entries'] === [] || (int)$result['entries'][0]['offset'] !== $offset) {
            Yii::$app->response->statusCode = 404;
            return "Entry not found at offset {$offset}\n";
        }

        return $result['entries'][0]['raw'] . "\n";
    }

    public function actionClear(string $slug, ?string $stamp = null)
    {
        if (!$this->module->canClear) {
            throw new ForbiddenHttpException('Clear is disabled.');
        }
        if (!Yii::$app->request->isPost) {
            return $this->redirect(['index', 'slug' => $slug, 'stamp' => $stamp]);
        }

        $log = $this->findLog($slug, $stamp);
        if (!$log->exists()) {
            Yii::$app->session->setFlash('warning', 'Log file does not exist.');
        } elseif ($log->clear()) {
            Yii::$app->session->setFlash('success', 'Log file cleared.');
        } else {
            Yii::$app->session->setFlash('error', 'Failed to clear log file.');
        }

        return $this->redirect(['index', 'slug' => $slug, 'stamp' => $stamp]);
    }

    public function actionDownload(string $slug, ?string $stamp = null)
    {
        if (!$this->module->canDownload) {
            throw new ForbiddenHttpException('Download is disabled.');
        }

        $log = $this->findLog($slug, $stamp);
        if (!$log->exists()) {
            throw new NotFoundHttpException('Log file not found.');
        }

        return Yii::$app->response->sendFile($log->getPath(), basename($log->getPath()), [
            'mimeType' => 'text/plain',
            'inline' => false,
        ]);
    }

    public function actionHistory(string $slug)
    {
        $log = $this->findLog($slug, null);
        return $this->render('history', [
            'log' => $log,
            'history' => $log->history(),
            'module' => $this->module,
        ]);
    }

    protected function findLog(?string $slug, ?string $stamp): LogFile
    {
        $slug = $slug ?: $this->module->defaultSlug();
        $log = $this->module->findLog($slug, $stamp);
        if ($log === null) {
            throw new NotFoundHttpException('Log not found.');
        }
        return $log;
    }

    /**
     * @param array<int, array> $entries
     * @return array<string, array{id:int,name:string,email?:?string}>
     */
    protected function resolveUsers(array $entries): array
    {
        $ids = [];
        foreach ($entries as $entry) {
            foreach (['userId', 'sessionUserId'] as $key) {
                if (!empty($entry[$key]) && ctype_digit((string)$entry[$key])) {
                    $ids[(int)$entry[$key]] = true;
                }
            }
        }
        if ($ids === [] || $this->module->userResolver === null) {
            return [];
        }

        $resolved = call_user_func($this->module->userResolver, array_keys($ids));
        return is_array($resolved) ? $resolved : [];
    }

    public function userUrl(string $userId): ?string
    {
        if ($this->module->userUrlCallback === null) {
            return null;
        }
        $url = call_user_func($this->module->userUrlCallback, $userId);
        if ($url === null || $url === '') {
            return null;
        }
        return is_array($url) ? Url::to($url) : (string)$url;
    }
}

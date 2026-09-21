<?php

use Ua0leg\Yii2LogViewer\LogFile;
use Ua0leg\Yii2LogViewer\Module;
use yii\helpers\Html;

/** @var yii\web\View $this */
/** @var LogFile $log */
/** @var list<LogFile> $history */
/** @var Module $module */

$this->title = 'History: ' . $log->name;
$this->params['breadcrumbs'][] = ['label' => 'Log: ' . $log->name, 'url' => ['index', 'slug' => $log->getSlug()]];
$this->params['breadcrumbs'][] = 'History';

$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
};
?>

<p><?= Html::a('← Back to current', ['index', 'slug' => $log->getSlug()], ['class' => 'btn btn-sm btn-outline-secondary']) ?></p>

<table class="table table-sm table-striped">
    <thead>
    <tr>
        <th>Stamp</th>
        <th>Size</th>
        <th>Modified</th>
        <th></th>
    </tr>
    </thead>
    <tbody>
    <tr>
        <td><em>current</em></td>
        <td><?= $fmtSize($log->getSize()) ?></td>
        <td><?= $log->getMtime() ? date('Y-m-d H:i:s', $log->getMtime()) : '—' ?></td>
        <td>
            <?= Html::a('View', ['index', 'slug' => $log->getSlug()], ['class' => 'btn btn-xs btn-primary btn-sm']) ?>
            <?php if ($module->canDownload && $log->exists()): ?>
                <?= Html::a('Download', ['download', 'slug' => $log->getSlug()], ['class' => 'btn btn-xs btn-outline-secondary btn-sm']) ?>
            <?php endif; ?>
        </td>
    </tr>
    <?php foreach ($history as $item): ?>
        <tr>
            <td><code><?= Html::encode($item->stamp) ?></code></td>
            <td><?= $fmtSize($item->getSize()) ?></td>
            <td><?= $item->getMtime() ? date('Y-m-d H:i:s', $item->getMtime()) : '—' ?></td>
            <td>
                <?= Html::a('View', ['index', 'slug' => $item->getSlug(), 'stamp' => $item->stamp], ['class' => 'btn btn-xs btn-primary btn-sm']) ?>
                <?php if ($module->canDownload): ?>
                    <?= Html::a('Download', ['download', 'slug' => $item->getSlug(), 'stamp' => $item->stamp], ['class' => 'btn btn-xs btn-outline-secondary btn-sm']) ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if ($history === []): ?>
        <tr><td colspan="4" class="text-muted">No rotated history files found (e.g. app.log.1, app.log.20240921).</td></tr>
    <?php endif; ?>
    </tbody>
</table>

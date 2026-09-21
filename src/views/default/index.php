<?php

use Ua0leg\Yii2LogViewer\LogFile;
use Ua0leg\Yii2LogViewer\Module;
use Ua0leg\Yii2LogViewer\controllers\DefaultController;
use yii\helpers\Html;
use yii\helpers\Json;
use yii\helpers\Url;

/** @var yii\web\View $this */
/** @var LogFile $log */
/** @var list<LogFile> $logs */
/** @var list<LogFile> $history */
/** @var array $meta */
/** @var array $entries */
/** @var int|null $nextBefore */
/** @var bool $exhausted */
/** @var int $scannedBytes */
/** @var array $filters */
/** @var array $users */
/** @var Module $module */
/** @var DefaultController $context */
$context = $this->context;

$this->title = 'Log: ' . $log->name . ($log->stamp ? ' (' . $log->stamp . ')' : '');
$this->params['breadcrumbs'][] = $this->title;

$levelClass = static function (string $level): string {
    return match (strtolower($level)) {
        'error' => 'lv-level-error',
        'warning' => 'lv-level-warning',
        'info' => 'lv-level-info',
        default => 'lv-level-other',
    };
};

$userLabel = static function (?string $userId) use ($users): string {
    if ($userId === null || $userId === '') {
        return 'guest';
    }
    if (isset($users[$userId])) {
        return '#' . $userId . ' ' . $users[$userId]['name'];
    }
    return '#' . $userId;
};

$fmtSize = static function (int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 2) . ' MB';
};

$indexParams = static function (array $extra = []) use ($log, $filters): array {
    return array_merge([
        'index',
        'slug' => $log->getSlug(),
        'stamp' => $log->stamp,
        'level' => $filters['level'] ?: null,
        'q' => $filters['q'] ?: null,
        'userId' => $filters['userId'] ?: null,
        'category' => $filters['category'] ?: null,
        'uri' => $filters['uri'] ?: null,
        'limit' => $filters['limit'],
        'excludeHttp' => $filters['excludeHttp'] ? 1 : null,
        'includeInfo' => $filters['includeInfo'] ? 1 : null,
    ], $extra);
};
?>

<style>
    .lv-wrap { font-size: 13px; }
    .lv-meta { color: #6c757d; margin-bottom: 12px; }
    .lv-filters .form-control, .lv-filters .custom-select { height: calc(1.5em + .5rem + 2px); padding: .25rem .5rem; font-size: 13px; }
    .lv-filters .btn { padding: .25rem .6rem; font-size: 13px; }
    .lv-entry { border: 1px solid #e3e6ea; border-left-width: 4px; border-radius: 6px; margin-bottom: 10px; background: #fff; overflow: hidden; }
    .lv-level-error { border-left-color: #dc3545; }
    .lv-level-warning { border-left-color: #f0ad4e; }
    .lv-level-info { border-left-color: #17a2b8; }
    .lv-level-other { border-left-color: #6c757d; }
    .lv-head { display: flex; flex-wrap: wrap; gap: 6px 12px; align-items: baseline; padding: 8px 12px; cursor: pointer; user-select: none; }
    .lv-head:hover { background: #f8f9fa; }
    .lv-badge { display: inline-block; font-size: 11px; font-weight: 700; text-transform: uppercase; padding: 1px 6px; border-radius: 3px; color: #fff; }
    .lv-badge-error { background: #dc3545; }
    .lv-badge-warning { background: #f0ad4e; color: #212529; }
    .lv-badge-info { background: #17a2b8; }
    .lv-badge-other { background: #6c757d; }
    .lv-time { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #495057; white-space: nowrap; }
    .lv-cat { color: #6c757d; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; word-break: break-all; }
    .lv-uri { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; color: #0d6efd; word-break: break-all; }
    .lv-msg { width: 100%; color: #212529; margin-top: 2px; }
    .lv-body { display: none; border-top: 1px solid #e9ecef; padding: 10px 12px 12px; background: #fafbfc; }
    .lv-entry.open .lv-body { display: block; }
    .lv-grid { display: grid; grid-template-columns: 110px 1fr; gap: 4px 10px; margin-bottom: 10px; }
    .lv-grid dt { color: #6c757d; margin: 0; }
    .lv-grid dd { margin: 0; word-break: break-word; }
    .lv-pre { margin: 0 0 10px; padding: 10px; background: #1e1e1e; color: #d4d4d4; border-radius: 4px; max-height: 360px; overflow: auto; font-size: 12px; white-space: pre-wrap; word-break: break-word; }
    .lv-pre-title { font-weight: 600; margin: 8px 0 4px; color: #495057; }
    .lv-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
    .lv-empty { padding: 24px; text-align: center; color: #6c757d; border: 1px dashed #ced4da; border-radius: 6px; }
    .lv-load { text-align: center; margin: 16px 0 8px; }
    .lv-method { font-size: 11px; font-weight: 700; padding: 1px 5px; border-radius: 3px; background: #e9ecef; color: #343a40; margin-right: 4px; }
    .lv-method-POST { background: #fff3cd; }
    .lv-method-GET { background: #d1e7dd; }
    .lv-tabs { margin-bottom: 12px; }
</style>

<div class="lv-wrap">
    <?php if (count($logs) > 1): ?>
        <div class="lv-tabs btn-group btn-group-sm mb-2">
            <?php foreach ($logs as $item): ?>
                <?= Html::a(
                    Html::encode($item->name),
                    ['index', 'slug' => $item->getSlug()],
                    ['class' => 'btn btn-sm ' . ($item->getSlug() === $log->getSlug() && $log->stamp === null ? 'btn-primary' : 'btn-outline-secondary')]
                ) ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="d-flex flex-wrap justify-content-between align-items-start mb-2">
        <div>
            <h4 class="mb-1"><?= Html::encode($this->title) ?></h4>
            <div class="lv-meta">
                <?php if (!$meta['exists']): ?>
                    Log file not found: <code><?= Html::encode($log->getPath()) ?></code>
                <?php else: ?>
                    <code><?= Html::encode($log->getPath()) ?></code>
                    · <?= $fmtSize((int)$meta['size']) ?>
                    · mtime <?= Html::encode($meta['mtimeFormatted']) ?>
                    · scanned <?= $fmtSize((int)$scannedBytes) ?> for this page
                <?php endif; ?>
                <?php if ($history !== []): ?>
                    · <?= Html::a('History (' . count($history) . ')', ['history', 'slug' => $log->getSlug()]) ?>
                <?php endif; ?>
            </div>
        </div>
        <div>
            <?= Html::a('Refresh', Url::current(), ['class' => 'btn btn-sm btn-outline-secondary']) ?>
            <?php if ($module->canDownload && $meta['exists']): ?>
                <?= Html::a('Download', ['download', 'slug' => $log->getSlug(), 'stamp' => $log->stamp], [
                    'class' => 'btn btn-sm btn-outline-secondary',
                    'data-pjax' => 0,
                ]) ?>
            <?php endif; ?>
            <?php if ($module->canClear && $meta['exists'] && (int)$meta['size'] > 0 && $log->stamp === null): ?>
                <?= Html::a('Clear log', ['clear', 'slug' => $log->getSlug()], [
                    'class' => 'btn btn-sm btn-outline-danger',
                    'data-method' => 'post',
                    'data-confirm' => 'Clear this log file completely?',
                    'data-pjax' => 0,
                ]) ?>
            <?php endif; ?>
        </div>
    </div>

    <form method="get" class="lv-filters card card-body mb-3 py-2">
        <?= Html::hiddenInput('slug', $log->getSlug()) ?>
        <?php if ($log->stamp): ?>
            <?= Html::hiddenInput('stamp', $log->stamp) ?>
        <?php endif; ?>
        <div class="form-row align-items-end">
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">Level</label>
                <?= Html::dropDownList('level', $filters['level'], [
                    '' => 'Any', 'error' => 'error', 'warning' => 'warning', 'info' => 'info',
                ], ['class' => 'custom-select custom-select-sm form-control']) ?>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">User ID</label>
                <?= Html::textInput('userId', $filters['userId'], ['class' => 'form-control form-control-sm', 'placeholder' => 'e.g. 79']) ?>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">URI contains</label>
                <?= Html::textInput('uri', $filters['uri'], ['class' => 'form-control form-control-sm', 'placeholder' => '/path']) ?>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">Category</label>
                <?= Html::textInput('category', $filters['category'], ['class' => 'form-control form-control-sm', 'placeholder' => 'HttpException']) ?>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">Search</label>
                <?= Html::textInput('q', $filters['q'], ['class' => 'form-control form-control-sm', 'placeholder' => 'text…']) ?>
            </div>
            <div class="col-md-2 mb-2">
                <label class="mb-0 small text-muted">Limit</label>
                <?= Html::dropDownList('limit', $filters['limit'], [20 => '20', 40 => '40', 80 => '80', 120 => '120'], ['class' => 'custom-select custom-select-sm form-control']) ?>
            </div>
        </div>
        <div class="form-row align-items-center">
            <div class="col-md-8 mb-1">
                <label class="mb-0 mr-3"><?= Html::checkbox('excludeHttp', $filters['excludeHttp'], ['value' => 1]) ?> Hide 400/401/403/404</label>
                <label class="mb-0"><?= Html::checkbox('includeInfo', $filters['includeInfo'], ['value' => 1]) ?> Include [info][application] dumps</label>
            </div>
            <div class="col-md-4 mb-1 text-md-right">
                <button type="submit" class="btn btn-sm btn-primary">Filter</button>
                <?= Html::a('Reset', ['index', 'slug' => $log->getSlug(), 'stamp' => $log->stamp], ['class' => 'btn btn-sm btn-light']) ?>
            </div>
        </div>
    </form>

    <div id="lv-list">
        <?php if ($entries === []): ?>
            <div class="lv-empty">No matching log entries in the scanned range.</div>
        <?php endif; ?>

        <?php foreach ($entries as $entry): ?>
            <?php
            $uid = $entry['userId'] ?? $entry['sessionUserId'] ?? null;
            $lvl = strtolower($entry['level']);
            $userHref = ($uid && ctype_digit((string)$uid)) ? $context->userUrl((string)$uid) : null;
            ?>
            <div class="lv-entry <?= $levelClass($entry['level']) ?>" data-offset="<?= (int)$entry['offset'] ?>">
                <div class="lv-head" onclick="this.parentElement.classList.toggle('open')">
                    <span class="lv-time"><?= Html::encode($entry['datetime']) ?></span>
                    <span class="lv-badge lv-badge-<?= Html::encode($lvl) ?>"><?= Html::encode($entry['level']) ?></span>
                    <?php if (!empty($entry['httpStatus'])): ?>
                        <span class="badge badge-light">HTTP <?= (int)$entry['httpStatus'] ?></span>
                    <?php endif; ?>
                    <?php if ($userHref): ?>
                        <?= Html::a(Html::encode($userLabel((string)$uid)), $userHref, [
                            'target' => '_blank', 'data-pjax' => 0, 'onclick' => 'event.stopPropagation()',
                        ]) ?>
                    <?php else: ?>
                        <span><?= Html::encode($userLabel($uid !== null ? (string)$uid : null)) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['ip'])): ?>
                        <span class="text-muted"><?= Html::encode($entry['ip']) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($entry['requestUri'])): ?>
                        <span class="lv-uri">
                            <?php if (!empty($entry['requestMethod'])): ?>
                                <span class="lv-method lv-method-<?= Html::encode($entry['requestMethod']) ?>"><?= Html::encode($entry['requestMethod']) ?></span>
                            <?php endif; ?>
                            <?= Html::a(Html::encode($entry['requestUri']), $entry['requestUri'], [
                                'target' => '_blank', 'data-pjax' => 0, 'onclick' => 'event.stopPropagation()',
                            ]) ?>
                        </span>
                    <?php endif; ?>
                    <span class="lv-cat"><?= Html::encode($entry['category']) ?></span>
                    <div class="lv-msg"><?= Html::encode($entry['messageShort']) ?></div>
                </div>
                <div class="lv-body">
                    <div class="lv-actions">
                        <button type="button" class="btn btn-sm btn-outline-secondary lv-copy" data-copy="message">Copy message</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary lv-copy" data-copy="raw">Copy raw</button>
                        <?= Html::a('Raw', ['raw', 'slug' => $log->getSlug(), 'stamp' => $log->stamp, 'offset' => $entry['offset']], [
                            'class' => 'btn btn-sm btn-outline-secondary', 'target' => '_blank', 'data-pjax' => 0,
                        ]) ?>
                        <?php if ($userHref): ?>
                            <?= Html::a('User #' . Html::encode($uid), $userHref, [
                                'class' => 'btn btn-sm btn-outline-primary', 'target' => '_blank', 'data-pjax' => 0,
                            ]) ?>
                        <?php endif; ?>
                    </div>
                    <dl class="lv-grid">
                        <dt>Exception</dt><dd><?= Html::encode($entry['exceptionClass'] ?: '—') ?></dd>
                        <dt>Session</dt><dd><code><?= Html::encode($entry['sessionId'] ?: '—') ?></code></dd>
                        <dt>Host</dt><dd><?= Html::encode($entry['httpHost'] ?: '—') ?></dd>
                        <dt>Referer</dt><dd><?= Html::encode($entry['referer'] ?: '—') ?></dd>
                        <dt>Offset</dt><dd><code><?= (int)$entry['offset'] ?></code></dd>
                    </dl>
                    <div class="lv-pre-title">Message</div>
                    <pre class="lv-pre lv-message"><?= Html::encode($entry['message']) ?></pre>
                    <?php if (!empty($entry['stack'])): ?>
                        <div class="lv-pre-title">Stack trace</div>
                        <pre class="lv-pre"><?= Html::encode($entry['stack']) ?></pre>
                    <?php endif; ?>
                    <?php if ($entry['get'] !== null): ?>
                        <div class="lv-pre-title">$_GET</div>
                        <pre class="lv-pre"><?= Html::encode($entry['get']) ?></pre>
                    <?php endif; ?>
                    <?php if ($entry['post'] !== null): ?>
                        <div class="lv-pre-title">$_POST</div>
                        <pre class="lv-pre"><?= Html::encode($entry['post']) ?></pre>
                    <?php endif; ?>
                    <?php if ($entry['files'] !== null && $entry['files'] !== '[]'): ?>
                        <div class="lv-pre-title">$_FILES</div>
                        <pre class="lv-pre"><?= Html::encode($entry['files']) ?></pre>
                    <?php endif; ?>
                    <textarea class="d-none lv-raw"><?= Html::encode($entry['raw']) ?></textarea>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="lv-load">
        <?php if ($nextBefore !== null): ?>
            <button type="button" class="btn btn-outline-primary" id="lv-more"
                    data-before="<?= (int)$nextBefore ?>"
                    data-url="<?= Html::encode(Url::to(array_filter($indexParams(['format' => 'json']), static fn ($v) => $v !== null && $v !== ''))) ?>">
                Load older…
            </button>
        <?php elseif ($meta['exists']): ?>
            <span class="text-muted">End of file (or scan limit reached).</span>
        <?php endif; ?>
    </div>
</div>

<?php
$usersJson = Json::htmlEncode($users);
$rawUrlBase = Json::htmlEncode(Url::to(['raw', 'slug' => $log->getSlug(), 'stamp' => $log->stamp, 'offset' => 0]));
$userUrlMap = [];
foreach ($users as $id => $_) {
    $u = $context->userUrl((string)$id);
    if ($u) {
        $userUrlMap[(string)$id] = $u;
    }
}
$userUrlMapJson = Json::htmlEncode($userUrlMap);

$this->registerJs(<<<JS
(function () {
    var users = {$usersJson};
    var userUrls = {$userUrlMapJson};
    var rawUrlBase = {$rawUrlBase};

    function esc(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function (c) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c];
        });
    }
    function userLabel(userId) {
        if (!userId) return 'guest';
        if (users[userId]) return '#' + userId + ' ' + users[userId].name;
        return '#' + userId;
    }
    function userHref(userId) { return userUrls[userId] || null; }
    function rawUrl(offset) {
        return rawUrlBase.replace(/offset=0/, 'offset=' + encodeURIComponent(offset));
    }
    function levelClass(level) {
        level = (level || '').toLowerCase();
        if (level === 'error') return 'lv-level-error';
        if (level === 'warning') return 'lv-level-warning';
        if (level === 'info') return 'lv-level-info';
        return 'lv-level-other';
    }
    function renderEntry(e) {
        var uid = e.userId || e.sessionUserId || null;
        var lvl = (e.level || '').toLowerCase();
        var href = uid ? userHref(String(uid)) : null;
        var uri = '';
        if (e.requestUri) {
            uri = '<span class="lv-uri">' +
                (e.requestMethod ? '<span class="lv-method lv-method-' + esc(e.requestMethod) + '">' + esc(e.requestMethod) + '</span>' : '') +
                '<a href="' + esc(e.requestUri) + '" target="_blank" data-pjax="0" onclick="event.stopPropagation()">' +
                esc(e.requestUri) + '</a></span>';
        }
        var userHtml = href
            ? '<a href="' + esc(href) + '" target="_blank" data-pjax="0" onclick="event.stopPropagation()">' + esc(userLabel(uid)) + '</a>'
            : '<span>' + esc(userLabel(uid)) + '</span>';
        var userBtn = href
            ? '<a class="btn btn-sm btn-outline-primary" target="_blank" data-pjax="0" href="' + esc(href) + '">User #' + esc(uid) + '</a>'
            : '';
        var http = e.httpStatus ? '<span class="badge badge-light">HTTP ' + esc(e.httpStatus) + '</span>' : '';
        var stack = e.stack ? '<div class="lv-pre-title">Stack trace</div><pre class="lv-pre">' + esc(e.stack) + '</pre>' : '';
        var getB = e.get != null ? '<div class="lv-pre-title">\$_GET</div><pre class="lv-pre">' + esc(e.get) + '</pre>' : '';
        var postB = e.post != null ? '<div class="lv-pre-title">\$_POST</div><pre class="lv-pre">' + esc(e.post) + '</pre>' : '';
        var filesB = (e.files && e.files !== '[]') ? '<div class="lv-pre-title">\$_FILES</div><pre class="lv-pre">' + esc(e.files) + '</pre>' : '';

        return '<div class="lv-entry ' + levelClass(e.level) + '" data-offset="' + esc(e.offset) + '">' +
            '<div class="lv-head" onclick="this.parentElement.classList.toggle(\'open\')">' +
            '<span class="lv-time">' + esc(e.datetime) + '</span>' +
            '<span class="lv-badge lv-badge-' + esc(lvl) + '">' + esc(e.level) + '</span>' + http +
            userHtml +
            (e.ip ? '<span class="text-muted">' + esc(e.ip) + '</span>' : '') + uri +
            '<span class="lv-cat">' + esc(e.category) + '</span>' +
            '<div class="lv-msg">' + esc(e.messageShort) + '</div></div>' +
            '<div class="lv-body"><div class="lv-actions">' +
            '<button type="button" class="btn btn-sm btn-outline-secondary lv-copy" data-copy="message">Copy message</button>' +
            '<button type="button" class="btn btn-sm btn-outline-secondary lv-copy" data-copy="raw">Copy raw</button>' +
            '<a class="btn btn-sm btn-outline-secondary" target="_blank" data-pjax="0" href="' + esc(rawUrl(e.offset)) + '">Raw</a>' +
            userBtn + '</div>' +
            '<dl class="lv-grid"><dt>Exception</dt><dd>' + esc(e.exceptionClass || '—') + '</dd>' +
            '<dt>Session</dt><dd><code>' + esc(e.sessionId || '—') + '</code></dd>' +
            '<dt>Host</dt><dd>' + esc(e.httpHost || '—') + '</dd>' +
            '<dt>Referer</dt><dd>' + esc(e.referer || '—') + '</dd>' +
            '<dt>Offset</dt><dd><code>' + esc(e.offset) + '</code></dd></dl>' +
            '<div class="lv-pre-title">Message</div><pre class="lv-pre lv-message">' + esc(e.message) + '</pre>' +
            stack + getB + postB + filesB +
            '<textarea class="d-none lv-raw">' + esc(e.raw) + '</textarea></div></div>';
    }

    document.getElementById('lv-list').addEventListener('click', function (ev) {
        var btn = ev.target.closest('.lv-copy');
        if (!btn) return;
        ev.preventDefault();
        ev.stopPropagation();
        var entry = btn.closest('.lv-entry');
        var text = btn.getAttribute('data-copy') === 'raw'
            ? entry.querySelector('.lv-raw').value
            : entry.querySelector('.lv-message').textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text);
        }
        btn.textContent = 'Copied';
        setTimeout(function () {
            btn.textContent = btn.getAttribute('data-copy') === 'raw' ? 'Copy raw' : 'Copy message';
        }, 1200);
    });

    var moreBtn = document.getElementById('lv-more');
    if (moreBtn) {
        moreBtn.addEventListener('click', function () {
            var before = moreBtn.getAttribute('data-before');
            var url = moreBtn.getAttribute('data-url') + '&before=' + encodeURIComponent(before);
            moreBtn.disabled = true;
            moreBtn.textContent = 'Loading…';
            fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    Object.assign(users, data.users || {});
                    (data.entries || []).forEach(function (e) {
                        document.getElementById('lv-list').insertAdjacentHTML('beforeend', renderEntry(e));
                    });
                    if (data.nextBefore) {
                        moreBtn.setAttribute('data-before', data.nextBefore);
                        moreBtn.disabled = false;
                        moreBtn.textContent = 'Load older…';
                    } else {
                        moreBtn.parentElement.innerHTML = '<span class="text-muted">End of file (or scan limit reached).</span>';
                    }
                })
                .catch(function () {
                    moreBtn.disabled = false;
                    moreBtn.textContent = 'Retry load older…';
                });
        });
    }
})();
JS
);
?>
